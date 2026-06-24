<?php

declare(strict_types=1);

namespace XPHP\Tests\Transpiler\Monomorphize;

use PhpParser\Node\Stmt\Class_;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use XPHP\Transpiler\Monomorphize\Registry;
use XPHP\Transpiler\Monomorphize\TypeHierarchy;
use XPHP\Transpiler\Monomorphize\TypeParam;
use XPHP\Transpiler\Monomorphize\TypeRef;
use XPHP\Transpiler\Monomorphize\Variance;
use XPHP\Transpiler\Monomorphize\VarianceSubtyping;

/**
 * Direct, in-process coverage of the variance-subtype decision shared by the variance edge emitter
 * and the specialization closer. The end-to-end behaviour is also proven by the runtime fixtures, but
 * those run in separate processes (where mutation testing can't attribute kills), so these in-process
 * accept/reject pairs pin each decision branch.
 */
#[CoversClass(VarianceSubtyping::class)]
final class VarianceSubtypingTest extends TestCase
{
    private const FRUIT = 'App\\Fruit';
    private const BANANA = 'App\\Banana';
    private const BOX = 'App\\Box';

    /** Banana <: Fruit; Box<+T> defined so the nested-generic recursion has a template to read. */
    private function subtyping(): VarianceSubtyping
    {
        return new VarianceSubtyping($this->hierarchy());
    }

    private function hierarchy(): TypeHierarchy
    {
        return new TypeHierarchy([self::BANANA => [self::FRUIT]]);
    }

    private function registry(): Registry
    {
        $registry = new Registry(Registry::DEFAULT_HASH_HEX_LENGTH, $this->hierarchy());
        $registry->recordDefinition(
            self::BOX,
            'Box',
            [new TypeParam('T', variance: Variance::Covariant)],
            new Class_('Box'),
            'test',
        );
        return $registry;
    }

    private static function covariant(): array
    {
        return [new TypeParam('T', variance: Variance::Covariant)];
    }

    public function testCovariantStrictSubtypeIsASubtype(): void
    {
        // Banana <: Fruit, covariant T: Producer<Banana> <: Producer<Fruit>. Kills the `continue`
        // mutant — without it the covariant pass would fall through to the contravariant check
        // (Fruit <: Banana = false) and wrongly reject.
        self::assertTrue($this->subtyping()->isVarianceSubtype(
            [new TypeRef(self::BANANA)],
            [new TypeRef(self::FRUIT)],
            self::covariant(),
            $this->registry(),
        ));
    }

    public function testCovariantSupertypeToSubtypeIsNotASubtype(): void
    {
        // The reverse direction: Producer<Fruit> is NOT <: Producer<Fruit-narrowed>. Fruit is not a
        // subtype of Banana, so no edge.
        self::assertFalse($this->subtyping()->isVarianceSubtype(
            [new TypeRef(self::FRUIT)],
            [new TypeRef(self::BANANA)],
            self::covariant(),
            $this->registry(),
        ));
    }

    public function testContravariantReversesTheDirection(): void
    {
        // Contravariant T: Consumer<Fruit> <: Consumer<Banana> (a Fruit-consumer can stand in for a
        // Banana-consumer). Kills the contravariant-branch direction.
        self::assertTrue($this->subtyping()->isVarianceSubtype(
            [new TypeRef(self::FRUIT)],
            [new TypeRef(self::BANANA)],
            [new TypeParam('T', variance: Variance::Contravariant)],
            $this->registry(),
        ));
    }

    public function testIdenticalArgsAreNotAProperSubtype(): void
    {
        // Reflexive pair: not a proper subtype edge. Kills the `$sawNonIdentity = false` init mutant —
        // initialised true, identical args would be reported as a subtype.
        self::assertFalse($this->subtyping()->isVarianceSubtype(
            [new TypeRef(self::FRUIT)],
            [new TypeRef(self::FRUIT)],
            self::covariant(),
            $this->registry(),
        ));
    }

