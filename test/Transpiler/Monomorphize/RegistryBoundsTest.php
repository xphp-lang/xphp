<?php

declare(strict_types=1);

namespace XPHP\Transpiler\Monomorphize;

use PhpParser\Node\Identifier;
use PhpParser\Node\Stmt\Class_;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class RegistryBoundsTest extends TestCase
{
    public function testBoundValidationRejectsScalarConcreteForClassBound(): void
    {
        $hierarchy = new TypeHierarchy([]);
        $registry = new Registry(hierarchy: $hierarchy);

        $registry->recordDefinition(
            'App\\Box',
            'Box',
            [new TypeParam('T', new BoundLeaf(new TypeRef('Stringable')))],
            new Class_(new Identifier('Box')),
            '/Box.xphp',
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Generic bound violated');
        $this->expectExceptionMessage('"int" does not extend/implement "Stringable"');
        $registry->recordInstantiation('App\\Box', [new TypeRef('int', isScalar: true)]);
    }

    public function testBoundValidationAcceptsConcreteThatImplementsBoundViaHierarchy(): void
    {
        $hierarchy = new TypeHierarchy([
            'App\\User' => ['Stringable'],
        ]);
        $registry = new Registry(hierarchy: $hierarchy);

        $registry->recordDefinition(
            'App\\Box',
            'Box',
            [new TypeParam('T', new BoundLeaf(new TypeRef('Stringable')))],
            new Class_(new Identifier('Box')),
            '/Box.xphp',
        );

        $registry->recordInstantiation('App\\Box', [new TypeRef('App\\User')]);
        self::assertCount(1, $registry->instantiations());
    }

    public function testBoundValidationFailsForUnknownConcreteWithClearMessage(): void
    {
        $hierarchy = new TypeHierarchy([]);
        $registry = new Registry(hierarchy: $hierarchy);

        $registry->recordDefinition(
            'App\\Box',
            'Box',
            [new TypeParam('T', new BoundLeaf(new TypeRef('Stringable')))],
            new Class_(new Identifier('Box')),
            '/Box.xphp',
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('not in the source set');
        $registry->recordInstantiation('App\\Box', [new TypeRef('App\\SomeUnknownClass')]);
    }

    public function testNoHierarchyMeansBoundsAreSkippedSilently(): void
    {
        // Lenient mode: bare Registry tests that don't bother with a hierarchy must keep
        // working — bounds become advisory until the compiler hands us a hierarchy.
        $registry = new Registry();

        $registry->recordDefinition(
            'App\\Box',
            'Box',
            [new TypeParam('T', new BoundLeaf(new TypeRef('Stringable')))],
            new Class_(new Identifier('Box')),
            '/Box.xphp',
        );

        $registry->recordInstantiation('App\\Box', [new TypeRef('int', isScalar: true)]);
        self::assertCount(1, $registry->instantiations());
    }

    public function testBoundValidationIsPositionalAcrossMultipleParams(): void
    {
        // Pair<K: Stringable, V> — bound check fires only on K, not V.
        $hierarchy = new TypeHierarchy([
            'App\\Tag' => ['Stringable'],
        ]);
        $registry = new Registry(hierarchy: $hierarchy);

        $registry->recordDefinition(
            'App\\Pair',
            'Pair',
            [new TypeParam('K', new BoundLeaf(new TypeRef('Stringable'))), new TypeParam('V')],
            new Class_(new Identifier('Pair')),
            '/Pair.xphp',
        );

        // V can be anything — even int — because it has no bound.
        $registry->recordInstantiation('App\\Pair', [new TypeRef('App\\Tag'), new TypeRef('int', isScalar: true)]);
        self::assertCount(1, $registry->instantiations());
    }

    public function testBoundOnLaterParamIsStillCheckedWhenEarlierParamIsUnbounded(): void
    {
        // Locks `continue` (vs `break`) on the unbounded-param branch of validateBounds.
        // The first param (K) has no bound — original skips to V's check, which would
        // throw for an int concrete. Under a `break` mutation we'd exit the loop after K
        // and never validate V, masking the violation.
        $hierarchy = new TypeHierarchy([]);
        $registry = new Registry(hierarchy: $hierarchy);

        $registry->recordDefinition(
            'App\\Pair',
            'Pair',
            [new TypeParam('K'), new TypeParam('V', new BoundLeaf(new TypeRef('Stringable')))],
            new Class_(new Identifier('Pair')),
            '/Pair.xphp',
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Generic bound violated');
        $this->expectExceptionMessage('type parameter V');
        $registry->recordInstantiation(
            'App\\Pair',
            [new TypeRef('App\\Whatever'), new TypeRef('int', isScalar: true)],
        );
    }

    public function testSatisfiedBoundOnEarlierParamStillChecksLaterParam(): void
    {
        // Mutation regression: `continue` -> `break` on the verdict-true
        // branch of `Registry::validateBounds` (line 175).
        //
        // Pair<K: Stringable, V: Stringable> -- BOTH params bounded, but
        // only K's concrete type satisfies Stringable.  Original code
        // validates K, sees verdict===true, `continue`s to V.  V's
        // concrete fails -> RuntimeException.
        //
        // Under the `break` mutation, validation exits after K passes
        // and V's violation slips through silently -- the test would
        // see NO exception.  This locks the per-param "keep going"
        // semantic.
        $hierarchy = new TypeHierarchy([
            'App\\Tag' => ['Stringable'],
        ]);
        $registry = new Registry(hierarchy: $hierarchy);

        $registry->recordDefinition(
            'App\\Pair',
            'Pair',
            [new TypeParam('K', new BoundLeaf(new TypeRef('Stringable'))), new TypeParam('V', new BoundLeaf(new TypeRef('Stringable')))],
            new Class_(new Identifier('Pair')),
            '/Pair.xphp',
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Generic bound violated');
        $this->expectExceptionMessage('type parameter V');
        $registry->recordInstantiation(
            'App\\Pair',
            [new TypeRef('App\\Tag'), new TypeRef('int', isScalar: true)],
        );
    }

    public function testUnboundedTypeParamSkipsValidation(): void
    {
        $hierarchy = new TypeHierarchy([]);
        $registry = new Registry(hierarchy: $hierarchy);

        $registry->recordDefinition(
            'App\\Box',
            'Box',
            [new TypeParam('T')],
            new Class_(new Identifier('Box')),
            '/Box.xphp',
        );

        // No bound -> no validation -> unknown concrete passes.
        $registry->recordInstantiation('App\\Box', [new TypeRef('App\\Whatever')]);
        self::assertCount(1, $registry->instantiations());
    }

    public function testRecordInstantiationPadsTrailingDefaultsWhenArgsAreShort(): void
    {
        $registry = new Registry();
        $registry->recordDefinition(
            'App\\Cache',
            'Cache',
            [
                new TypeParam('K', default: new TypeRef('string', isScalar: true)),
                new TypeParam('V', default: new TypeRef('mixed', isScalar: true)),
            ],
            new Class_(new Identifier('Cache')),
            '/Cache.xphp',
        );

        $instantiation = $registry->recordInstantiation('App\\Cache', []);

        self::assertCount(2, $instantiation->concreteTypes);
        self::assertSame('string', $instantiation->concreteTypes[0]->name);
        self::assertSame('mixed', $instantiation->concreteTypes[1]->name);
    }

    public function testEmptyAndFullyExplicitInstantiationsProduceTheSameFqn(): void
    {
        $registry = new Registry();
        $registry->recordDefinition(
            'App\\Cache',
            'Cache',
            [
                new TypeParam('K', default: new TypeRef('string', isScalar: true)),
                new TypeParam('V', default: new TypeRef('mixed', isScalar: true)),
            ],
            new Class_(new Identifier('Cache')),
            '/Cache.xphp',
        );

        $bare = $registry->recordInstantiation('App\\Cache', []);
        $explicit = $registry->recordInstantiation('App\\Cache', [
            new TypeRef('string', isScalar: true),
            new TypeRef('mixed', isScalar: true),
        ]);

        self::assertSame($bare->generatedFqn, $explicit->generatedFqn);
    }

    public function testRecordInstantiationSubstitutesEarlierParamRefsIntoDefault(): void
    {
        $registry = new Registry();
        $registry->recordDefinition(
            'App\\Pair',
            'Pair',
            [
                new TypeParam('A'),
                new TypeParam('B', default: new TypeRef('A', isTypeParam: true)),
            ],
            new Class_(new Identifier('Pair')),
            '/Pair.xphp',
        );

        $instantiation = $registry->recordInstantiation('App\\Pair', [
            new TypeRef('int', isScalar: true),
        ]);

        self::assertCount(2, $instantiation->concreteTypes);
        self::assertSame('int', $instantiation->concreteTypes[1]->name);
        self::assertTrue($instantiation->concreteTypes[1]->isScalar);
    }

    public function testRecordInstantiationThrowsWhenNonDefaultedParamIsMissing(): void
    {
        $registry = new Registry();
        $registry->recordDefinition(
            'App\\Pair',
            'Pair',
            [
                new TypeParam('A'),
                new TypeParam('B', default: new TypeRef('A', isTypeParam: true)),
            ],
            new Class_(new Identifier('Pair')),
            '/Pair.xphp',
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('parameter `A`');
        $this->expectExceptionMessage('(position 1)');
        $this->expectExceptionMessage('has no default');
        $registry->recordInstantiation('App\\Pair', []);
    }

    public function testRecordInstantiationErrorReportsCorrectPositionForMissingSecondParam(): void
    {
        // First param is supplied; second has no default. Position number must
        // read `(position 2)` -- pins the $i + 1 human-readable offset.
        $registry = new Registry();
        $registry->recordDefinition(
            'App\\Triple',
            'Triple',
            [
                new TypeParam('A'),
                new TypeParam('B'),
                new TypeParam('C', default: new TypeRef('int', isScalar: true)),
            ],
            new Class_(new Identifier('Triple')),
            '/Triple.xphp',
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('parameter `B`');
        $this->expectExceptionMessage('(position 2)');
        $registry->recordInstantiation('App\\Triple', [new TypeRef('int', isScalar: true)]);
    }

    public function testValidateDefaultsAgainstBoundsReportsUnknownConcreteWithNullVerdict(): void
    {
        // Bound is a class the hierarchy doesn't know about -> isSubtype returns
        // null -> the verdict is "compiler cannot prove" (not "does not satisfy").
        // Pins the verdict-aware ternary in the error-shape selection.
        $hierarchy = new TypeHierarchy([]);
        $registry = new Registry(hierarchy: $hierarchy);
        $registry->recordDefinition(
            'App\\Box',
            'Box',
            [new TypeParam(
                'T',
                bound: new BoundLeaf(new TypeRef('Vendor\\UnknownIface')),
                default: new TypeRef('Vendor\\AlsoUnknown'),
            )],
            new Class_(new Identifier('Box')),
            '/Box.xphp',
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('compiler cannot prove');
        $registry->validateDefaultsAgainstBounds();
    }

    public function testValidateDefaultsAgainstBoundsContinuesPastSkippedParamToLaterViolator(): void
    {
        // First param skips (bound but no default); second param's default
        // violates its bound. If the `continue` on the skip becomes a `break`,
        // the second param is never checked and the violation slips through.
        $hierarchy = new TypeHierarchy([]);
        $registry = new Registry(hierarchy: $hierarchy);
        $registry->recordDefinition(
            'App\\Box',
            'Box',
            [
                new TypeParam(
                    'A',
                    bound: new BoundLeaf(new TypeRef('Stringable')),
                    // no default -- the first `continue` (bound or default null) skip
                ),
                new TypeParam(
                    'B',
                    bound: new BoundLeaf(new TypeRef('Stringable')),
                    default: new TypeRef('int', isScalar: true),
                ),
            ],
            new Class_(new Identifier('Box')),
            '/Box.xphp',
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Default for generic parameter `B`');
        $registry->validateDefaultsAgainstBounds();
    }

    public function testValidateDefaultsAgainstBoundsContinuesPastTypeParamRefToLaterConcreteViolator(): void
    {
        // First param's default IS a type-param ref (skipped via the !isConcrete
        // branch). Second param's default is fully concrete AND violates its
        // bound. If the !isConcrete `continue` becomes a `break`, the second
        // param's violation isn't surfaced.
        $hierarchy = new TypeHierarchy([]);
        $registry = new Registry(hierarchy: $hierarchy);
        // `class Bad<A : Stringable, B : Stringable = A, C : Stringable = int>`
        // A is required (no default, skipped at line 187 -- bound != null but
        // default == null). B's default is a type-param ref to A (NOT concrete
        // -> skipped at line 190). C's default is `int` (concrete) and violates
        // Stringable. Only line 190's continue chain reaches C.
        $registry->recordDefinition(
            'App\\Bad',
            'Bad',
            [
                new TypeParam('A', bound: new BoundLeaf(new TypeRef('Stringable'))),
                new TypeParam(
                    'B',
                    bound: new BoundLeaf(new TypeRef('Stringable')),
                    default: new TypeRef('A', isTypeParam: true),
                ),
                new TypeParam(
                    'C',
                    bound: new BoundLeaf(new TypeRef('Stringable')),
                    default: new TypeRef('int', isScalar: true),
                ),
            ],
            new Class_(new Identifier('Bad')),
            '/Bad.xphp',
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Default for generic parameter `C`');
        $registry->validateDefaultsAgainstBounds();
    }

    public function testValidateDefaultsAgainstBoundsCatchesConcreteViolation(): void
    {
        $hierarchy = new TypeHierarchy([]);
        $registry = new Registry(hierarchy: $hierarchy);

        $registry->recordDefinition(
            'App\\Box',
            'Box',
            [new TypeParam(
                'T',
                bound: new BoundLeaf(new TypeRef('Stringable')),
                default: new TypeRef('int', isScalar: true),
            )],
            new Class_(new Identifier('Box')),
            '/Box.xphp',
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Default for generic parameter `T`');
        $this->expectExceptionMessage('Stringable');
        $registry->validateDefaultsAgainstBounds();
    }

    public function testValidateDefaultsAgainstBoundsSkipsTypeParamRefDefaults(): void
    {
        // `class Box<A : Stringable, B : Stringable = A>` -- B's default
        // references A. At decl time, A's concrete is unknown; the sweep skips
        // B's check. At inst time, padding substitutes B = A's concrete and the
        // existing validateBounds re-checks against B's bound.
        $hierarchy = new TypeHierarchy(['App\\Tag' => ['Stringable']]);
        $registry = new Registry(hierarchy: $hierarchy);
        $registry->recordDefinition(
            'App\\Box',
            'Box',
            [
                new TypeParam('A', bound: new BoundLeaf(new TypeRef('Stringable'))),
                new TypeParam(
                    'B',
                    bound: new BoundLeaf(new TypeRef('Stringable')),
                    default: new TypeRef('A', isTypeParam: true),
                ),
            ],
            new Class_(new Identifier('Box')),
            '/Box.xphp',
        );

        // Must not throw at decl time -- B's verdict depends on A's concrete.
        $registry->validateDefaultsAgainstBounds();

        // Inst-time positive case: A = Tag (satisfies Stringable). B pads from
        // A = Tag, and the bound check re-verifies Tag against Stringable.
        $good = $registry->recordInstantiation('App\\Box', [new TypeRef('App\\Tag')]);
        self::assertCount(2, $good->concreteTypes);
        self::assertSame('App\\Tag', $good->concreteTypes[1]->name);

        // Inst-time negative case: A = int (violates Stringable). The validation
        // surfaces on A first (positional-first), not B.
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('type parameter A');
        $registry->recordInstantiation('App\\Box', [new TypeRef('int', isScalar: true)]);
    }
}
