<?php

declare(strict_types=1);

namespace XPHP\Transpiler\Monomorphize;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use ValueError;

/**
 * {@see Substitution} — the immutable type-parameter → concrete-type map threaded through
 * specialization. Pins the factory shapes, the read accessors, and the load-bearing
 * merge precedence (a method-level parameter shadows a class-level one of the same name).
 */
final class SubstitutionTest extends TestCase
{
    public function testEmptyIsEmptyAndResolvesNothing(): void
    {
        $s = Substitution::empty();

        self::assertTrue($s->isEmpty());
        self::assertSame([], $s->names());
        self::assertFalse($s->has('T'));
        self::assertNull($s->get('T'));
        self::assertSame([], $s->toArray());
    }

    public function testOfWrapsAndReadsBack(): void
    {
        $int = self::ref('int');
        $s = Substitution::of(['T' => $int]);

        self::assertFalse($s->isEmpty());
        self::assertTrue($s->has('T'));
        self::assertSame($int, $s->get('T'));
        self::assertSame(['T'], $s->names());
        self::assertSame(['T' => $int], $s->toArray());
    }

    public function testFromParamsZipsInDeclarationOrder(): void
    {
        $params = [self::param('T'), self::param('E')];
        $int = self::ref('int');
        $book = self::ref('App\\Book');

        $s = Substitution::fromParams($params, [$int, $book]);

        self::assertSame($int, $s->get('T'));
        self::assertSame($book, $s->get('E'));
        self::assertSame(['T', 'E'], $s->names());
    }

    public function testFromParamsRejectsArityMismatch(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'Substitution::fromParams expects one argument per type parameter, got 1 for 2 parameter(s).',
        );

        Substitution::fromParams([self::param('T'), self::param('E')], [self::ref('int')]);
    }

    public function testFromNamesZipsNamesToArgs(): void
    {
        $int = self::ref('int');
        $s = Substitution::fromNames(['T'], [$int]);

        self::assertSame($int, $s->get('T'));
    }

    public function testFromNamesRaisesOnLengthMismatch(): void
    {
        // array_combine semantics — the compile-mode backstop the raw call sites relied on.
        $this->expectException(ValueError::class);

        Substitution::fromNames(['T', 'E'], [self::ref('int')]);
    }

    public function testWithOverridesArgumentWinsOnCollision(): void
    {
        $classLevel = Substitution::of(['T' => self::ref('int'), 'E' => self::ref('App\\Book')]);
        $methodLevel = Substitution::of(['T' => self::ref('string')]);

        $merged = $classLevel->withOverrides($methodLevel);

        // Method-level T shadows class-level T; the non-colliding class-level E survives.
        self::assertSame('string', $merged->get('T')?->name);
        self::assertSame('App\\Book', $merged->get('E')?->name);
    }

    public function testWithOverridesDoesNotMutateEitherOperand(): void
    {
        $a = Substitution::of(['T' => self::ref('int')]);
        $b = Substitution::of(['T' => self::ref('string')]);

        $a->withOverrides($b);

        self::assertSame('int', $a->get('T')?->name, 'left operand is unchanged');
        self::assertSame('string', $b->get('T')?->name, 'right operand is unchanged');
    }

    private static function ref(string $name): TypeRef
    {
        return new TypeRef($name);
    }

    private static function param(string $name): TypeParam
    {
        return new TypeParam($name);
    }
}
