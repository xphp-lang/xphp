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
}
