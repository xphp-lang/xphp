<?php

declare(strict_types=1);

namespace XPHP\Transpiler\Monomorphize;

use PhpParser\Node;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Function_;
use PhpParser\NodeFinder;
use PhpParser\ParserFactory;
use PHPUnit\Framework\TestCase;
use XPHP\Diagnostics\DiagnosticCollector;

/**
 * Direct coverage for {@see ClosureConformanceValidator::checkCallArguments} — the
 * shared call-argument site every statically-resolvable call shape feeds. The method
 * lives on the outer (mutation-covered) class, so its argument→parameter pairing and
 * grounding are pinned here as unit tests, independent of any call-shape wiring.
 *
 * The pairing rules under test: positional by index, named by parameter name, a
 * spread stops positional pairing (tail gradual), a trailing variadic parameter
 * absorbs the positional tail, and a non-closure parameter / non-literal argument /
 * over-supplied position stays gradual.
 */
final class ClosureCallArgumentConformanceTest extends TestCase
{
    private const PREFIX = 'Closure literal does not conform to the declared `Closure(...)` type: ';

    public function testPositionalClosureArgProvableMismatchIsRejected(): void
    {
        $diagnostics = $this->checkCall(
            'function callee(Closure(int $x): int $fn): void {}',
            'callee(fn(string $x): int => 0);',
        );
        self::assertCount(1, $diagnostics);
        self::assertSame(ClosureConformanceValidator::CODE, $diagnostics[0]->code);
        self::assertSame(self::PREFIX . 'parameter 1: string is not wider than int', $diagnostics[0]->message);
    }

    public function testConformingPositionalClosureArgIsAccepted(): void
    {
        self::assertCount(0, $this->checkCall(
            'function callee(Closure(int $x): int $fn): void {}',
            'callee(fn(int $x): int => 0);',
        ));
    }

    public function testNonClosureParameterInEarlierSlotIsSkipped(): void
    {
        // The `int` param at slot 0 is not a Closure(...) target; the closure literal
        // at slot 1 is still paired and checked.
        $diagnostics = $this->checkCall(
            'function callee(int $n, Closure(int $x): int $fn): void {}',
            'callee(5, fn(string $x): int => 0);',
        );
        self::assertCount(1, $diagnostics);
        self::assertSame(self::PREFIX . 'parameter 1: string is not wider than int', $diagnostics[0]->message);
    }

    public function testNamedArgumentBindsByParameterNameNotPosition(): void
    {
        // The closure is passed as `fn:` out of positional order; it must bind to the
        // `$fn` parameter (a Closure target), not to `$n` at position 0.
        $diagnostics = $this->checkCall(
            'function callee(int $n, Closure(int $x): int $fn): void {}',
            'callee(fn: fn(string $x): int => 0, n: 5);',
        );
        self::assertCount(1, $diagnostics);
        self::assertSame(self::PREFIX . 'parameter 1: string is not wider than int', $diagnostics[0]->message);
    }

    public function testNamedArgumentForUnknownParameterIsGradual(): void
    {
        self::assertCount(0, $this->checkCall(
            'function callee(Closure(int $x): int $fn): void {}',
            'callee(nope: fn(string $x): int => 0);',
        ));
    }

    public function testArgumentsAfterANamedArgumentAreStillChecked(): void
    {
        // A benign named arg comes first; a later named closure is a provable
        // mismatch and must still be reached (the loop continues past each arg).
        $diagnostics = $this->checkCall(
            'function callee(int $n, Closure(int $x): int $fn): void {}',
            'callee(n: 5, fn: fn(string $x): int => 0);',
        );
        self::assertCount(1, $diagnostics);
        self::assertSame(self::PREFIX . 'parameter 1: string is not wider than int', $diagnostics[0]->message);
    }

