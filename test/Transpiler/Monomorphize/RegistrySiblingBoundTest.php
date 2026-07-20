<?php

declare(strict_types=1);

namespace XPHP\Transpiler\Monomorphize;

use PhpParser\Node\Identifier;
use PhpParser\Node\Stmt\Class_;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Class-level bounds that reference a *sibling* type parameter (`class Pair<T, U : T>`) must be
 * grounded against the supplied arg before checking — otherwise `U`'s bound is the literal `T`,
 * which is no class, so `isSubtype("Banana","T")` is false and a valid `Pair<Fruit, Banana>` is
 * wrongly rejected. Banana <: Fruit throughout.
 */
final class RegistrySiblingBoundTest extends TestCase
{
    /** Hierarchy: Banana <: Fruit (and Stringable); Cherry <: Fruit (not Stringable); Money <: Comparable; Apple unrelated. */
    private static function hierarchy(): TypeHierarchy
    {
        return new TypeHierarchy([
            'App\\Banana' => ['App\\Fruit', 'Stringable'],
            'App\\Cherry' => ['App\\Fruit'],
            'App\\Fruit' => [],
            'App\\Apple' => [],
            'App\\Comparable' => [],
            'App\\Money' => ['App\\Comparable'],
        ]);
    }

    /** @param list<TypeParam> $params */
    private static function registryFor(array $params): Registry
    {
        $registry = new Registry(hierarchy: self::hierarchy());
        $registry->recordDefinition('App\\Pair', 'Pair', $params, new Class_(new Identifier('Pair')), '/Pair.xphp');

        return $registry;
    }

    /**
     * `class Pair<T, U : T>`
     *
     * @return list<TypeParam>
     */
    private static function pairTU(): array
    {
        return [
            new TypeParam('T'),
            new TypeParam('U', new BoundLeaf(new TypeRef('T', isTypeParam: true))),
        ];
    }

    public function testSiblingBoundAcceptsWhenArgSatisfiesTheSiblingArg(): void
    {
        $registry = self::registryFor(self::pairTU());

        // U = Banana must satisfy T = Fruit; Banana <: Fruit, so this is accepted (no throw).
        $inst = $registry->recordInstantiation('App\\Pair', [new TypeRef('App\\Fruit'), new TypeRef('App\\Banana')]);

        self::assertSame('App\\Pair', $inst->templateFqn);
    }

    public function testSiblingBoundRejectsWhenArgViolatesTheSiblingArg(): void
    {
        $registry = self::registryFor(self::pairTU());

        // U = Fruit must satisfy T = Banana; Fruit is not a subtype of Banana → reject, and the
        // message must show the GROUNDED bound (Banana), not the literal `T`.
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Generic bound violated');
        $this->expectExceptionMessage('extend/implement "App\\Banana"');
        $registry->recordInstantiation('App\\Pair', [new TypeRef('App\\Banana'), new TypeRef('App\\Fruit')]);
    }

    public function testDeclTimeDefaultCheckDefersASiblingParamBound(): void
    {
        // `class Pair<T, U : T = Banana>`: the default (Banana) is concrete but the bound references
        // a sibling (T), so it can't be validated at declaration time — it must defer (not throw).
        $registry = self::registryFor([
            new TypeParam('T'),
            new TypeParam('U', new BoundLeaf(new TypeRef('T', isTypeParam: true)), new TypeRef('App\\Banana')),
        ]);

        $registry->validateDefaultsAgainstBounds(); // must not throw (deferred to instantiation)

        $this->addToAssertionCount(1);
    }

    public function testDeclTimeDefaultCheckContinuesPastADeferredSiblingBound(): void
    {
        // A deferred sibling-bound param (B : A) must only SKIP itself, not stop the loop — a later
        // param with a concrete bound whose default violates it (C : Fruit = Apple) must still be
        // caught at declaration time.
        $registry = self::registryFor([
            new TypeParam('A'),
            new TypeParam('B', new BoundLeaf(new TypeRef('A', isTypeParam: true)), new TypeRef('App\\Banana')),
            new TypeParam('C', new BoundLeaf(new TypeRef('App\\Fruit')), new TypeRef('App\\Apple')),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('App\\Fruit');
        $registry->validateDefaultsAgainstBounds();
    }

    public function testDefaultedSiblingBoundIsCheckedAtInstantiation(): void
    {
        // Same class; instantiating `Pair<Fruit>` pads U to its default Banana → Banana must satisfy
        // T = Fruit → Banana <: Fruit → accepted.
        $registry = self::registryFor([
            new TypeParam('T'),
            new TypeParam('U', new BoundLeaf(new TypeRef('T', isTypeParam: true)), new TypeRef('App\\Banana')),
        ]);

        $inst = $registry->recordInstantiation('App\\Pair', [new TypeRef('App\\Fruit')]);

        self::assertSame('App\\Pair', $inst->templateFqn);
    }

    public function testDefaultedSiblingBoundRejectsAtInstantiationWhenTheDefaultViolates(): void
    {
        // `Pair<Apple>` pads U to its default Banana → Banana must satisfy T = Apple → Banana is not
        // a subtype of Apple → rejected at instantiation (the deferred check fires here).
        $registry = self::registryFor([
            new TypeParam('T'),
            new TypeParam('U', new BoundLeaf(new TypeRef('T', isTypeParam: true)), new TypeRef('App\\Banana')),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('extend/implement "App\\Apple"');
        $registry->recordInstantiation('App\\Pair', [new TypeRef('App\\Apple')]);
    }

    public function testBoundReferencingALaterSiblingIsGrounded(): void
    {
        // `class Rev<T : U, U>` — T's bound references a LATER param. The subst map is built over all
        // params first, so it grounds regardless of order. Banana <: Fruit → accepted.
        $registry = self::registryFor([
            new TypeParam('T', new BoundLeaf(new TypeRef('U', isTypeParam: true))),
            new TypeParam('U'),
        ]);

        $inst = $registry->recordInstantiation('App\\Pair', [new TypeRef('App\\Banana'), new TypeRef('App\\Fruit')]);

        self::assertSame('App\\Pair', $inst->templateFqn);
    }

    public function testIntersectionSiblingBoundGroundsTheSiblingOperand(): void
    {
        // `class Box2<T, U : T & \Stringable>` — the sibling leaf lives inside an intersection.
        // U = Cherry must satisfy (T = Banana) AND Stringable; Cherry is not a subtype of Banana →
        // reject, with the grounded sibling operand (Banana) shown.
        $registry = self::registryFor([
            new TypeParam('T'),
            new TypeParam('U', new BoundIntersection(
                new BoundLeaf(new TypeRef('T', isTypeParam: true)),
                new BoundLeaf(new TypeRef('Stringable')),
            )),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('App\\Banana');
        $registry->recordInstantiation('App\\Pair', [new TypeRef('App\\Banana'), new TypeRef('App\\Cherry')]);
    }

    public function testFBoundIsPreservedWhenGroundedAtInstantiation(): void
    {
        // `class Sortable<T : Comparable<T>>` — grounding rewrites the inner arg `T`→Money, but the
        // leaf name stays `Comparable` and is checked erased. Money <: Comparable → accepted.
        $registry = self::registryFor([
            new TypeParam('T', new BoundLeaf(new TypeRef('App\\Comparable', [new TypeRef('T', isTypeParam: true)]))),
        ]);

        $inst = $registry->recordInstantiation('App\\Pair', [new TypeRef('App\\Money')]);

        self::assertSame('App\\Pair', $inst->templateFqn);
    }
}
