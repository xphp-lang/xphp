<?php

declare(strict_types=1);

namespace XPHP\Transpiler\Monomorphize;

use PhpParser\Node\Identifier;
use PhpParser\Node\Stmt\Class_;
use PHPUnit\Framework\TestCase;
use XPHP\Diagnostics\DiagnosticCollector;
use XPHP\Diagnostics\SourceLocation;

/**
 * Collect-mode behavior for the remaining flat Registry checks (duplicate definition,
 * missing required type argument, default-vs-bound, undefined template). With a collector
 * each reports a Diagnostic and the run continues; without one they throw as before
 * (byte-identical, pinned by existing RegistryBoundsTest / GenericFunctionIntegrationTest).
 */
final class RegistryCheckDiagnosticsTest extends TestCase
{
    public function testMissingTypeArgumentIsCollectedAtCallSite(): void
    {
        $collector = new DiagnosticCollector();
        $registry = new Registry(diagnostics: $collector);
        $registry->recordDefinition(
            'App\\Pair',
            'Pair',
            [new TypeParam('A'), new TypeParam('B')], // B has no default
            $this->classAt(1),
            '/Pair.xphp',
        );

        $registry->recordInstantiation('App\\Pair', [new TypeRef('int', isScalar: true)], new SourceLocation('/Use.xphp', 8));

        self::assertCount(1, $collector->all());
        $d = $collector->all()[0];
        self::assertSame(Registry::CODE_MISSING_TYPE_ARGUMENT, $d->code);
        self::assertEquals(new SourceLocation('/Use.xphp', 8), $d->location);
        self::assertSame(
            'Generic template "App\\Pair" was instantiated with 1 type argument(s) '
            . 'but parameter `B` (position 2) has no default; supply it '
            . 'explicitly or add defaults to every preceding required parameter.',
            $d->message,
        );
    }

    public function testDefaultBoundViolationIsCollectedAtDeclaration(): void
    {
        $collector = new DiagnosticCollector();
        $registry = new Registry(hierarchy: new TypeHierarchy([]), diagnostics: $collector);
        $registry->recordDefinition(
            'App\\Box',
            'Box',
            [new TypeParam('T', new BoundLeaf(new TypeRef('Stringable')), new TypeRef('int', isScalar: true))],
            $this->classAt(4),
            '/Box.xphp',
        );

        $registry->validateDefaultsAgainstBounds();

        self::assertCount(1, $collector->all());
        $d = $collector->all()[0];
        self::assertSame(Registry::CODE_DEFAULT_BOUND_VIOLATION, $d->code);
        self::assertEquals(new SourceLocation('/Box.xphp', 4), $d->location);

        $expected = <<<'TXT'
            Default for generic parameter `T` of "App\Box" violates the parameter's bound.
              bound:   Stringable
              default: int
              reason:  does not satisfy "Stringable".
            TXT;
        self::assertSame($expected, $d->message);
    }

    public function testMultipleDefaultViolationsCollectedForOneDefinition(): void
    {
        $collector = new DiagnosticCollector();
        $registry = new Registry(hierarchy: new TypeHierarchy([]), diagnostics: $collector);
        $registry->recordDefinition(
            'App\\Pair',
            'Pair',
            [
                new TypeParam('A', new BoundLeaf(new TypeRef('Stringable')), new TypeRef('int', isScalar: true)),
                new TypeParam('B', new BoundLeaf(new TypeRef('Countable')), new TypeRef('float', isScalar: true)),
            ],
            $this->classAt(1),
            '/Pair.xphp',
        );

        $registry->validateDefaultsAgainstBounds();

        // Both violating defaults are reported — the per-param loop continues past the first.
        self::assertCount(2, $collector->all());
    }

    public function testUndefinedTemplateIsCollected(): void
    {
        $collector = new DiagnosticCollector();
        $registry = new Registry(diagnostics: $collector);

        // No definition recorded for App\Ghost.
        $registry->recordInstantiation('App\\Ghost', [new TypeRef('int', isScalar: true)]);
        $registry->collectUndefinedTemplates($collector);

        self::assertCount(1, $collector->all());
        $d = $collector->all()[0];
        self::assertSame(Registry::CODE_UNDEFINED_TEMPLATE, $d->code);
        self::assertNull($d->location);
        self::assertStringContainsString('Generic template "App\\Ghost" was instantiated but never defined', $d->message);
    }

    public function testDefinedTemplatesProduceNoUndefinedDiagnostics(): void
    {
        $collector = new DiagnosticCollector();
        $registry = new Registry(diagnostics: $collector);
        $registry->recordDefinition('App\\Box', 'Box', [new TypeParam('T')], $this->classAt(1), '/Box.xphp');
        $registry->recordInstantiation('App\\Box', [new TypeRef('int', isScalar: true)]);

        $registry->collectUndefinedTemplates($collector);

        self::assertSame([], $collector->all());
    }

    private function classAt(int $line): Class_
    {
        return new Class_(new Identifier('C'), [], ['startLine' => $line]);
    }
}
