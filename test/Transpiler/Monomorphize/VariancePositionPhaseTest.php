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
        // NOTE: a NESTED type-param in a bound (`+T : Box<T>`) is owned by the composing
        // inner-variance pass, not this direct-position pass — see
        // testNestedTypeParamIsRejectedByTheComposingPass.
        // A covariant param as the BARE leaf of a sibling class param's bound is a bound position
        // too, flagged consistently with the inner-arg `Box<T>` case above. (Distinct from the
        // supported method-level `contains<U : E>` shape, where U is a *method* type parameter.)
        yield 'covariant in sibling bare bound' => [
            "<?php\nnamespace App;\nclass Pair<+T, U : T>\n{\n    public function get(): T { throw new \\LogicException; }\n}\n",
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
        // NOTE: a NESTED type-param in a method parameter/return (`Box<T>`) is owned by the
        // composing inner-variance pass — see testNestedTypeParamIsRejectedByTheComposingPass.
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
     * A type-param NESTED inside a type constructor (`Box<T>` in a parameter, return, or bound) is
     * judged by the COMPOSING inner-variance pass, not the direct-position pass: its effective variance
     * is the composition of the outer position with the inner slot's variance (here Box's invariant
     * slot ⇒ invariant ⇒ `+T`/`-T` rejected). The direct-position pass deliberately does NOT descend
     * into type-constructor args, so these are reported by `validateInnerVariance` with the composing
     * "via slot N of …" message — never double-reported by both passes.
     *
     * @param list<string> $fragments
     */
    #[DataProvider('nestedComposingRejections')]
    public function testNestedTypeParamIsRejectedByTheComposingPass(string $source, array $fragments): void
    {
        $registry = $this->registryFor($source);
        // The direct-position pass must stay SILENT on a purely-nested violation (no double-report).
        $registry->validateVariancePositions();

        try {
            $registry->validateInnerVariance();
            self::fail('expected an inner-variance composition violation');
        } catch (RuntimeException $e) {
            foreach ($fragments as $fragment) {
                self::assertStringContainsString($fragment, $e->getMessage());
            }
        }
    }

    /**
     * @return iterable<string, array{string, list<string>}>
     */
    public static function nestedComposingRejections(): iterable
    {
        yield 'covariant nested in method parameter' => [
            "<?php\nnamespace App;\nclass Producer<+T>\n{\n    public function set(Box<T> \$x): void {}\n}\n",
            ['+T', 'invariant-only position', 'via slot 0 of'],
        ];
        yield 'contravariant nested in method return' => [
            "<?php\nnamespace App;\nclass Consumer<-T>\n{\n    public function fetch(): Box<T> { throw new \\LogicException; }\n}\n",
            ['-T', 'invariant-only position', 'via slot 0 of'],
        ];
        yield 'covariant nested in bound' => [
            "<?php\nnamespace App;\nclass Sortable<+T : Box<T>>\n{\n    public function get(): T { throw new \\LogicException; }\n}\n",
            ['+T', 'invariant-only position', 'via slot 0 of'],
        ];
        // A NESTED type-param in a VISIBLE (non-promoted) declared property — `public Box<T> $item` on
        // `+T`. The property's outer position is invariant; Box's invariant slot composes to invariant,
        // so `+T` is rejected by the composing pass via the declared-property walk (a private property
        // would be exempt — only visible ones are walked).
        yield 'covariant nested in visible declared property' => [
            "<?php\nnamespace App;\nclass Producer<+T>\n{\n    public Box<T> \$item;\n}\n",
            ['+T', 'invariant-only position', 'via slot 0 of'],
        ];
        // Composition through a NON-invariant inner slot. `Producer<+X>` as a method PARAMETER:
        // compose(contravariant param, covariant slot) = contravariant → a covariant `+E` is rejected.
        yield 'covariant Producer param composes to contravariant' => [
            "<?php\nnamespace App;\ninterface Producer<+X> { public function get(): X; }\nclass Box<+E>\n{\n    public function take(Producer<E> \$p): void {}\n}\n",
            ['+E', 'contravariant-only position', 'via slot 0 of'],
        ];
        // The mirror that confirms the composing pass owns the unsound direction too: `Sink<-E>` with a
        // `Comparator<-T>` parameter — compose(contravariant param, contravariant slot) = covariant → a
        // contravariant `-E` is rejected. (The sound covariant case is the accept test below.)
        yield 'contravariant Sink with Comparator composes to covariant' => [
            "<?php\nnamespace App;\ninterface Comparator<-T> { public function compare(T \$a, T \$b): int; }\nclass Sink<-E>\n{\n    public function pick(Comparator<E> \$c): void {}\n}\n",
            ['-E', 'covariant-only position', 'via slot 0 of'],
        ];
    }

    /**
     * The sound nested compositions that the buggy direct-position descent used to reject: a type-param
     * nested in a contravariant slot inside a same-variance outer position composes back to that
     * variance, which the class's marker may occupy. Neither pass may flag these.
     *
     * @param string $source
     */
    #[DataProvider('soundNestedCompositions')]
    public function testSoundNestedCompositionIsAccepted(string $source): void
    {
        $registry = $this->registryFor($source);
        $registry->validateVariancePositions(); // must NOT throw
        $registry->validateInnerVariance();     // must NOT throw
        $this->addToAssertionCount(1);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function soundNestedCompositions(): iterable
    {
        // The headline sound case: a `Comparator<-T>` parameter on a covariant `+E` — compose(contra
        // param, contra slot) = covariant, which `+E` may occupy. Sound; must be accepted.
        yield 'Comparator param on covariant class' => [
            "<?php\nnamespace App;\ninterface Comparator<-T> { public function compare(T \$a, T \$b): int; }\nclass Box<+E>\n{\n    public function pick(Comparator<E> \$c): void {}\n}\n",
        ];
        // A `Producer<+X>` RETURN on a covariant `+E` — compose(covariant return, covariant slot) =
        // covariant. Sound; must be accepted.
        yield 'Producer return on covariant class' => [
            "<?php\nnamespace App;\ninterface Producer<+X> { public function get(): X; }\nclass Box<+E>\n{\n    public function make(): Producer<E> { throw new \\LogicException; }\n}\n",
        ];
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

    public function testVisiblePromotedConstructorPropertyIsReportedExactlyOnce(): void
    {
        // A VISIBLE promoted constructor property (`public T $item`) is a direct occurrence the
        // position pass owns (it reports it as a 'constructor parameter'). The composing pass must NOT
        // also report it — it cedes the direct leaf of a PROMOTED constructor param (only a NON-promoted
        // constructor param, which the position pass exempts, stays owned by the composing pass). In
        // check-mode (both passes run) this must yield EXACTLY ONE diagnostic, not two.
        $source = "<?php\nnamespace App;\nclass P<+T>\n{\n    public function __construct(public T \$item) {}\n}\n";
        $collector = new DiagnosticCollector();
        $registry = $this->registryFor($source, $collector);

        $registry->validateVariancePositions();
        $registry->validateInnerVariance();

        self::assertCount(1, $collector->all());
        self::assertSame(VariancePositionValidator::CODE_VARIANCE_POSITION, $collector->all()[0]->code);
    }

    public function testNonPromotedNonBareConstructorParamIsOwnedByTheComposingPassOnce(): void
    {
        // The companion: a NON-promoted, non-bare constructor param (`?T $x`) is exempt from the
        // position pass, so the composing pass keeps ownership of its direct leaf — exactly one
        // `inner_variance` diagnostic, no double-report. (The bare-`T` immutable shape stays exempt by
        // both; a promoted `public T $item` is owned by the position pass — see the test above.)
        $source = "<?php\nnamespace App;\nclass P<+T>\n{\n    public function __construct(?T \$x) {}\n}\n";
        $collector = new DiagnosticCollector();
        $registry = $this->registryFor($source, $collector);

        $registry->validateVariancePositions();
        $registry->validateInnerVariance();

        self::assertCount(1, $collector->all());
        self::assertSame(InnerVarianceValidator::CODE_INNER_VARIANCE, $collector->all()[0]->code);
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