    public function testCovariantRejectsWhenALaterArgIsNotASubtype(): void
    {
        // Two covariant params where the FIRST agrees but the SECOND does not: Fruit is not a subtype
        // of Banana. The loop must keep checking after the first arg — kills the `continue` → `break`
        // mutant, which would stop at arg 0 and wrongly accept on the (true) sawNonIdentity flag.
        self::assertFalse($this->subtyping()->isVarianceSubtype(
            [new TypeRef(self::BANANA), new TypeRef(self::FRUIT)],
            [new TypeRef(self::FRUIT), new TypeRef(self::BANANA)],
            [
                new TypeParam('T', variance: Variance::Covariant),
                new TypeParam('U', variance: Variance::Covariant),
            ],
            $this->registry(),
        ));
    }

    public function testInvariantParamRequiresEqualArgs(): void
    {
        // Invariant T: Cell<Banana> is NOT <: Cell<Fruit> even though Banana <: Fruit.
        self::assertFalse($this->subtyping()->isVarianceSubtype(
            [new TypeRef(self::BANANA)],
            [new TypeRef(self::FRUIT)],
            [new TypeParam('T', variance: Variance::Invariant)],
            $this->registry(),
        ));
    }

    public function testArityMismatchBetweenArgsAndParamsIsNotASubtype(): void
    {
        // count(args) != count(params): the arity guard must reject. Kills the `||` → `&&` mutant,
        // which would skip the guard and walk past the params array.
        self::assertFalse($this->subtyping()->isVarianceSubtype(
            [new TypeRef(self::BANANA)],
            [new TypeRef(self::FRUIT)],
            [],
            $this->registry(),
        ));
    }

    public function testMismatchedArgCountsAreNotASubtype(): void
    {
        // count(args1) != count(args2): the other half of the arity guard.
        self::assertFalse($this->subtyping()->isVarianceSubtype(
            [new TypeRef(self::BANANA)],
            [new TypeRef(self::BANANA), new TypeRef(self::FRUIT)],
            self::covariant(),
            $this->registry(),
        ));
    }

    public function testNestedSameTemplateGenericRecursesThroughInnerVariance(): void
    {
        // Producer<Box<Banana>> <: Producer<Box<Fruit>> because Box has covariant T. Exercises the
        // nested-generic branch (both generic, same template) and its recursion into Box's variance —
        // kills the `&&` mutants in the same-template guard.
        self::assertTrue($this->subtyping()->isVarianceSubtype(
            [new TypeRef(self::BOX, [new TypeRef(self::BANANA)])],
            [new TypeRef(self::BOX, [new TypeRef(self::FRUIT)])],
            self::covariant(),
            $this->registry(),
        ));
    }

    public function testNestedSameTemplateGenericRejectsWhenInnerArgsAreNotSubtype(): void
    {
        // Producer<Box<Fruit>> is NOT <: Producer<Box<Banana>> — the inner recursion (Fruit <: Banana
        // = false) rejects, which is the whole point of recursing instead of flattening to
        // isSubtype('Box','Box').
        self::assertFalse($this->subtyping()->isVarianceSubtype(
            [new TypeRef(self::BOX, [new TypeRef(self::FRUIT)])],
            [new TypeRef(self::BOX, [new TypeRef(self::BANANA)])],
            self::covariant(),
            $this->registry(),
        ));
    }

    public function testDifferentInnerTemplatesAreNotSubtype(): void
    {
        // A nested generic of a template with no recorded definition can't be proven a subtype — the
        // conservative false (empty inner params) path.
        self::assertFalse($this->subtyping()->isVarianceSubtype(
            [new TypeRef('App\\Other', [new TypeRef(self::BANANA)])],
            [new TypeRef('App\\Other', [new TypeRef(self::FRUIT)])],
            self::covariant(),
            $this->registry(),
        ));
    }
}
