<?php

declare(strict_types=1);

namespace XPHP\Transpiler\Monomorphize;

use PhpParser\Node\Identifier;
use PhpParser\Node\Stmt\Class_;
use PhpParser\ParserFactory;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use XPHP\Diagnostics\DiagnosticCollector;
use XPHP\Diagnostics\DiagnosticSource;
use XPHP\Diagnostics\Severity;
use XPHP\Diagnostics\SourceLocation;

/**
 * The collector seam on the bound-violation path: with a DiagnosticCollector the
 * Registry reports bound violations instead of throwing (and keeps recording), so
 * `xphp check` can collect every error in one run; without one it throws exactly
 * as before (`xphp compile`).
 */
final class RegistryBoundsDiagnosticTest extends TestCase
{
    public function testBoundViolationIsCollectedNotThrownWhenCollectorPresent(): void
    {
        $collector = new DiagnosticCollector();
        $registry = $this->boxRegistry($collector);
        $loc = new SourceLocation('/App/Box.xphp', 7);

        // Must NOT throw.
        $registry->recordInstantiation('App\\Box', [new TypeRef('int', isScalar: true)], $loc);

        self::assertTrue($collector->hasErrors());
        self::assertCount(1, $collector->all());

        $d = $collector->all()[0];
        self::assertSame(Severity::Error, $d->severity);
        self::assertSame(Registry::CODE_BOUND_VIOLATION, $d->code);
        self::assertSame(DiagnosticSource::Xphp, $d->source);
        self::assertSame($loc, $d->location);
        self::assertStringContainsString('Generic bound violated', $d->message);
        self::assertStringContainsString('"int" does not extend/implement "Stringable"', $d->message);

        // Recording still completed (continue-safely): the instantiation is on file.
        self::assertCount(1, $registry->instantiations());
    }

    public function testCollectedMessageHasExactText(): void
    {
        $collector = new DiagnosticCollector();
        $this->boxRegistry($collector)
            ->recordInstantiation('App\\Box', [new TypeRef('int', isScalar: true)]);

        $expected = <<<'TXT'
            Generic bound violated while instantiating App\Box<int>.
              type parameter T is bounded by Stringable
              but the supplied concrete type is int

              "int" does not extend/implement "Stringable".
            TXT;

        self::assertSame($expected, $collector->all()[0]->message);
    }

    public function testCollectedMessageIsByteIdenticalToThrownMessage(): void
    {
        $thrown = null;
        try {
            $this->boxRegistry(null)
                ->recordInstantiation('App\\Box', [new TypeRef('int', isScalar: true)]);
            self::fail('expected RuntimeException in throw-mode');
        } catch (RuntimeException $e) {
            $thrown = $e->getMessage();
        }

        $collector = new DiagnosticCollector();
        $this->boxRegistry($collector)
            ->recordInstantiation('App\\Box', [new TypeRef('int', isScalar: true)]);

        self::assertSame($thrown, $collector->all()[0]->message);
    }

    public function testMultipleViolationsCollectedInOneRun(): void
    {
        $collector = new DiagnosticCollector();
        $hierarchy = new TypeHierarchy([]);
        $registry = new Registry(hierarchy: $hierarchy, diagnostics: $collector);
        $registry->recordDefinition(
            'App\\Pair',
            'Pair',
            [
                new TypeParam('A', new BoundLeaf(new TypeRef('Stringable'))),
                new TypeParam('B', new BoundLeaf(new TypeRef('Countable'))),
            ],
            new Class_(new Identifier('Pair')),
            '/App/Pair.xphp',
        );

        $registry->recordInstantiation(
            'App\\Pair',
            [new TypeRef('int', isScalar: true), new TypeRef('float', isScalar: true)],
        );

        self::assertCount(2, $collector->all());
    }

    public function testNodeLineIsCapturedFromTheInstantiationSite(): void
    {
        // Line 5 holds the `new Box::<int>(...)` instantiation site.
        $source = <<<'XPHP'
            <?php
            declare(strict_types=1);
            namespace App;
            class Box<T : \Stringable> { public function __construct(public T $v) {} }
            $b = new Box::<int>(5);
            XPHP;

        $parser = new XphpSourceParser((new ParserFactory())->createForHostVersion());
        $ast = $parser->parse($source);

        $collector = new DiagnosticCollector();
        $registry = new Registry(
            hierarchy: TypeHierarchy::fromAstPerFile(['/App/Box.xphp' => $ast]),
            diagnostics: $collector,
        );
        $rc = new RegistryCollector($registry);
        $rc->collectDefinitions($ast, '/App/Box.xphp');
        $rc->collectInstantiations($ast, '/App/Box.xphp');

        self::assertCount(1, $collector->all());
        $loc = $collector->all()[0]->location;
        self::assertNotNull($loc);
        self::assertSame('/App/Box.xphp', $loc->file);
        self::assertSame(5, $loc->line);
    }

    private function boxRegistry(?DiagnosticCollector $collector): Registry
    {
        $registry = new Registry(hierarchy: new TypeHierarchy([]), diagnostics: $collector);
        $registry->recordDefinition(
            'App\\Box',
            'Box',
            [new TypeParam('T', new BoundLeaf(new TypeRef('Stringable')))],
            new Class_(new Identifier('Box')),
            '/App/Box.xphp',
        );

        return $registry;
    }
}
