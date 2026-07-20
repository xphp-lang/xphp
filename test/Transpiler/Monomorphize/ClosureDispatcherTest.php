<?php

declare(strict_types=1);

namespace XPHP\Transpiler\Monomorphize;

use PhpParser\Node\Expr\Assign;
use PhpParser\Node\Expr\Closure;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\Match_;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Name\FullyQualified;
use PhpParser\Node\Param;
use PhpParser\Node\Stmt\Function_;
use PhpParser\Node\Stmt\Return_;
use PHPUnit\Framework\TestCase;
use ReflectionFunction;
use RuntimeException;

/**
 * Unit tests for the dispatcher infrastructure -- the AST shape it
 * emits, the tag mechanic, and dedup behavior. Integration-level
 * coverage (end-to-end compile from source) lives in
 * `XphpSourceParserTest::testGenericClosure*` and the new
 * `ClosureDispatcherIntegrationTest`.
 */
final class ClosureDispatcherTest extends TestCase
{
    public function testSingleArgTupleProducesOneMatchArm(): void
    {
        $result = $this->dispatchSimple([[new TypeRef('int')]]);
        $this->assertCount(1, $result['declarations']);
        $match = $this->getDispatcherMatch($result['assignment']);
        // Match arms: 1 specialization arm + 1 default-throw arm.
        $this->assertCount(2, $match->arms);
    }

    public function testMultiArgTupleProducesOneArmPerTuple(): void
    {
        $result = $this->dispatchSimple([
            [new TypeRef('int')],
            [new TypeRef('string')],
        ]);
        $this->assertCount(2, $result['declarations']);
        $match = $this->getDispatcherMatch($result['assignment']);
        $this->assertCount(3, $match->arms);     // 2 spec + 1 default
    }

    public function testDuplicateArgTuplesAreDeduped(): void
    {
        // Order matters: a duplicate in the MIDDLE of the list must be
        // skipped (not break the iteration). Pins that the dedupe is a
        // `continue`, not a `break`.
        $result = $this->dispatchSimple([
            [new TypeRef('int')],
            [new TypeRef('int')],
            [new TypeRef('string')],
        ]);
        $this->assertCount(2, $result['declarations']);
        $match = $this->getDispatcherMatch($result['assignment']);
        $this->assertCount(3, $match->arms);
    }

    public function testTagDerivationMatchesCanonicalHash(): void
    {
        $args = [new TypeRef('int')];
        $expected = 'T_' . Registry::canonicalHash($args, 16);
        $this->assertSame($expected, ClosureDispatcher::tagFor($args, 16));
    }

    public function testDispatcherClosureDropsTheTemplateGenericParamsMarker(): void
    {
        // The emitted dispatcher is a grounded, already-specialized artifact: it replaces the
        // generic closure with per-tag routing. It must NOT carry the template's
        // ATTR_METHOD_GENERIC_PARAMS marker, so that a surviving marker stays a sound leak
        // signal for the emit-time backstop ({@see GenericMarkerLeakGuard}). Pin both halves:
        // the dispatcher's copy is cleared, and the template's own marker is left untouched
        // (the copy is by value via getAttributes(), so the other passes still see it).
        $template = $this->buildTemplate();
        $params = $this->buildTypeParams();
        $template->setAttribute(XphpSourceParser::ATTR_METHOD_GENERIC_PARAMS, $params);

        $result = (new ClosureDispatcher())->dispatch(
            $template,
            [[new TypeRef('int')]],
            $params,
            'pair',
            'App',
            16,
        );

        $dispatcher = $result['assignment']->expr;
        $this->assertInstanceOf(Closure::class, $dispatcher);
        $this->assertNull(
            $dispatcher->getAttribute(XphpSourceParser::ATTR_METHOD_GENERIC_PARAMS),
            'the emitted dispatcher must not read as an un-specialized generic',
        );
        $this->assertSame(
            $params,
            $template->getAttribute(XphpSourceParser::ATTR_METHOD_GENERIC_PARAMS),
            'clearing the dispatcher copy must not touch the template',
        );
    }

    public function testDispatcherClosureSignatureIsStringTagAndVariadicArgs(): void
    {
        $result = $this->dispatchSimple([[new TypeRef('int')]]);
        $closure = $result['assignment']->expr;
        $this->assertInstanceOf(Closure::class, $closure);
        $this->assertCount(2, $closure->params);
        $this->assertSame('string', $closure->params[0]->type->name);
        $this->assertSame(ClosureDispatcher::TAG_PARAM_NAME, $closure->params[0]->var->name);
        $this->assertSame('mixed', $closure->params[1]->type->name);
        $this->assertSame(ClosureDispatcher::ARGS_PARAM_NAME, $closure->params[1]->var->name);
        $this->assertTrue($closure->params[1]->variadic);
        $this->assertSame('mixed', $closure->returnType->name);
    }

    public function testDispatcherDefaultArmThrowsUnknownTagAtRuntime(): void
    {
        // Eval the emitted dispatcher and call it with a bogus tag.
        $dispatcher = $this->materializeDispatcher([[new TypeRef('int')]]);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Unknown generic specialization tag: T_bogus');
        $dispatcher('T_bogus');
    }

