<?php

declare(strict_types=1);

namespace XPHP\Transpiler\Monomorphize;

use PhpParser\Node\Identifier;
use PhpParser\Node\Stmt\Class_;
use PHPUnit\Framework\TestCase;
use XPHP\Diagnostics\DiagnosticCollector;
use XPHP\Diagnostics\Severity;
use XPHP\Diagnostics\SourceLocation;

/**
 * `Registry::validateVarianceEdgeProvability`: a covariant/contravariant template instantiated
 * over an element type that is NOT in the source set (and not a PHP built-in) drops its variance
 * `extends` edge silently. The Registry now reports that as a non-failing Warning (check-mode,
 * collector present); it stays silent for provable, scalar, type-param, generic, and invariant
 * cases, and never throws (compile-mode, no collector).
 *
 * Hierarchy: `App\Fruit` and `App\Banana extends App\Fruit` are declared; `App\Book` is not.
 */
final class RegistryVarianceEdgeDiagnosticTest extends TestCase
{
    public function testCovariantOverUnprovableLeafIsWarned(): void
    {
        $collector = new DiagnosticCollector();
        $loc = new SourceLocation('/App/Use.xphp', 9);

        $this->registry($collector)->recordInstantiation(
            'App\\Producer',
            [new TypeRef('App\\Book')],
            $loc,
        );

        self::assertFalse($collector->hasErrors(), 'a warning must not fail the gate');
        self::assertCount(1, $collector->all());
        $d = $collector->all()[0];
        self::assertSame(Severity::Warning, $d->severity);
        self::assertSame(Registry::CODE_VARIANCE_EDGE_UNPROVABLE, $d->code);
        self::assertSame($loc, $d->location);
    }

    public function testWarningMessageHasExactText(): void
    {
        $collector = new DiagnosticCollector();
        $this->registry($collector)->recordInstantiation('App\\Producer', [new TypeRef('App\\Book')]);

        $expected = <<<'TXT'
            Variance edge cannot be proven while instantiating App\Producer<App\Book>.
              type parameter +T is covariant, but App\Book is not in the source set the hierarchy was built from (and is not a recognized PHP built-in),
              so the compiler cannot prove its subtype edges — this specialization is not linked to related ones and the covariant relationship silently does not apply at runtime.

              Add App\Book to the source set the hierarchy is built from to enable the edge.
            TXT;

        self::assertSame($expected, $collector->all()[0]->message);
    }

    public function testContravariantOverUnprovableLeafIsWarnedWithMinusMarker(): void
    {
        $collector = new DiagnosticCollector();
        $this->registry($collector)->recordInstantiation('App\\Consumer', [new TypeRef('App\\Book')]);

        self::assertCount(1, $collector->all());
        self::assertStringContainsString('type parameter -T is contravariant', $collector->all()[0]->message);
    }

    public function testProvableDeclaredLeafIsSilent(): void
    {
        // `App\Banana` is in the hierarchy → provable verdict, no warning.
        $collector = new DiagnosticCollector();
        $this->registry($collector)->recordInstantiation('App\\Producer', [new TypeRef('App\\Banana')]);

        self::assertSame([], $collector->all());
    }

    public function testInvariantPositionIsSilent(): void
    {
        // Invariant positions form no edge, so an unprovable type there loses nothing.
        $collector = new DiagnosticCollector();
        $this->registry($collector)->recordInstantiation('App\\Box', [new TypeRef('App\\Book')]);

        self::assertSame([], $collector->all());
    }

    public function testScalarArgIsSilent(): void
    {
        $collector = new DiagnosticCollector();
        $this->registry($collector)->recordInstantiation('App\\Producer', [new TypeRef('int', isScalar: true)]);

        self::assertSame([], $collector->all());
    }

    public function testTypeParamArgIsSilent(): void
    {
        $collector = new DiagnosticCollector();
        $this->registry($collector)->recordInstantiation('App\\Producer', [new TypeRef('T', isTypeParam: true)]);

        self::assertSame([], $collector->all());
    }

    public function testGenericArgIsSilentLeafOnly(): void
    {
        // A generic arg is leaf-only-deferred: its inner leaves are checked when its own
        // instantiation is recorded, not at the outer level — even when its template name
        // (`App\External`) is itself not in the source set.
        $collector = new DiagnosticCollector();
        $this->registry($collector)->recordInstantiation(
            'App\\Producer',
            [new TypeRef('App\\External', [new TypeRef('App\\Banana')])],
        );

        self::assertSame([], $collector->all());
    }

