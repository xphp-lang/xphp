<?php

declare(strict_types=1);

namespace XPHP\Transpiler\Monomorphize;

use PhpParser\Node\Arg;
use PhpParser\Node\Expr\ArrowFunction;
use PhpParser\Node\Expr\Closure;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\NullsafeMethodCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\Int_;
use PhpParser\Node\Stmt\Expression;
use PhpParser\Node\Stmt\Return_;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * {@see GenericMarkerLeakGuard} — the last-resort backstop over emitted specialized output. A
 * generic marker consumed on every grounded site, so a survivor is a leak that would become a
 * runtime `TypeError`; the guard must throw on each leaking shape and stay silent on a clean body.
 * Pure outer-class code (Infection-visible), pinned directly here alongside the behavioral fixtures.
 */
final class GenericMarkerLeakGuardTest extends TestCase
{
    private const ARGS_MARKER = XphpSourceParser::ATTR_METHOD_GENERIC_ARGS;
    private const PARAMS_MARKER = XphpSourceParser::ATTR_METHOD_GENERIC_PARAMS;

    public function testThrowsOnFuncCallCarryingASurvivingTurbofishMarker(): void
    {
        $call = new FuncCall(new Variable('inner'), [new Arg(new Int_(3))]);
        $call->setAttribute(self::ARGS_MARKER, [new Identifier('int')]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(GenericMarkerLeakGuard::CODE);
        GenericMarkerLeakGuard::assertNoLeak(new Return_($call), 'relay_T_x');
    }

    public function testThrowsOnMethodCallCarryingASurvivingTurbofishMarker(): void
    {
        $call = new MethodCall(new Variable('this'), new Identifier('gen'), [new Arg(new Int_(3))]);
        $call->setAttribute(self::ARGS_MARKER, [new Identifier('int')]);

        $this->expectException(RuntimeException::class);
        GenericMarkerLeakGuard::assertNoLeak($call, 'box');
    }

    public function testThrowsOnStaticCallCarryingASurvivingTurbofishMarker(): void
    {
        $call = new StaticCall(new Name('self'), new Identifier('gen'), [new Arg(new Int_(3))]);
        $call->setAttribute(self::ARGS_MARKER, [new Identifier('int')]);

        $this->expectException(RuntimeException::class);
        GenericMarkerLeakGuard::assertNoLeak($call, 'box');
    }

    public function testThrowsOnNullsafeMethodCallCarryingASurvivingTurbofishMarker(): void
    {
        $call = new NullsafeMethodCall(new Variable('obj'), new Identifier('gen'), [new Arg(new Int_(3))]);
        $call->setAttribute(self::ARGS_MARKER, [new Identifier('int')]);

        $this->expectException(RuntimeException::class);
        GenericMarkerLeakGuard::assertNoLeak($call, 'box');
    }

    public function testThrowsOnClosureCarryingASurvivingGenericParamsMarker(): void
    {
        $closure = new Closure(['stmts' => []]);
        $closure->setAttribute(self::PARAMS_MARKER, [new Identifier('I')]);

        $this->expectException(RuntimeException::class);
        GenericMarkerLeakGuard::assertNoLeak($closure, 'relay');
    }

    public function testThrowsOnArrowFunctionCarryingASurvivingGenericParamsMarker(): void
    {
        $arrow = new ArrowFunction(['expr' => new Variable('x')]);
        $arrow->setAttribute(self::PARAMS_MARKER, [new Identifier('U')]);

        $this->expectException(RuntimeException::class);
        GenericMarkerLeakGuard::assertNoLeak($arrow, 'outer');
    }

    public function testTheThrownMessageNamesTheLabelTheLineAndTheCode(): void
    {
        $call = new FuncCall(new Variable('inner'), [], ['startLine' => 42]);
        $call->setAttribute(self::ARGS_MARKER, [new Identifier('int')]);

        try {
            GenericMarkerLeakGuard::assertNoLeak($call, 'relay_T_deadbeef');
            self::fail('expected a leak to throw');
        } catch (RuntimeException $e) {
            self::assertStringContainsString('relay_T_deadbeef', $e->getMessage());
            self::assertStringContainsString('42', $e->getMessage());
            self::assertStringContainsString(GenericMarkerLeakGuard::CODE, $e->getMessage());
        }
    }

    public function testTheParamsMarkerSignalIsScopedToClosuresAndArrowsOnly(): void
    {
        // The `ATTR_METHOD_GENERIC_PARAMS` signal is a closure/arrow-only marker; the same
        // attribute set on any other node type is not a leak and must be ignored (a plain
        // `Variable` never declares type parameters). Pins the instanceof scoping precisely.
        $decoy = new Variable('x');
        $decoy->setAttribute(self::PARAMS_MARKER, [new Identifier('U')]);

        GenericMarkerLeakGuard::assertNoLeak(new Expression($decoy), 'decoy');

        $this->addToAssertionCount(1);
    }

    public function testStaysSilentOnAFullyRewrittenBody(): void
    {
        // A successful turbofish rewrite nulls the marker; a grounded closure loses its params
        // marker. A body where every marker is absent (or explicitly nulled) must not throw.
        $rewritten = new FuncCall(new Variable('inner'), [new Arg(new Int_(3))]);
        $rewritten->setAttribute(self::ARGS_MARKER, null);
        $plainCall = new StaticCall(new Name('self'), new Identifier('gen'), [new Arg(new Int_(3))]);
        $cleanClosure = new Closure(['stmts' => []]);

        GenericMarkerLeakGuard::assertNoLeak(
            [new Return_($rewritten), new Expression($plainCall), new Expression($cleanClosure)],
            'clean_body',
        );

        $this->addToAssertionCount(1);
    }

    public function testAcceptsAListOfNodesAndScansEach(): void
    {
        $clean = new Expression(new FuncCall(new Variable('a')));
        $leaking = new StaticCall(new Name('self'), new Identifier('gen'));
        $leaking->setAttribute(self::ARGS_MARKER, [new Identifier('int')]);

        $this->expectException(RuntimeException::class);
        GenericMarkerLeakGuard::assertNoLeak([$clean, new Expression($leaking)], 'list');
    }

    public function testFindLeakReturnsTheLeakingNodeAndNullOnCleanInput(): void
    {
        // The check-mode drain consumes the scan directly (degrading to a diagnostic
        // instead of a throw), so the found node — not just the boolean outcome — is API.
        $leaking = new StaticCall(new Name('self'), new Identifier('gen'));
        $leaking->setAttribute(self::ARGS_MARKER, [new Identifier('int')]);

        self::assertSame($leaking, GenericMarkerLeakGuard::findLeak(new Expression($leaking)));
        self::assertNull(GenericMarkerLeakGuard::findLeak(new Expression(new FuncCall(new Variable('a')))));
    }

    public function testFindLeakCanExcludeTheClosureTemplateArm(): void
    {
        // With $includeClosureTemplates=false only call-node markers count: an
        // un-specialized closure template is reported elsewhere (source seam / orphan
        // check), so the check-mode drain must not re-flag the template node itself.
        $closure = new Closure(['stmts' => []]);
        $closure->setAttribute(self::PARAMS_MARKER, [new Identifier('I')]);
        $body = new Expression($closure);

        self::assertSame($closure, GenericMarkerLeakGuard::findLeak($body));
        self::assertNull(GenericMarkerLeakGuard::findLeak($body, includeClosureTemplates: false));

        // A call-node marker still counts with the closure arm off.
        $call = new FuncCall(new Variable('f'));
        $call->setAttribute(self::ARGS_MARKER, [new Identifier('int')]);
        self::assertSame($call, GenericMarkerLeakGuard::findLeak(new Expression($call), includeClosureTemplates: false));
    }

    public function testLeakMessageNamesTheLabelTheLineAndTheCode(): void
    {
        $call = new FuncCall(new Variable('inner'), [], ['startLine' => 7]);
        $call->setAttribute(self::ARGS_MARKER, [new Identifier('int')]);

        $message = GenericMarkerLeakGuard::leakMessage($call, 'wrap_T_cafe');

        self::assertStringContainsString('wrap_T_cafe', $message);
        self::assertStringContainsString('7', $message);
        self::assertStringContainsString(GenericMarkerLeakGuard::CODE, $message);
    }
}
