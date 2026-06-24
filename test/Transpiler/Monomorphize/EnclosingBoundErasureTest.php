<?php

declare(strict_types=1);

namespace XPHP\Transpiler\Monomorphize;

use PhpParser\Node;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\NodeFinder;
use PhpParser\ParserFactory;
use PHPUnit\Framework\TestCase;

/**
 * A method generic param bounded by an enclosing class param is *erasable* (lower it to its bound
 * `E`, specialized per class instantiation) only when the param appears solely as a top-level input.
 */
final class EnclosingBoundErasureTest extends TestCase
{
    private const SOURCE = <<<'PHP'
    <?php
    declare(strict_types=1);
    namespace App;
    class Box<+E> {
        public function contains<U : E>(U $value): bool { return true; }
        public function probe<U : E>(U $value): bool { return $this->contains::<U>($value); }
        public function pair<U : E, V : E>(U $a, V $b): bool { return true; }
        public function addAll<U : E>(Box<U> $items): void {}
        public function echoBack<U : E>(U $x): U { return $x; }
        public function inspect<U : E>(U $x): bool { return $x instanceof U; }
        public function mixedUse<U : E>(U $a, Box<U> $b): bool { return true; }
        public function plain<T>(T $x): T { return $x; }
        public function viaStringable<U : \Stringable>(U $x): bool { return true; }
        public function compound<U : \Stringable & E>(U $x): bool { return true; }
        public function noInput<U : E>(): bool { return true; }
        public function nullableInput<U : E>(?U $x): bool { return true; }
        public function unionInput<U : E>(U|Fruit $x): bool { return true; }
        public function construct<U : E>(U $x): bool { return (new Box::<U>()) instanceof Box; }
        public function nullableReturn<U : E>(U $x): ?U { return $x; }
        public function unionSecond<U : E>(U $a, U|Fruit $b): bool { return true; }
        public function returnsConcrete<U : E>(U $x): Fruit { return new Fruit(); }
        public function pairMixed<U : E, V : E>(U $a, Box<V> $b): bool { return true; }
        public function unionConcrete<U : E>(U $a, Fruit|Banana $b): bool { return true; }
    }
    PHP;

    private function erasable(string $methodName): bool
    {
        $parser = new XphpSourceParser((new ParserFactory())->createForHostVersion());
        $ast = $parser->parse(self::SOURCE);

        $finder = new NodeFinder();
        $class = $finder->findFirst($ast, static fn (Node $n): bool => $n instanceof Class_);
        self::assertInstanceOf(Class_::class, $class);
        $method = $finder->findFirst([$class], static fn (Node $n): bool => $n instanceof ClassMethod && $n->name->toString() === $methodName);
        self::assertInstanceOf(ClassMethod::class, $method);

        /** @var list<TypeParam> $classParams */
        $classParams = $class->getAttribute(XphpSourceParser::ATTR_GENERIC_PARAMS) ?? [];
        /** @var list<TypeParam> $methodParams */
        $methodParams = $method->getAttribute(XphpSourceParser::ATTR_METHOD_GENERIC_PARAMS) ?? [];
        $classParamNames = array_map(static fn (TypeParam $p): string => $p->name, $classParams);

        return EnclosingBoundErasure::isErasable($method, $methodParams, $classParamNames);
    }

    public function testBareInputParamIsErasable(): void
    {
        self::assertTrue($this->erasable('contains'));
    }

    public function testForwardedTurbofishStaysErasable(): void
    {
        // `$this->contains::<U>()` forwards U in an attribute the AST Name walk never sees.
        self::assertTrue($this->erasable('probe'));
    }

    public function testMultipleBareInputParamsAreErasable(): void
    {
        self::assertTrue($this->erasable('pair'));
    }

    public function testNestedParamIsNotErasable(): void
    {
        // `Box<U> $items` — U is observable nested in the arg, so erasing it would change behaviour.
        self::assertFalse($this->erasable('addAll'));
    }