    public function testTwoVariantPositionsEachUnprovableWarnTwice(): void
    {
        // `Pair<out A, out B>` over two unprovable leaves → one warning per covariant position.
        $collector = new DiagnosticCollector();
        $this->registry($collector)->recordInstantiation(
            'App\\Pair',
            [new TypeRef('App\\Book'), new TypeRef('App\\Author')],
        );

        self::assertCount(2, $collector->all());
        foreach ($collector->all() as $d) {
            self::assertSame(Registry::CODE_VARIANCE_EDGE_UNPROVABLE, $d->code);
        }
    }

    public function testLeadingBackslashTemplateNameResolvesAndIsNormalisedInMessage(): void
    {
        // The template FQN may arrive with a leading backslash; it must still resolve to the
        // recorded definition (so the warning fires) and the message must name it normalised
        // (no leading backslash), matching the bounds path.
        $collector = new DiagnosticCollector();
        $this->registry($collector)->recordInstantiation('\\App\\Producer', [new TypeRef('App\\Book')]);

        self::assertCount(1, $collector->all());
        self::assertStringContainsString('instantiating App\\Producer<App\\Book>', $collector->all()[0]->message);
    }

    public function testEarlierInvariantPositionDoesNotShortCircuitLaterVariant(): void
    {
        // `Mixed<A, out B>`: the invariant A is skipped, but the walk must continue to the
        // covariant B and still warn (pins `continue`, not `break`, on the invariant skip).
        $collector = new DiagnosticCollector();
        $this->registry($collector)->recordInstantiation(
            'App\\Mixed',
            [new TypeRef('App\\Book'), new TypeRef('App\\Author')],
        );

        self::assertCount(1, $collector->all());
        self::assertStringContainsString('App\\Author', $collector->all()[0]->message);
    }

    public function testEarlierScalarPositionDoesNotShortCircuitLaterVariant(): void
    {
        // `Pair<out A, out B>` with a scalar A: A is skipped, B still warns (pins `continue` on
        // the scalar/type-param/generic skip).
        $collector = new DiagnosticCollector();
        $this->registry($collector)->recordInstantiation(
            'App\\Pair',
            [new TypeRef('int', isScalar: true), new TypeRef('App\\Book')],
        );

        self::assertCount(1, $collector->all());
        self::assertStringContainsString('App\\Book', $collector->all()[0]->message);
    }

    public function testEarlierDeclaredPositionDoesNotShortCircuitLaterVariant(): void
    {
        // `Pair<out A, out B>` with a declared A: A is skipped (provable), B still warns (pins
        // `continue` on the isDeclared skip).
        $collector = new DiagnosticCollector();
        $this->registry($collector)->recordInstantiation(
            'App\\Pair',
            [new TypeRef('App\\Banana'), new TypeRef('App\\Book')],
        );

        self::assertCount(1, $collector->all());
        self::assertStringContainsString('App\\Book', $collector->all()[0]->message);
    }

    public function testCompileModeNeverThrowsAndEmitsNothing(): void
    {
        // No collector (compile): the warning has no sink, so recording completes silently.
        $registry = $this->registry(null);
        $registry->recordInstantiation('App\\Producer', [new TypeRef('App\\Book')]);

        self::assertCount(1, $registry->instantiations());
    }

    private function registry(?DiagnosticCollector $collector): Registry
    {
        $hierarchy = new TypeHierarchy([
            'App\\Fruit' => [],
            'App\\Banana' => ['App\\Fruit'],
        ]);
        $registry = new Registry(hierarchy: $hierarchy, diagnostics: $collector);
        $registry->recordDefinition(
            'App\\Producer',
            'Producer',
            [new TypeParam('T', null, null, Variance::Covariant)],
            new Class_(new Identifier('Producer')),
            '/App/Producer.xphp',
        );
        $registry->recordDefinition(
            'App\\Consumer',
            'Consumer',
            [new TypeParam('T', null, null, Variance::Contravariant)],
            new Class_(new Identifier('Consumer')),
            '/App/Consumer.xphp',
        );
        $registry->recordDefinition(
            'App\\Box',
            'Box',
            [new TypeParam('T', null, null, Variance::Invariant)],
            new Class_(new Identifier('Box')),
            '/App/Box.xphp',
        );
        $registry->recordDefinition(
            'App\\Pair',
            'Pair',
            [
                new TypeParam('A', null, null, Variance::Covariant),
                new TypeParam('B', null, null, Variance::Covariant),
            ],
            new Class_(new Identifier('Pair')),
            '/App/Pair.xphp',
        );
        // Invariant first, covariant second — to prove an earlier skipped position
        // doesn't short-circuit the walk before a later variant position.
        $registry->recordDefinition(
            'App\\Mixed',
            'Mixed',
            [
                new TypeParam('A', null, null, Variance::Invariant),
                new TypeParam('B', null, null, Variance::Covariant),
            ],
            new Class_(new Identifier('Mixed')),
            '/App/Mixed.xphp',
        );

        return $registry;
    }
}
