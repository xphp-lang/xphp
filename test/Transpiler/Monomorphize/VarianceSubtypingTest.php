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

    // ---- Cross-template generic type-argument subtyping ----
    //
    // `ImmutableList<+E> implements Collection<+E>`, `Book <: Product`. The hierarchy carries the
    // PARAMETERISED supertype edge so `resolveInheritedArgs` can thread `ImmutableList<Book>` up to
    // `Collection<Book>`. `Mid<X> implements Collection<X, X>` is the MALFORMED case — a 2-arg
    // parameterised super against a 1-param target — exercising the load-bearing count() arity guard.

    private const COLLECTION = 'App\\Collection';
    private const IMMUTABLE_LIST = 'App\\ImmutableList';
    private const BOOK = 'App\\Book';
    private const PRODUCT = 'App\\Product';

    private function crossHierarchy(): TypeHierarchy
    {
        return new TypeHierarchy(
            ancestors: [
                self::IMMUTABLE_LIST => [self::COLLECTION],
                self::BOOK => [self::PRODUCT],
                'App\\Mid' => [self::COLLECTION],
                'App\\Foo' => [],
                'App\\Bar' => [],
            ],
            superTypeArgs: [
                self::IMMUTABLE_LIST => [new TypeRef(self::COLLECTION, [new TypeRef('E', isTypeParam: true)])],
                // Malformed: declares two args for a one-param Collection.
                'App\\Mid' => [new TypeRef(self::COLLECTION, [
                    new TypeRef('X', isTypeParam: true),
                    new TypeRef('X', isTypeParam: true),
                ])],
            ],
            typeParamNames: [
                self::IMMUTABLE_LIST => ['E'],
                self::COLLECTION => ['E'],
                'App\\Mid' => ['X'],
            ],
        );
    }

    private function crossRegistry(): Registry
    {
        $hierarchy = $this->crossHierarchy();
        $registry = new Registry(Registry::DEFAULT_HASH_HEX_LENGTH, $hierarchy);
        // Only the PARENT template's definition is read (for its slot variance); Collection<+E>.
        $registry->recordDefinition(
            self::COLLECTION,
            'Collection',
            [new TypeParam('E', variance: Variance::Covariant)],
            new Class_('Collection'),
            'test',
        );
        return $registry;
    }

    private function crossSubtyping(): VarianceSubtyping
    {
        return new VarianceSubtyping($this->crossHierarchy());
    }

    public function testCrossTemplateGenericArgRelatesThroughThreadedSupertype(): void
    {
        // The headline: a covariant outer slot holding `ImmutableList<Book>` vs `Collection<Product>`.
        // isNestedSubtype's different-template branch threads ImmutableList<Book> → Collection<Book>,
        // then compares Book ⊑ Product under Collection's covariant E → true. Kills the new branch's
        // `isSubtype(...) === true` guard (a mutant dropping it would mis-handle this) and proves the
        // threading produces the right arity.
        self::assertTrue($this->crossSubtyping()->isVarianceSubtype(
            [new TypeRef(self::IMMUTABLE_LIST, [new TypeRef(self::BOOK)])],
            [new TypeRef(self::COLLECTION, [new TypeRef(self::PRODUCT)])],
            self::covariant(),
            $this->crossRegistry(),
        ));
    }

    public function testCrossTemplateUnrelatedTemplatesAreNotSubtype(): void
    {
        // Foo ⋢ Bar (no hierarchy edge): isSubtype short-circuits → no edge. A wrong "yes" here would
        // emit a bogus `implements` → autoload fatal, so this is the expensive direction to keep false.
        self::assertFalse($this->crossSubtyping()->isVarianceSubtype(
            [new TypeRef('App\\Foo', [new TypeRef(self::BOOK)])],
            [new TypeRef('App\\Bar', [new TypeRef(self::PRODUCT)])],
            self::covariant(),
            $this->crossRegistry(),
        ));
    }

    public function testCrossTemplateReverseDirectionIsNotSubtype(): void
    {
        // Operand order: the SUPER template in the subtype slot. Collection ⋢ ImmutableList, so
        // isSubtype(Collection, ImmutableList) is false → no edge. Kills an operand-swap mutant on the
        // `isSubtype($childName, $parentName)` call.
        self::assertFalse($this->crossSubtyping()->isVarianceSubtype(
            [new TypeRef(self::COLLECTION, [new TypeRef(self::BOOK)])],
            [new TypeRef(self::IMMUTABLE_LIST, [new TypeRef(self::PRODUCT)])],
            self::covariant(),
            $this->crossRegistry(),
        ));
    }

    public function testContravariantSlotThreadsCrossTemplateArgInTheFlippedDirection(): void
    {
        // A contravariant outer slot flips the operands (isNestedSubtype($a2, $a1)): a subtype needs the
        // SUPERTYPE-spec's arg to be a subtype of the SUBTYPE-spec's arg. With a cross-template inner
        // pair, the branch must thread `ImmutableList<Book>` (the flipped child, from args2) up to
        // `Collection<Product>` (args1) — proving the cross-template case composes symmetrically across
        // variance, not just for the covariant direction.
        self::assertTrue($this->crossSubtyping()->isVarianceSubtype(
            [new TypeRef(self::COLLECTION, [new TypeRef(self::PRODUCT)])],
            [new TypeRef(self::IMMUTABLE_LIST, [new TypeRef(self::BOOK)])],
            [new TypeParam('X', variance: Variance::Contravariant)],
            $this->crossRegistry(),
        ));
    }

    public function testContravariantSlotRejectsTheWrongCrossTemplateDirection(): void
    {
        // The flipped reject: with the args the other way round a contravariant slot needs
        // `Collection<Product> ⊑ ImmutableList<Book>`, which is false (Collection ⋢ ImmutableList).
        self::assertFalse($this->crossSubtyping()->isVarianceSubtype(
            [new TypeRef(self::IMMUTABLE_LIST, [new TypeRef(self::BOOK)])],
            [new TypeRef(self::COLLECTION, [new TypeRef(self::PRODUCT)])],
            [new TypeParam('X', variance: Variance::Contravariant)],
            $this->crossRegistry(),
        ));
    }

    public function testCrossTemplateMalformedGroundingIsNotSubtype(): void
    {
        // The load-bearing guard: `Mid implements Collection<X, X>` grounds
        // Mid<Book> to a NON-null but wrong-arity tuple [Book, Book] against the one-param Collection.
        // resolveInheritedArgs returns it non-null; only isVarianceSubtype's count() arity guard rejects
        // it. Without that guard a bogus edge would be emitted → autoload fatal.
        self::assertFalse($this->crossSubtyping()->isVarianceSubtype(
            [new TypeRef('App\\Mid', [new TypeRef(self::BOOK)])],
            [new TypeRef(self::COLLECTION, [new TypeRef(self::PRODUCT)])],
            self::covariant(),
            $this->crossRegistry(),
        ));
    }
}