    public function testDispatcherRoutesViaMatchToRightSpecialization(): void
    {
        // Register fake specialized functions matching the tags the
        // dispatcher will compute, then assert the dispatcher routes
        // each tag to the right function.
        //
        // Note: the `eval()` below defines these functions in the
        // XPHP\Transpiler\Monomorphize namespace and they persist for
        // the rest of the suite. The tags are content-hashed from the
        // arg-tuples, so the names are deterministic and re-running this
        // test (or other tests using the same tuples) reuses the same
        // function definitions -- the `function_exists` guard makes this
        // idempotent.
        $intArgs = [new TypeRef('int')];
        $strArgs = [new TypeRef('string')];
        $intTag = ClosureDispatcher::tagFor($intArgs, 16);
        $strTag = ClosureDispatcher::tagFor($strArgs, 16);
        $intFn = 'XPHP\\Transpiler\\Monomorphize\\closure_x_' . $intTag;
        $strFn = 'XPHP\\Transpiler\\Monomorphize\\closure_x_' . $strTag;
        if (!function_exists($intFn)) {
            eval("namespace XPHP\\Transpiler\\Monomorphize; function closure_x_{$intTag}(...\$a) { return 'int:' . \$a[0]; }");
        }
        if (!function_exists($strFn)) {
            eval("namespace XPHP\\Transpiler\\Monomorphize; function closure_x_{$strTag}(...\$a) { return 'str:' . \$a[0]; }");
        }
        $dispatcher = $this->materializeDispatcher(
            [$intArgs, $strArgs],
            varName: 'x',
            namespace: 'XPHP\\Transpiler\\Monomorphize',
            hashLength: 16,
        );
        $this->assertSame('int:42', $dispatcher($intTag, 42));
        $this->assertSame('str:hi', $dispatcher($strTag, 'hi'));
    }

    public function testSpecializedDeclarationsAreTopLevelFunctionNodes(): void
    {
        $result = $this->dispatchSimple([
            [new TypeRef('int')],
            [new TypeRef('string')],
        ]);
        foreach ($result['declarations'] as $decl) {
            $this->assertInstanceOf(Function_::class, $decl);
            $this->assertStringStartsWith('closure_pair_T_', $decl->name->toString());
        }
    }

    public function testSpecializedDeclarationsHaveNoResidualGenericParamsAttr(): void
    {
        // Pins the second-pass-safety invariant from the Round 8 plan
        // review: re-running process() over the same AST must not try
        // to re-specialize these.
        $result = $this->dispatchSimple([[new TypeRef('int')]]);
        foreach ($result['declarations'] as $decl) {
            $this->assertNull($decl->getAttribute(XphpSourceParser::ATTR_METHOD_GENERIC_PARAMS));
        }
    }

    public function testEmptyArgSetsProducesNoSpecializationsAndAnUnreachableMatch(): void
    {
        // Edge case: dispatch() with no arg sets emits a dispatcher with
        // ONLY the default-throw arm. The compiler's finalize phase short-
        // circuits on empty argSets (so the original Assign is left intact),
        // but the dispatcher contract itself remains consistent here.
        $result = $this->dispatchSimple([]);
        $this->assertSame([], $result['declarations']);
        $match = $this->getDispatcherMatch($result['assignment']);
        $this->assertCount(1, $match->arms);     // only default
    }

    // ----- helpers ---------------------------------------------------------

    /**
     * @param list<list<TypeRef>> $argSets
     * @return array{declarations: list<Function_>, assignment: Assign}
     */
    private function dispatchSimple(
        array $argSets,
        string $varName = 'pair',
        string $namespace = 'App',
        int $hashLength = 16,
    ): array {
        $dispatcher = new ClosureDispatcher();
        return $dispatcher->dispatch(
            $this->buildTemplate(),
            $argSets,
            $this->buildTypeParams(),
            $varName,
            $namespace,
            $hashLength,
        );
    }

    private function buildTemplate(): Closure
    {
        // Single-type-param closure so each test's arg-tuple is just `[someType]`.
        return new Closure([
            'params' => [
                new Param(new \PhpParser\Node\Expr\Variable('x'), type: new Name(['T'])),
            ],
            'returnType' => new Name(['T']),
            'stmts' => [new Return_(new \PhpParser\Node\Expr\Variable('x'))],
        ]);
    }

    /**
     * @return list<TypeParam>
     */
    private function buildTypeParams(): array
    {
        return [new TypeParam('T')];
    }

    private function getDispatcherMatch(Assign $assignment): Match_
    {
        $this->assertInstanceOf(Closure::class, $assignment->expr);
        $stmts = $assignment->expr->stmts;
        $this->assertCount(1, $stmts);
        $this->assertInstanceOf(Return_::class, $stmts[0]);
        $this->assertInstanceOf(Match_::class, $stmts[0]->expr);
        return $stmts[0]->expr;
    }

    /**
     * Eval the dispatcher's pretty-printed source and return the resulting
     * runtime closure so we can call it. The return-type is the *runtime*
     * `\Closure`, not `PhpParser\Node\Expr\Closure`.
     *
     * @param list<list<TypeRef>> $argSets
     */
    private function materializeDispatcher(
        array $argSets,
        string $varName = 'pair',
        string $namespace = 'App',
        int $hashLength = 16,
    ): \Closure {
        $result = $this->dispatchSimple($argSets, $varName, $namespace, $hashLength);
        $printer = new \PhpParser\PrettyPrinter\Standard();
        $expr = $result['assignment']->expr;
        $code = 'return ' . $printer->prettyPrintExpr($expr) . ';';
        return eval($code);
    }
}
