<?php

declare(strict_types=1);

namespace XPHP\Transpiler\Monomorphize;

use PhpParser\Node\Arg;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\NullsafeMethodCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Param;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Function_;
use PhpParser\Node\Stmt\Return_;
use PhpParser\NodeFinder;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * When a generic template specializes, a method-generic turbofish call inside its body
 * carries the enclosing type parameter in ATTR_METHOD_GENERIC_ARGS — a variable turbofish
 * (`$inner::<S>(...)`, a FuncCall), a static call (`Maker::wrap::<T>`), an instance call
 * (`$this->m::<T>`), or a nullsafe call (`$obj?->m::<T>`). The Specializer's shared
 * substituting visitor must ground the recorded type arguments (`T` → `int`) on every one
 * of those call-node kinds so a downstream grounding pass sees `::<int>` rather than the
 * abstract `::<T>` — a call kind the substitution skips keeps a raw type-param ref and is
 * rejected by the emit leak guard.
 *
 * This substitution is end-to-end inert on its own (nothing consumes the grounded args
 * until a grounding pass dispatches them); the assertion is on the recorded attribute
 * directly.
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
            Substitution::of(['S' => new TypeRef('int', [], isScalar: true)]),
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
            Substitution::of(['S' => new TypeRef('string', [], isScalar: true)]),
            'keep_T_string',
        );

        $calls = (new NodeFinder())->findInstanceOf($specialized, FuncCall::class);
        $grounded = $calls[0]->getAttribute(XphpSourceParser::ATTR_METHOD_GENERIC_ARGS);
        self::assertIsArray($grounded);
        self::assertSame('int', $grounded[0]->name);
    }

    /**
     * @return iterable<string, array{Expr, class-string<Expr>}>
     */
    public static function methodTurbofishCallKinds(): iterable
    {
        $args = [new Arg(new Variable('v'))];
        yield 'static call (Maker::wrap::<T>)' => [
            new StaticCall(new Name('Maker'), new Identifier('wrap'), $args),
            StaticCall::class,
        ];
        yield 'instance call ($this->m::<T>)' => [
            new MethodCall(new Variable('this'), new Identifier('m'), $args),
            MethodCall::class,
        ];
        yield 'nullsafe call ($obj?->m::<T>)' => [
            new NullsafeMethodCall(new Variable('obj'), new Identifier('m'), $args),
            NullsafeMethodCall::class,
        ];
    }

    /**
     * @param class-string<Expr> $nodeClass
     */
    #[DataProvider('methodTurbofishCallKinds')]
    public function testMethodTurbofishArgsAreGroundedOnEveryCallNodeKind(Expr $call, string $nodeClass): void
    {
        $call->setAttribute(
            XphpSourceParser::ATTR_METHOD_GENERIC_ARGS,
            [new TypeRef('T', [], isScalar: false, isTypeParam: true)],
        );
        $template = new ClassMethod('make', [
            'params' => [new Param(new Variable('v'))],
            'stmts'  => [new Return_($call)],
        ]);

        $specialized = (new Specializer())->specializeMethod(
            $template,
            Substitution::of(['T' => new TypeRef('int', [], isScalar: true)]),
            'make_T_int',
        );

        $calls = (new NodeFinder())->findInstanceOf($specialized, $nodeClass);
        self::assertCount(1, $calls);
        $grounded = $calls[0]->getAttribute(XphpSourceParser::ATTR_METHOD_GENERIC_ARGS);
        self::assertIsArray($grounded);
        self::assertCount(1, $grounded);
        self::assertInstanceOf(TypeRef::class, $grounded[0]);
        self::assertSame('int', $grounded[0]->name);
        self::assertFalse($grounded[0]->isTypeParam, 'the grounded arg is concrete, not a type parameter');
    }

    #[DataProvider('methodTurbofishCallKinds')]
    public function testNestedTurbofishArgsGroundOnEveryCallNodeKind(Expr $call, string $nodeClass): void
    {
        // A nested-generic turbofish arg (`Maker::wrap::<Box<T>>`) grounds its leaves in
        // place: the outer ref stays `Box`, the inner `T` leaf becomes concrete.
        $call->setAttribute(
            XphpSourceParser::ATTR_METHOD_GENERIC_ARGS,
            [new TypeRef('Box', [new TypeRef('T', [], isScalar: false, isTypeParam: true)], isScalar: false)],
        );
        $template = new ClassMethod('make', [
            'params' => [new Param(new Variable('v'))],
            'stmts'  => [new Return_($call)],
        ]);

        $specialized = (new Specializer())->specializeMethod(
            $template,
            Substitution::of(['T' => new TypeRef('string', [], isScalar: true)]),
            'make_T_string',
        );

        $grounded = (new NodeFinder())->findInstanceOf($specialized, $nodeClass)[0]
            ->getAttribute(XphpSourceParser::ATTR_METHOD_GENERIC_ARGS);
        self::assertIsArray($grounded);
        self::assertSame('Box', $grounded[0]->name);
        self::assertSame('string', $grounded[0]->args[0]->name);
        self::assertFalse($grounded[0]->args[0]->isTypeParam);
        self::assertTrue($grounded[0]->isConcrete(), 'the whole nested ref is concrete after grounding');
    }
}