    public function testNamedArgumentAfterASpreadIsStillChecked(): void
    {
        // A spread stops POSITIONAL pairing but a named argument binds by name
        // regardless of order, so a mismatched named closure after a spread is caught.
        $diagnostics = $this->checkCall(
            'function callee(int $n, Closure(int $x): int $fn): void {}',
            '$xs = []; callee(...$xs, fn: fn(string $x): int => 0);',
        );
        self::assertCount(1, $diagnostics);
        self::assertSame(self::PREFIX . 'parameter 1: string is not wider than int', $diagnostics[0]->message);
    }

    public function testNamedArgumentAfterASkippedPostSpreadPositionalIsStillChecked(): void
    {
        // spread, then a (skipped) positional, then a mismatched named closure — the
        // named one must still be reached even though the positional was passed over.
        $diagnostics = $this->checkCall(
            'function callee(Closure(int $x): int $a, Closure(int $x): int $b): void {}',
            '$xs = []; callee(...$xs, fn(int $x): int => 0, b: fn(string $x): int => 0);',
        );
        self::assertCount(1, $diagnostics);
        self::assertSame(self::PREFIX . 'parameter 1: string is not wider than int', $diagnostics[0]->message);
    }

    public function testStructuralArityMismatchIsReportedUnderTheFullCheck(): void
    {
        // A too-few-parameters literal is a STRUCTURAL mismatch (not a leaf-type one),
        // caught only by the full check() the default `groundedTypesOnly = false`
        // selects — a grounded-types-only run would miss it.
        $diagnostics = $this->checkCall(
            'function callee(Closure(int $a, int $b): void $fn): void {}',
            'callee(fn(int $a): void => null);',
        );
        self::assertCount(1, $diagnostics);
        self::assertSame(
            self::PREFIX . 'expects at least 2 parameter(s), candidate accepts at most 1',
            $diagnostics[0]->message,
        );
    }

    public function testTrailingVariadicParameterAbsorbsThePositionalTail(): void
    {
        // A variadic Closure(...) parameter absorbs every positional closure past the
        // fixed arity: both closures bind to it, and the second (string param) is a
        // provable mismatch. Source-level `Closure(...) ...$x` is a separate parser
        // limitation, so the variadic flag is set on the parsed param directly to
        // exercise the tail-absorb pairing.
        $ast = $this->parse(
            "<?php\nfunction callee(Closure(int \$x): int \$fn): void {}\n"
            . "callee(fn(int \$x): int => 0, fn(string \$x): int => 0);",
        );
        $params = $this->calleeParams($ast);
        $params[0]->variadic = true;

        $validator = new ClosureConformanceValidator(TypeHierarchy::fromAstPerFile(['test.xphp' => $ast]));
        $diagnostics = new DiagnosticCollector();
        $validator->checkCallArguments($params, $this->callArgs($ast), Substitution::empty(), new NamespaceContext(), 'test.xphp', $diagnostics);

        self::assertCount(1, $diagnostics->all());
        self::assertSame(self::PREFIX . 'parameter 1: string is not wider than int', $diagnostics->all()[0]->message);
    }

    public function testSpreadArgumentValueIsGradual(): void
    {
        // A spread's value is an array, never a closure literal — no crash, no check.
        self::assertCount(0, $this->checkCall(
            'function callee(Closure(int $x): int $fn): void {}',
            '$xs = []; callee(...$xs);',
        ));
    }

    public function testPositionalAfterSpreadIsNotMispaired(): void
    {
        // Once a spread is seen, positional slots rebind at runtime, so a later
        // positional literal must NOT be checked against a fixed slot (it would
        // false-reject). Without the stop-at-spread rule the `string` literal would
        // pair to `$a` (Closure(int):int) and be rejected — here it stays gradual.
        self::assertCount(0, $this->checkCall(
            'function callee(Closure(int $x): int $a, Closure(int $x): int $b): void {}',
            '$xs = []; callee(...$xs, fn(string $x): int => 0);',
        ));
    }

    public function testNonLiteralArgumentIsGradual(): void
    {
        self::assertCount(0, $this->checkCall(
            'function callee(Closure(int $x): int $fn): void {}',
            '$h = fn(string $x): int => 0; callee($h);',
        ));
    }

