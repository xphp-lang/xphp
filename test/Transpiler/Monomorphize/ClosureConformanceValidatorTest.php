<?php

declare(strict_types=1);

namespace XPHP\Transpiler\Monomorphize;

use PhpParser\ParserFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use XPHP\Diagnostics\DiagnosticCollector;

/**
 * The validator that wires {@see ClosureSignatureConformance} to the statically
 * visible literal sites — a `return` / arrow body (S-A) and a parameter / property
 * default (S-B) — and reports a provable mismatch.
 *
 * The one statically decidable site is the return position (S-A): a `return`
 * literal or an arrow body whose declared return type is `Closure(...)`. A
 * parameter/property default cannot hold a closure literal — a default must be a
 * constant expression — so there is no site there to check.
 *
 * The site-recognition and namespace-tracking logic lives in an anonymous
 * NodeVisitor (invisible to mutation testing), so every rule is pinned
 * behaviourally: an accept/reject pair, exercised in BOTH modes
 * (collect ⇒ diagnostics; fail-fast ⇒ throw).
 */
final class ClosureConformanceValidatorTest extends TestCase
{
    /**
     * A conforming literal at any site is silent in both modes.
     *
     * @param non-empty-string $source
     */
    #[DataProvider('conformingSites')]
    public function testConformingLiteralIsAccepted(string $source): void
    {
        self::assertCount(0, self::collect($source), 'no diagnostic expected');
        self::assertNull($this->throwMode($source), 'compile mode must not throw');
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function conformingSites(): iterable
    {
        yield 'S-A return: same signature' => [
            '<?php function m(): Closure(int $x): int { return fn(int $x): int => $x; }',
        ];
        yield 'S-A return: contravariant param widening' => [
            '<?php function m(): Closure(Apple $a): Fruit { return fn(Fruit $a): Apple => new Apple(); }',
        ];
        yield 'S-A arrow body' => [
            '<?php $f = fn(): Closure(int $x): int => fn(int $x): int => $x;',
        ];
        yield 'S-A method return' => [
            '<?php class C { public function m(): Closure(int $x): int { return fn(int $x): int => $x; } }',
        ];
        yield 'S-A union return: a narrower member conforms' => [
            '<?php function m(): Closure(): int|string { return fn(): int => 0; }',
        ];
        yield 'S-A union parameter: candidate accepts the whole union' => [
            '<?php function m(): Closure(int|string $x): void { return fn(int|string $x): void => null; }',
        ];
        yield 'target references a type parameter ⇒ gradual here' => [
            '<?php class Box<T> { public function m(): Closure(T $x): T { return fn(string $x): int => 0; } }',
        ];
        yield 'S-A return: fully-qualified literal type resolves and conforms' => [
            // `\App\Apple` must resolve absolutely (App\Apple <: App\Fruit), not
            // relative to the namespace (App\App\Apple, undeclared ⇒ gradual).
            '<?php function m(): Closure(): Fruit { return fn(): \App\Apple => new Apple(); }',
        ];
        yield 'S-A DNF group parameter: one param, gradual — matching arity accepted' => [
            // `(A&B)|C $x` is ONE parameter; a mis-scan that split the group into
            // extra params false-rejected this correct literal on arity.
            '<?php function m(): Closure((A&B)|C $x): int { return fn($x): int => 0; }',
        ];
        yield 'S-A DNF group return: gradual leaf accepts any return' => [
            '<?php function m(): Closure(): (A&B)|C { return fn(): int => 0; }',
        ];
        yield 'S-A trailing DNF group return: not truncated to its first member' => [
            // A scan stopping mid-type recorded `return = A` and provably
            // rejected a declared class that is no subtype of A; the full
            // `A|(B&C)` leaf is gradual and must accept.
            '<?php class A {} class Beta {} function m(): Closure(): A|(B&C) { return fn(): Beta => new Beta(); }',
        ];
        // No `Closure(...)` return target ⇒ no site, even though a literal is
        // returned. A non-closure return type must not pair with the literal.
        yield 'return of a literal from a non-closure return type' => [
            '<?php function m(): callable { return fn(string $x): int => 0; }',
        ];
        yield 'arrow body literal under a non-closure return type' => [
            '<?php $f = fn(): callable => fn(string $x): int => 0;',
        ];
    }

    /**
     * A provable mismatch surfaces in both modes, and the diagnostic carries the
     * closure-conformance code, the literal's line, and the expected detail.
     *
     * @param non-empty-string $source
     */
    #[DataProvider('violatingSites')]
    public function testViolatingLiteralIsRejected(string $source, int $line, string $detailNeedle): void
    {
        $diagnostics = self::collect($source);
        self::assertCount(1, $diagnostics);
        $diagnostic = $diagnostics[0];
        self::assertSame(ClosureConformanceValidator::CODE, $diagnostic->code);
        self::assertNotNull($diagnostic->location);
        self::assertSame($line, $diagnostic->location->line, 'diagnostic points at the literal');
        self::assertStringStartsWith(
            'Closure literal does not conform to the declared `Closure(...)` type: ',
            $diagnostic->message,
            'the human-readable prefix precedes the violation detail',
        );
        self::assertStringContainsString($detailNeedle, $diagnostic->message);

        $thrown = $this->throwMode($source);
        self::assertInstanceOf(RuntimeException::class, $thrown);
        self::assertStringContainsString($detailNeedle, $thrown->getMessage());
    }

    /**
     * @return iterable<string, array{string, int, string}>
     */
    public static function violatingSites(): iterable
    {
        yield 'S-A return: param not wider (contravariance)' => [
            "<?php function m(): Closure(int \$x): int {\n    return fn(string \$x): int => 0;\n}",
            2,
            'parameter 1: string is not wider than int',
        ];
        yield 'S-A return: return not a subtype (covariance)' => [
            "<?php function m(): Closure(int \$x): Apple {\n    return fn(int \$x): Fruit => new Fruit();\n}",
            2,
            'return type: App\\Fruit is not a subtype of App\\Apple',
        ];
        yield 'S-A arrow body: arity too few' => [
            "<?php \$f = fn(): Closure(int \$a, int \$b): void =>\n    fn(int \$a): void => null;",
            2,
            'expects at least 2 parameter(s), candidate accepts at most 1',
        ];
        yield 'S-A return: requires more' => [
            "<?php function m(): Closure(int \$a): void {\n    return fn(int \$a, int \$b): void => null;\n}",
            2,
            'requires 2 parameter(s) but the target guarantees only 1',
        ];
        yield 'S-A method return: by-ref mismatch' => [
            "<?php class C {\n    public function m(): Closure(int \$x): void { return fn(int &\$x): void => null; }\n}",
            2,
            'by-reference-ness must match exactly',
        ];
        yield 'S-A union return: outside the union' => [
            "<?php function m(): Closure(): int|string {\n    return fn(): float => 0.0;\n}",
            2,
            'float is not a subtype of int|string',
        ];
        yield 'S-A union parameter: candidate too narrow' => [
            "<?php function m(): Closure(int|string \$x): void {\n    return fn(int \$x): void => null;\n}",
            2,
            'parameter 1: int is not wider than int|string',
        ];
        yield 'S-A return: fully-qualified literal violation is caught' => [
            // Pre-fix, `\App\Fruit` flattened to the relative `App\App\Fruit`
            // (undeclared ⇒ gradual) and this provable violation was missed.
            "<?php function m(): Closure(): Apple {\n    return fn(): \\App\\Fruit => new Fruit();\n}",
            2,
            'App\\Fruit is not a subtype of App\\Apple',
        ];
        yield 'S-A DNF group parameter: wrong arity still rejected' => [
            // The DNF leaf is gradual but the ARITY is not: the one-param target
            // must reject a two-param literal (the group mis-scan used to record
            // a phantom second parameter, false-accepting exactly this shape).
            "<?php function m(): Closure((A&B)|C \$x): int {\n    return fn(int \$a, int \$b): int => 0;\n}",
            2,
            'requires 2 parameter(s) but the target guarantees only 1',
        ];
    }

    /**
     * A returned expression that is not a closure literal (a variable, a call, a
     * property fetch) is not a candidate: the validator must skip it, never fatal
     * by extracting a signature from a non-closure node.
     *
     * @param non-empty-string $source
     */
    #[DataProvider('nonLiteralValues')]
    public function testNonLiteralValueIsSkipped(string $source): void
    {
        self::assertCount(0, self::collect($source));
        self::assertNull($this->throwMode($source));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function nonLiteralValues(): iterable
    {
        yield 'return of a superglobal fetch' => [
            '<?php function m(): Closure(int $x): int { return $GLOBALS["h"]; }',
        ];
        yield 'return of a variable' => [
            '<?php function m(): Closure(int $x): int { $h = fn(string $s): int => 0; return $h; }',
        ];
        yield 'return of a call result' => [
            '<?php function h(): callable { return fn() => 1; } function m(): Closure(int $x): int { return h(); }',
        ];
    }

    public function testUseImportedTypeNamesResolveForConformance(): void
    {
        // Both the target's `Apple` and the candidate's `Fruit` reach the engine
        // only if the `use` imports are tracked: without them each resolves to the
        // undeclared `Client\...`, the relation is unprovable, and the real
        // Fruit-is-not-a-subtype-of-Apple violation is silently lost.
        $source = <<<'X'
        <?php
        namespace App { class Fruit {} class Apple extends Fruit {} }
        namespace Client {
            use App\Apple;
            use App\Fruit;
            function make(): Closure(): Apple { return fn(): Fruit => new Fruit(); }
        }
        X;
        $ast = self::parseRaw($source);

        $diagnostics = new DiagnosticCollector();
        self::validator($ast)->validateFile($ast, 'test.xphp', $diagnostics);

        $collected = $diagnostics->all();
        self::assertCount(1, $collected);
        self::assertStringContainsString(
            'App\\Fruit is not a subtype of App\\Apple',
            $collected[0]->message,
        );
    }

    public function testTargetUnionMembersAreResolvedForConformance(): void
    {
        // Both union members must resolve to `App\*` for the engine to prove `Fruit`
        // is neither; if the resolver didn't recurse into union members they'd stay
        // unqualified/undeclared and the violation would be silently lost.
        $source = <<<'X'
        <?php
        namespace App;
        class Fruit {}
        class Apple extends Fruit {}
        class Orange extends Fruit {}
        function make(): Closure(): Apple|Orange { return fn(): Fruit => new Fruit(); }
        X;
        $ast = self::parseRaw($source);

        $diagnostics = new DiagnosticCollector();
        self::validator($ast)->validateFile($ast, 'test.xphp', $diagnostics);

        self::assertCount(1, $diagnostics->all());
        self::assertStringContainsString(
            'is not a subtype of App\\Apple|App\\Orange',
            $diagnostics->all()[0]->message,
        );
    }

    public function testTargetIntersectionMembersAreResolvedForConformance(): void
    {
        // The intersection members must resolve too — `Fruit` is provably not an
        // `App\Apple`, so the `App\Apple&App\Orange` return target is unsatisfied.
        $source = <<<'X'
        <?php
        namespace App;
        class Fruit {}
        class Apple extends Fruit {}
        class Orange extends Fruit {}
        function make(): Closure(): Apple&Orange { return fn(): Fruit => new Fruit(); }
        X;
        $ast = self::parseRaw($source);

        $diagnostics = new DiagnosticCollector();
        self::validator($ast)->validateFile($ast, 'test.xphp', $diagnostics);

        self::assertCount(1, $diagnostics->all());
        self::assertStringContainsString(
            'is not a subtype of App\\Apple&App\\Orange',
            $diagnostics->all()[0]->message,
        );
    }

    public function testGlobalBracedNamespaceIsHandledWithoutFatal(): void
    {
        // A bare `namespace { ... }` block has a null name; the walk must enter it
        // (global scope) without dereferencing the absent name, and still reach the
        // conformance site inside.
        $source = "<?php\nnamespace { function make(): Closure(int \$x): int { return fn(string \$x): int => 0; } }";
        $ast = self::parseRaw($source);

        $diagnostics = new DiagnosticCollector();
        self::validator($ast)->validateFile($ast, 'test.xphp', $diagnostics);

        self::assertCount(1, $diagnostics->all());
        self::assertStringContainsString('is not wider than int', $diagnostics->all()[0]->message);
    }

    public function testReturnLiteralIsPairedWithItsInnermostEnclosingFunction(): void
    {
        // The inner closure's OWN return type (Closure(int): int) is the target for
        // its `return`; the outer function's `Closure(string): int` target must not
        // leak in. A string param would be REJECTED against the outer target but is
        // ACCEPTED against the inner one — so silence proves correct pairing.
        $source = <<<'X'
        <?php
        function outer(): Closure(string $s): int {
            $inner = function (): Closure(int $x): int {
                return fn(int $x): int => $x;
            };
            return fn(string $s): int => 0;
        }
        X;

        self::assertCount(0, self::collect($source));
    }

    // ---- outer-class decision helpers (directly mutation-covered) --------

    public function testClosureSigOfUnwrapsNullableAndIgnoresPlainTypes(): void
    {
        $ast = self::parseRaw('<?php function m(?Closure(int $x): int $a, int $b): void {}');
        $params = self::firstFunctionParams($ast);

        self::assertInstanceOf(ClosureSignature::class, ClosureConformanceValidator::closureSigOf($params[0]->type));
        self::assertNull(ClosureConformanceValidator::closureSigOf($params[1]->type), 'a plain scalar carries no target');
        self::assertNull(ClosureConformanceValidator::closureSigOf(null));
    }

    // ---- Helpers ---------------------------------------------------------

    /**
     * @return list<\XPHP\Diagnostics\Diagnostic>
     */
    private static function collect(string $source): array
    {
        $ast = self::parse($source);
        $diagnostics = new DiagnosticCollector();
        self::validator($ast)->validateFile($ast, 'test.xphp', $diagnostics);
        return $diagnostics->all();
    }

    private function throwMode(string $source): ?RuntimeException
    {
        $ast = self::parse($source);
        try {
            self::validator($ast)->validateFile($ast, 'test.xphp', null);
            return null;
        } catch (RuntimeException $e) {
            return $e;
        }
    }

    /**
     * @param list<\PhpParser\Node\Stmt> $ast
     */
    private static function validator(array $ast): ClosureConformanceValidator
    {
        return new ClosureConformanceValidator(TypeHierarchy::fromAstPerFile(['test.xphp' => $ast]));
    }

    /**
     * @return list<\PhpParser\Node\Stmt>
     */
    private static function parse(string $source): array
    {
        // The hierarchy needs App\Apple <: App\Fruit for the class-variance cases;
        // inject the declarations INLINE (no added newline) so every fixture shares
        // one `App` namespace + classes AND each literal keeps its original line.
        $prelude = 'namespace App; class Fruit {} class Apple extends Fruit {} ';
        $withPrelude = preg_replace('/^<\?php\s*/', "<?php {$prelude}", $source, 1);
        \assert(is_string($withPrelude));
        return self::parseRaw($withPrelude);
    }

    /**
     * @return list<\PhpParser\Node\Stmt>
     */
    private static function parseRaw(string $source): array
    {
        $parser = new XphpSourceParser((new ParserFactory())->createForHostVersion());
        return $parser->parse($source);
    }

    /**
     * @param list<\PhpParser\Node\Stmt> $ast
     * @return list<\PhpParser\Node\Param>
     */
    private static function firstFunctionParams(array $ast): array
    {
        $fn = (new \PhpParser\NodeFinder())->findFirstInstanceOf($ast, \PhpParser\Node\Stmt\Function_::class);
        \assert($fn instanceof \PhpParser\Node\Stmt\Function_);
        return array_values($fn->params);
    }
}