    public function testReturnPositionIsNotErasable(): void
    {
        self::assertFalse($this->erasable('echoBack'));
    }

    public function testStructuralBodyUseIsNotErasable(): void
    {
        // `$x instanceof U` observes the concrete U.
        self::assertFalse($this->erasable('inspect'));
    }

    public function testMixedTopLevelAndNestedIsNotErasable(): void
    {
        self::assertFalse($this->erasable('mixedUse'));
    }

    public function testUnboundedMethodGenericIsNotErasable(): void
    {
        // No enclosing-parameter bound → not an `<U : E>` method → stays per-U.
        self::assertFalse($this->erasable('plain'));
    }

    public function testBoundOnARealInterfaceIsNotErasable(): void
    {
        // `<U : \Stringable>` — the bound is a real interface, not an enclosing class parameter.
        self::assertFalse($this->erasable('viaStringable'));
    }

    public function testCompoundBoundIsNotErasable(): void
    {
        // `<U : \Stringable & E>` — erasing U to E would drop the \Stringable half; keep per-U.
        self::assertFalse($this->erasable('compound'));
    }

    public function testBoundedParamUnusedAsInputIsNotErasable(): void
    {
        // U is declared and enclosing-bounded but never a direct input — not the erasable shape.
        self::assertFalse($this->erasable('noInput'));
    }

    public function testNullableInputIsNotErasable(): void
    {
        // `?U $x` is not a bare top-level slot — conservatively not erasable.
        self::assertFalse($this->erasable('nullableInput'));
    }

    public function testUnionInputIsNotErasable(): void
    {
        // `U|Fruit $x` mentions U inside a union — not a bare top-level slot.
        self::assertFalse($this->erasable('unionInput'));
    }

    public function testNestedConstructionInBodyIsNotErasable(): void
    {
        // `new Box::<U>()` in the body makes the concrete U observable.
        self::assertFalse($this->erasable('construct'));
    }

    public function testBoundedInNullableReturnIsNotErasable(): void
    {
        // U is a bare input but also appears in the nullable return `?U` — observable, so not erasable.
        self::assertFalse($this->erasable('nullableReturn'));
    }

    public function testBoundedInAUnionParamIsNotErasable(): void
    {
        // First param is a bare input, but a second param `U|Fruit` mentions U in a union.
        self::assertFalse($this->erasable('unionSecond'));
    }

    public function testBareInputWithConcreteReturnIsErasable(): void
    {
        // U only as a bare input; a concrete (non-U) return type doesn't block erasure.
        self::assertTrue($this->erasable('returnsConcrete'));
    }

    public function testSecondBoundedParamNestedIsNotErasable(): void
    {
        // U is a bare input but a second bounded param V appears nested in `Box<V>`.
        self::assertFalse($this->erasable('pairMixed'));
    }

    public function testConcreteUnionSecondParamStaysErasable(): void
    {
        // A second param `Fruit|Banana` (no bounded member) doesn't block erasure of a bare-input U.
        self::assertTrue($this->erasable('unionConcrete'));
    }

    public function testRefTreeHasBoundedDirectly(): void
    {
        self::assertTrue(EnclosingBoundErasure::refTreeHasBounded(new TypeRef('U', isTypeParam: true), ['U']));
        self::assertTrue(EnclosingBoundErasure::refTreeHasBounded(
            new TypeRef('Box', [new TypeRef('U', isTypeParam: true)]),
            ['U'],
        ));
        // A real class named like a bound, but not a type parameter, doesn't match.
        self::assertFalse(EnclosingBoundErasure::refTreeHasBounded(new TypeRef('U'), ['U']));
        // A type parameter that isn't in the bounded set doesn't match.
        self::assertFalse(EnclosingBoundErasure::refTreeHasBounded(new TypeRef('K', isTypeParam: true), ['U']));
    }
}
