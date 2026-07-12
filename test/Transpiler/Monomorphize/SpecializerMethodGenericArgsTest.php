<?php

declare(strict_types=1);

namespace XPHP\Transpiler\Monomorphize;

use PhpParser\Node\Arg;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Param;
use PhpParser\Node\Stmt\Function_;
use PhpParser\Node\Stmt\Return_;
use PhpParser\NodeFinder;
use PHPUnit\Framework\TestCase;

/**
 * When a generic template specializes, an inner variable-turbofish call
 * (`$inner::<S>(...)`) inside its body carries the enclosing type parameter in
 * ATTR_METHOD_GENERIC_ARGS. {@see Specializer::specializeFunction} must ground those
 * recorded type arguments (`S` → `int`) so the per-specialization closure-grounding pass
 * sees `$inner::<int>` rather than the abstract `$inner::<S>` — otherwise the inner
 * closure keeps a raw hint and fatals at runtime.
 *
 * This substitution is end-to-end inert on its own (nothing consumes the grounded args
 * until the re-entry pass lands); the assertion is on the recorded attribute directly.
 */
final class SpecializerMethodGenericArgsTest extends TestCase
{
    public function testInnerTurbofishArgsAreGroundedOnFunctionSpecialization(): void
    {
        $call = new FuncCall(new Variable('inner'), [new Arg(new Variable('v'))]);
        $call->setAttribute(
            XphpSourceParser::ATTR_METHOD_GENERIC_ARGS,
            [new TypeRef('S', [], isScalar: false, isTypeParam: true)],
        );
        $template = new Function_('relay', [
            'params' => [new Param(new Variable('v'))],
            'stmts'  => [new Return_($call)],
        ]);

        $specialized = (new Specializer())->specializeFunction(
            $template,
            ['S' => new TypeRef('int', [], isScalar: true)],
            'relay_T_int',
        );

        $calls = (new NodeFinder())->findInstanceOf($specialized, FuncCall::class);
        self::assertCount(1, $calls);
        $grounded = $calls[0]->getAttribute(XphpSourceParser::ATTR_METHOD_GENERIC_ARGS);
        self::assertIsArray($grounded);
        self::assertCount(1, $grounded);
        self::assertInstanceOf(TypeRef::class, $grounded[0]);
        self::assertSame('int', $grounded[0]->name);
        self::assertFalse($grounded[0]->isTypeParam, 'the grounded arg is concrete, not a type parameter');
    }

    public function testConcreteInnerTurbofishArgsSurviveSpecializationUnchanged(): void
    {
        // An inner turbofish already concrete (`$inner::<int>`) is not a type param of the
        // enclosing template, so substitution leaves it exactly as-is.
        $call = new FuncCall(new Variable('inner'), [new Arg(new Variable('v'))]);
        $call->setAttribute(
            XphpSourceParser::ATTR_METHOD_GENERIC_ARGS,
            [new TypeRef('int', [], isScalar: true)],
        );
        $template = new Function_('keep', [
            'params' => [new Param(new Variable('v'))],
            'stmts'  => [new Return_($call)],
        ]);

        $specialized = (new Specializer())->specializeFunction(
            $template,
            ['S' => new TypeRef('string', [], isScalar: true)],
            'keep_T_string',
        );

        $calls = (new NodeFinder())->findInstanceOf($specialized, FuncCall::class);
        $grounded = $calls[0]->getAttribute(XphpSourceParser::ATTR_METHOD_GENERIC_ARGS);
        self::assertIsArray($grounded);
        self::assertSame('int', $grounded[0]->name);
    }
}
