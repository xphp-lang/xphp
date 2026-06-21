<?php

declare(strict_types=1);

namespace XPHP\Transpiler\Monomorphize;

use PhpParser\ParserFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use XPHP\Diagnostics\DiagnosticCollector;

/**
 * Variance-position validation now runs as a Registry phase over collected
 * definitions (moved out of the parser). In compile-mode (no collector) it
 * throws the first violation — byte-identical to the old parse-time check; in
 * check-mode (collector) it gathers every violation, located at the offending
 * member, across all definitions in one run.
 */
final class VariancePositionPhaseTest extends TestCase
{
    /**
     * @return iterable<string, array{string, list<string>}>
     */
    public static function rejectedSources(): iterable
    {
        yield 'covariant in method parameter' => [
            "<?php\nnamespace App;\nclass Producer<+T>\n{\n    public function set(T \$x): void {}\n}\n",
            ['+T', 'method parameter'],
        ];
        yield 'contravariant in method return' => [
            "<?php\nnamespace App;\nclass Consumer<-T>\n{\n    public function get(): T { throw new \\LogicException; }\n}\n",
            ['-T', 'method return'],
        ];
        yield 'covariant in mutable property' => [
            "<?php\nnamespace App;\nclass Producer<+T>\n{\n    public T \$item;\n}\n",
            ['mutable property'],
        ];
        yield 'covariant in readonly property' => [
            "<?php\nnamespace App;\nclass Producer<+T>\n{\n    public readonly T \$item;\n    public function get(): T { return \$this->item; }\n}\n",
            ['readonly property'],
        ];
        yield 'covariant in bound' => [
            "<?php\nnamespace App;\nclass Sortable<+T : Box<T>>\n{\n    public function get(): T { throw new \\LogicException; }\n}\n",
            ['bound'],
        ];
        // NOTE: `+T` in a *non-promoted* constructor parameter of a variant class is
        // ALLOWED — a constructor parameter is variance-exempt (constructors aren't
        // called through upcast references, and PHP exempts `__construct` from LSP), so
        // the real type is emitted there. See VarianceEdgeIntegrationTest's covariant
        // immutable-collection test. A *promoted* constructor param is a PROPERTY, which stays
        // strictly invariant (a `T`-typed property would PHP-fatal across the chain):
        yield 'covariant in promoted constructor property' => [
            "<?php\nnamespace App;\nclass Producer<+T>\n{\n    public function __construct(public T \$item) {}\n}\n",
            ['constructor parameter'],
        ];
        yield 'covariant in nested closure parameter' => [
            "<?php\nnamespace App;\nclass Producer<+T>\n{\n    public function emit(): array\n    {\n        \$f = function (T \$x) {};\n        return [];\n    }\n}\n",
            ['nested closure/arrow parameter'],
        ];
        yield 'contravariant in nested arrow return' => [
            "<?php\nnamespace App;\nclass Consumer<-T>\n{\n    public function pipe(): array\n    {\n        \$f = fn (): T => null;\n        return [];\n    }\n}\n",
            ['nested closure/arrow return'],
        ];
        yield 'covariant in nested generic input' => [
            "<?php\nnamespace App;\nclass Producer<+T>\n{\n    public function set(Box<T> \$x): void {}\n}\n",
            ['+T', 'method parameter'],
        ];
        yield 'contravariant in nested generic return' => [
            "<?php\nnamespace App;\nclass Consumer<-T>\n{\n    public function fetch(): Box<T> { throw new \\LogicException; }\n}\n",
            ['-T', 'method return'],
        ];
        yield 'interface method signature' => [
            "<?php\nnamespace App;\ninterface Producer<+T>\n{\n    public function feed(T \$x): void;\n}\n",
            ['+T'],
        ];
    }

    /**
     * @param list<string> $fragments
     */
    #[DataProvider('rejectedSources')]
    public function testVariancePositionIsRejectedInCompileMode(string $source, array $fragments): void
    {
        $registry = $this->registryFor($source);

        try {
            $registry->validateVariancePositions();
            self::fail('expected a variance-position violation');
        } catch (RuntimeException $e) {
            foreach ($fragments as $fragment) {
                self::assertStringContainsString($fragment, $e->getMessage());
            }
        }
    }

    public function testViolationIsCollectedWithMemberLineInCheckMode(): void
    {
        // Line 5 holds `public function set(T $x)`.
        $source = "<?php\nnamespace App;\nclass Producer<+T>\n{\n    public function set(T \$x): void {}\n}\n";
        $collector = new DiagnosticCollector();
        $registry = $this->registryFor($source, $collector);

        $registry->validateVariancePositions(); // must NOT throw

        self::assertCount(1, $collector->all());
        $d = $collector->all()[0];
        self::assertSame(VariancePositionValidator::CODE_VARIANCE_POSITION, $d->code);
        self::assertNotNull($d->location);
        self::assertSame('/T.xphp', $d->location->file);
        self::assertSame(5, $d->location->line);
        self::assertStringContainsString('method parameter', $d->message);
    }

    public function testAllViolationsAcrossDefinitionsCollectedInOneRun(): void
    {
        // Two distinct templates, each with a variance violation — both reported.
        $source = "<?php\nnamespace App;\n"
            . "class Producer<+T>\n{\n    public function set(T \$x): void {}\n}\n"
            . "class Consumer<-T>\n{\n    public function get(): T { throw new \\LogicException; }\n}\n";
        $collector = new DiagnosticCollector();
        $registry = $this->registryFor($source, $collector);

        $registry->validateVariancePositions();

        self::assertCount(2, $collector->all());
        foreach ($collector->all() as $d) {
            self::assertSame(VariancePositionValidator::CODE_VARIANCE_POSITION, $d->code);
        }
    }

    private function registryFor(string $source, ?DiagnosticCollector $collector = null): Registry
    {
        $ast = (new XphpSourceParser((new ParserFactory())->createForHostVersion()))->parse($source);
        $registry = new Registry(diagnostics: $collector);
        (new RegistryCollector($registry))->collectDefinitions($ast, '/T.xphp');

        return $registry;
    }
}