    public function testFirstClassCallableArgumentIsGradual(): void
    {
        // `strlen(...)` is a first-class-callable expression, not a closure literal.
        self::assertCount(0, $this->checkCall(
            'function callee(Closure(int $x): int $fn): void {}',
            'callee(strlen(...));',
        ));
    }

    public function testOverSuppliedPositionBeyondFixedArityIsGradual(): void
    {
        // The second argument has no parameter (arity is a PHP error elsewhere) and
        // the last param is NOT variadic, so it must NOT bind to the fixed slot: a
        // provably-mismatched extra closure stays gradual, not rejected.
        self::assertCount(0, $this->checkCall(
            'function callee(Closure(int $x): int $fn): void {}',
            'callee(fn(int $x): int => 0, fn(string $y): int => 1);',
        ));
    }

    public function testGroundingSubstitutionFlipsTheVerdict(): void
    {
        // The target is `Closure(T $x): int`. Grounded with T→int the `string`-param
        // literal is a provable mismatch; grounded with T→string the same literal
        // conforms — proving the subst actually grounds the target.
        $decl = 'class C<T> { public function callee(Closure(T $x): int $fn): void {} }';
        $call = 'callee(fn(string $x): int => 0);';

        $rejected = $this->checkCall($decl, $call, ['T' => new TypeRef('int', [], isScalar: true)]);
        self::assertCount(1, $rejected);
        self::assertSame(self::PREFIX . 'parameter 1: string is not wider than int', $rejected[0]->message);

        $accepted = $this->checkCall($decl, $call, ['T' => new TypeRef('string', [], isScalar: true)]);
        self::assertCount(0, $accepted);
    }

    public function testUngroundedTypeParameterLeafStaysGradual(): void
    {
        // With no substitution for T the target leaf stays abstract ⇒ gradual accept
        // (bucket 3: the enclosing template is not yet specialized).
        self::assertCount(0, $this->checkCall(
            'class C<T> { public function callee(Closure(T $x): int $fn): void {} }',
            'callee(fn(string $x): int => 0);',
        ));
    }

    // ---- Helpers ---------------------------------------------------------

    /**
     * Parse `<decl>` + `<call>`, extract the `callee` parameter list and the call's
     * argument list, and run {@see ClosureConformanceValidator::checkCallArguments}
     * against them with the given grounding substitution.
     *
     * @param array<string, TypeRef> $subst
     * @return list<\XPHP\Diagnostics\Diagnostic>
     */
    private function checkCall(string $decl, string $call, array $subst = []): array
    {
        $ast = $this->parse("<?php\n{$decl}\n{$call}");
        $validator = new ClosureConformanceValidator(TypeHierarchy::fromAstPerFile(['test.xphp' => $ast]));
        $diagnostics = new DiagnosticCollector();
        $validator->checkCallArguments(
            $this->calleeParams($ast),
            $this->callArgs($ast),
            Substitution::of($subst),
            new NamespaceContext(),
            'test.xphp',
            $diagnostics,
        );
        return $diagnostics->all();
    }

    /**
     * @param list<Node\Stmt> $ast
     * @return list<Node\Param>
     */
    private function calleeParams(array $ast): array
    {
        $node = (new NodeFinder())->findFirst(
            $ast,
            static fn (Node $n): bool =>
                ($n instanceof Function_ || $n instanceof ClassMethod) && $n->name->toString() === 'callee',
        );
        \assert($node instanceof Function_ || $node instanceof ClassMethod);
        return array_values($node->params);
    }

    /**
     * @param list<Node\Stmt> $ast
     * @return array<int, Node\Arg|Node\VariadicPlaceholder>
     */
    private function callArgs(array $ast): array
    {
        $call = (new NodeFinder())->findFirstInstanceOf($ast, FuncCall::class);
        \assert($call instanceof FuncCall);
        return $call->args;
    }

    /**
     * @return list<Node\Stmt>
     */
    private function parse(string $source): array
    {
        $parser = new XphpSourceParser((new ParserFactory())->createForHostVersion());
        return $parser->parse($source);
    }
}
