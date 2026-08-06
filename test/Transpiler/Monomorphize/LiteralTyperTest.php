<?php

declare(strict_types=1);

namespace XPHP\Transpiler\Monomorphize;

use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\ConstFetch;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\Float_;
use PhpParser\Node\Scalar\Int_;
use PhpParser\Node\Scalar\String_;
use PHPUnit\Framework\TestCase;

final class LiteralTyperTest extends TestCase
{
    private LiteralTyper $typer;

    protected function setUp(): void
    {
        $this->typer = new LiteralTyper();
    }

    public function testIntLiteral(): void
    {
        $ref = $this->typer->typeOf(new Int_(5));
        self::assertNotNull($ref);
        self::assertSame('int', $ref->name);
        self::assertTrue($ref->isScalar);
    }

    public function testFloatLiteral(): void
    {
        $ref = $this->typer->typeOf(new Float_(1.5));
        self::assertNotNull($ref);
        self::assertSame('float', $ref->name);
        self::assertTrue($ref->isScalar);
    }

    public function testStringLiteral(): void
    {
        $ref = $this->typer->typeOf(new String_('x'));
        self::assertNotNull($ref);
        self::assertSame('string', $ref->name);
        self::assertTrue($ref->isScalar);
    }

    public function testTrueAndFalseAreBool(): void
    {
        foreach (['true', 'false', 'TRUE', 'False'] as $literal) {
            $ref = $this->typer->typeOf(new ConstFetch(new Name($literal)));
            self::assertNotNull($ref, $literal);
            self::assertSame('bool', $ref->name);
            self::assertTrue($ref->isScalar);
        }
    }

    public function testNullConstantIsNotTyped(): void
    {
        self::assertNull($this->typer->typeOf(new ConstFetch(new Name('null'))));
    }

    public function testOtherConstantIsNotTyped(): void
    {
        self::assertNull($this->typer->typeOf(new ConstFetch(new Name('PHP_EOL'))));
    }

    public function testArrayLiteral(): void
    {
        $ref = $this->typer->typeOf(new Array_([]));
        self::assertNotNull($ref);
        self::assertSame('array', $ref->name);
        // isScalar mirrors the parser, which types an `array` turbofish arg as scalar — inference
        // must produce the byte-identical TypeRef an explicit `::<array>` would.
        self::assertTrue($ref->isScalar);
    }

    public function testNewNonGeneric(): void
    {
        $class = new Name('Plastic');
        $class->setAttribute(XphpSourceParser::ATTR_RESOLVED_FQN, 'App\\Plastic');
        $ref = $this->typer->typeOf(new New_($class));
        self::assertNotNull($ref);
        self::assertSame('App\\Plastic', $ref->name);
        self::assertSame([], $ref->args);
    }

    public function testNewWithTurbofishArgs(): void
    {
        // new Box::<int>() — the parser has attached the resolved turbofish args.
        $class = new Name('Box');
        $class->setAttribute(XphpSourceParser::ATTR_RESOLVED_FQN, 'App\\Box');
        $class->setAttribute(XphpSourceParser::ATTR_GENERIC_ARGS, [new TypeRef('int', isScalar: true)]);
        $ref = $this->typer->typeOf(new New_($class));
        self::assertNotNull($ref);
        self::assertSame('App\\Box<int>', $ref->canonical());
    }

    public function testNewWithoutResolvedFqnFallsBackToSpelling(): void
    {
        $ref = $this->typer->typeOf(new New_(new Name('Whatever')));
        self::assertNotNull($ref);
        self::assertSame('Whatever', $ref->name);
    }

    public function testNewGenericWithAbstractArgIsNotTyped(): void
    {
        // new Box::<T>() inside a template: the turbofish is not concrete, so it is no basis for
        // inference.
        $class = new Name('Box');
        $class->setAttribute(XphpSourceParser::ATTR_RESOLVED_FQN, 'App\\Box');
        $class->setAttribute(XphpSourceParser::ATTR_GENERIC_ARGS, [new TypeRef('T', isTypeParam: true)]);
        self::assertNull($this->typer->typeOf(new New_($class)));
    }

    public function testNewDynamicClassIsNotTyped(): void
    {
        // new $class() — the class is an expression, not a name.
        self::assertNull($this->typer->typeOf(new New_(new Variable('class'))));
    }

    public function testUntypedExpressionYieldsNull(): void
    {
        self::assertNull($this->typer->typeOf(new Variable('x')));
    }
}
