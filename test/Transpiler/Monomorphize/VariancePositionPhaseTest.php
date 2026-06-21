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
        // A *protected* property/promoted property is also a visible property (PHP
        // enforces invariant types across the chain for it), so it stays rejected —
        // only PRIVATE is exempt. These pin the PRIVATE-bit detection against a
        // mutant that swaps the visibility bit for PROTECTED.
        yield 'covariant in protected promoted constructor property' => [
            "<?php\nnamespace App;\nclass Producer<+T>\n{\n    public function __construct(protected T \$item) {}\n}\n",
            ['constructor parameter'],
        ];
        yield 'covariant in protected mutable property' => [
            "<?php\nnamespace App;\nclass Producer<+T>\n{\n    protected T \$item;\n}\n",
            ['mutable property'],
        ];
        // Asymmetric visibility (PHP 8.4): a `public private(set)` property is
        // externally *readable* through an upcast reference (PRIVATE_SET sets a
        // separate bit, not PRIVATE), so it's on the visible variance surface and
        // stays strictly invariant. Only a truly *private* slot is exempt. These
        // pin the PRIVATE-bit detection against a mutant that swaps it for PRIVATE_SET.
        yield 'covariant in public private(set) promoted constructor property' => [
            "<?php\nnamespace App;\nclass Producer<+T>\n{\n    public function __construct(public private(set) T \$item) {}\n}\n",
            ['constructor parameter'],
        ];
        yield 'covariant in public private(set) declared property' => [
            "<?php\nnamespace App;\nclass Producer<+T>\n{\n    public private(set) T \$item;\n}\n",
            ['mutable property'],
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
        // A by-reference parameter is read AND written back, so it is an
        // invariant position — neither +T nor -T is allowed there.
        yield 'contravariant in by-reference parameter' => [
            "<?php\nnamespace App;\nclass Consumer<-T>\n{\n    public function swap(T &\$x): void {}\n}\n",
            ['-T', 'by-reference parameter'],
        ];
        yield 'covariant in by-reference parameter' => [
            "<?php\nnamespace App;\nclass Producer<+T>\n{\n    public function swap(T &\$x): void {}\n}\n",
            ['+T', 'by-reference parameter'],
        ];
        yield 'contravariant in nested closure by-reference parameter' => [
            "<?php\nnamespace App;\nclass Consumer<-T>\n{\n    public function pipe(): array\n    {\n        \$f = function (T &\$x) {};\n        return [];\n    }\n}\n",
            ['by-reference parameter'],
        ];
        // A variant class can't be `final`: its specializations are linked by
        // real `extends` edges, which a `final` class can't anchor.
        yield 'final variant class' => [
            "<?php\nnamespace App;\nfinal class Producer<+T>\n{\n    public function get(): T { throw new \\LogicException; }\n}\n",
            ['cannot be declared `final`'],
        ];
    }

    /**
     * Sources that must pass variance-position validation. A PRIVATE property
     * (declared or promoted; mutable or readonly; bare or inner-generic) is exempt
     * from the property-invariance rule: PHP doesn't type-check private slots across
     * the `extends` chain, and a private slot is invisible to the variance surface.
     *
     * @return iterable<string, array{string}>
     */
    public static function allowedSources(): iterable
    {
        yield 'covariant in private promoted constructor property' => [
            "<?php\nnamespace App;\nclass Producer<+T>\n{\n    public function __construct(private T \$item) {}\n    public function get(): T { return \$this->item; }\n}\n",
        ];
        yield 'covariant in private declared property' => [
            "<?php\nnamespace App;\nclass Producer<+T>\n{\n    private T \$item;\n    public function get(): T { return \$this->item; }\n}\n",
        ];
        yield 'covariant in private readonly declared property' => [
            "<?php\nnamespace App;\nclass Producer<+T>\n{\n    private readonly T \$item;\n    public function get(): T { return \$this->item; }\n}\n",
        ];
        yield 'covariant in private readonly promoted property' => [
            "<?php\nnamespace App;\nclass Producer<+T>\n{\n    public function __construct(private readonly T \$item) {}\n    public function get(): T { return \$this->item; }\n}\n",
        ];
        yield 'contravariant in private promoted constructor property' => [
            "<?php\nnamespace App;\nclass Consumer<-T>\n{\n    public function __construct(private T \$item) {}\n    public function accept(T \$x): void {}\n}\n",
        ];
        // Inner-generic private members are exempt too — the inner-variance walk
        // skips them, so `private Container<T>` doesn't trip composition even though
        // a *visible* `Container<T>` property would (Container's slot is invariant).
        yield 'covariant in private inner-generic declared property' => [
            "<?php\nnamespace App;\nclass Producer<+T>\n{\n    private Box<T> \$item;\n    public function get(): T { throw new \\LogicException; }\n}\n",
        ];
        yield 'covariant in private inner-generic promoted property' => [
            "<?php\nnamespace App;\nclass Producer<+T>\n{\n    public function __construct(private Box<T> \$item) {}\n    public function get(): T { throw new \\LogicException; }\n}\n",
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

    /**
     * A private property carrying a variance marker must pass BOTH the position
     * check and the inner-variance composition check (the latter for the
     * inner-generic cases). Neither phase may throw.
     */
    #[DataProvider('allowedSources')]
    public function testPrivatePropertyVarianceIsAllowed(string $source): void
    {
        $registry = $this->registryFor($source);

        $registry->validateVariancePositions(); // must NOT throw
        $registry->validateInnerVariance();     // must NOT throw

        self::assertTrue(true);
    }

    public function testPrivatePromotedDoesNotShortCircuitLaterConstructorParam(): void
    {
        // A private promoted constructor property is skipped in the inner-variance
        // walk — but the skip must `continue`, not `break`: a LATER constructor
        // param that DOES violate (an inner-generic `Box<T>`, T through an invariant
        // slot) must still be reached and rejected. The position phase passes both
        // params (a bare/inner-generic ctor param is position-allowed in a variant
        // class), so inner-variance is the phase that must catch the trailing one.
        $source = "<?php\nnamespace App;\nclass Producer<+T>\n{\n    public function __construct(private T \$first, Box<T> \$second) {}\n}\n";
        $registry = $this->registryFor($source);

        $registry->validateVariancePositions(); // must NOT throw — both params position-allowed

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Variance violation');
        $registry->validateInnerVariance();
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

    public function testByReferenceParamWithoutVarianceMarkersIsAllowed(): void
    {
        // An invariant class (no +T/-T) with a by-ref T parameter is fine — the
        // by-ref invariance rule only constrains variance-marked type params.
        $source = "<?php\nnamespace App;\nclass Box<T>\n{\n    public function swap(T &\$x): void {}\n}\n";
        $registry = $this->registryFor($source);

        $registry->validateVariancePositions(); // must NOT throw
        self::assertTrue(true);
    }

    public function testByReferenceParamDoesNotShortCircuitLaterParams(): void
    {
        // A by-ref param violation must not stop the walk: a *later* violating
        // param in the same signature is still reported (pins `continue`, not
        // `break`, after the by-ref check).
        $source = "<?php\nnamespace App;\nclass Producer<+T>\n{\n    public function f(T &\$a, T \$b): void {}\n}\n";
        $collector = new DiagnosticCollector();
        $registry = $this->registryFor($source, $collector);

        $registry->validateVariancePositions();

        $messages = array_map(static fn ($d): string => $d->message, $collector->all());
        self::assertCount(2, $messages);
        self::assertStringContainsString('by-reference parameter', implode("\n", $messages));
        self::assertStringContainsString('method parameter', implode("\n", $messages));
    }

    private function registryFor(string $source, ?DiagnosticCollector $collector = null): Registry
    {
        $ast = (new XphpSourceParser((new ParserFactory())->createForHostVersion()))->parse($source);
        $registry = new Registry(diagnostics: $collector);
        (new RegistryCollector($registry))->collectDefinitions($ast, '/T.xphp');

        return $registry;
    }
}
