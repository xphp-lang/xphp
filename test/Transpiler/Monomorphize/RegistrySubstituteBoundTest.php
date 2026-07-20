<?php

declare(strict_types=1);

namespace XPHP\Transpiler\Monomorphize;

use PHPUnit\Framework\TestCase;

/**
 * Unit coverage for {@see Registry::substituteBound} — the pure primitive that grounds a
 * method-generic bound referencing an enclosing class type parameter (`<U : E>`) against the
 * receiver's concrete type arguments before the bound is checked. Leaves are rewritten via
 * Specializer::substituteTypeRef; compound bounds (intersection / union / DNF) recurse.
 */
final class RegistrySubstituteBoundTest extends TestCase
{
    /** @param array<string, TypeRef> $subst */
    private static function ground(BoundExpr $bound, array $subst): BoundExpr
    {
        return Registry::substituteBound($bound, Substitution::of($subst));
    }

    public function testLeafTypeParamIsGroundedToConcrete(): void
    {
        $result = self::ground(
            new BoundLeaf(new TypeRef('E', isTypeParam: true)),
            ['E' => new TypeRef('App\\Product')],
        );

        self::assertInstanceOf(BoundLeaf::class, $result);
        self::assertSame('App\\Product', $result->type->name);
        self::assertFalse($result->type->isTypeParam);
    }

    public function testLeafNotInSubstIsLeftIntact(): void
    {
        $result = self::ground(
            new BoundLeaf(new TypeRef('E', isTypeParam: true)),
            ['X' => new TypeRef('App\\Product')],
        );

        self::assertInstanceOf(BoundLeaf::class, $result);
        self::assertSame('E', $result->type->name);
        self::assertTrue($result->type->isTypeParam, 'an ungroundable leaf stays a type-param so the caller can detect it');
    }

    public function testConcreteLeafSharingASubstNameIsNotGrounded(): void
    {
        // Only type-param leaves ground: a concrete class that happens to be named `E`
        // (isTypeParam: false) is left intact even when the map has an `E` key.
        $result = self::ground(
            new BoundLeaf(new TypeRef('E')),
            ['E' => new TypeRef('App\\Product')],
        );

        self::assertInstanceOf(BoundLeaf::class, $result);
        self::assertSame('E', $result->type->name);
        self::assertFalse($result->type->isTypeParam);
    }

    public function testEmptySubstIsANoOp(): void
    {
        $result = self::ground(new BoundLeaf(new TypeRef('E', isTypeParam: true)), []);

        self::assertInstanceOf(BoundLeaf::class, $result);
        self::assertSame('E', $result->type->name);
        self::assertTrue($result->type->isTypeParam);
    }

    public function testNestedGenericLeafIsGroundedThroughArgs(): void
    {
        // F-bounded-shaped leaf `Comparable<E>` grounds its inner arg.
        $result = self::ground(
            new BoundLeaf(new TypeRef('Comparable', [new TypeRef('E', isTypeParam: true)])),
            ['E' => new TypeRef('App\\Product')],
        );

        self::assertInstanceOf(BoundLeaf::class, $result);
        self::assertSame('Comparable', $result->type->name);
        self::assertCount(1, $result->type->args);
        self::assertSame('App\\Product', $result->type->args[0]->name);
    }

    public function testIntersectionRecursesOverEveryOperand(): void
    {
        $result = self::ground(
            new BoundIntersection(
                new BoundLeaf(new TypeRef('App\\Named')),
                new BoundLeaf(new TypeRef('E', isTypeParam: true)),
            ),
            ['E' => new TypeRef('App\\Product')],
        );

        self::assertInstanceOf(BoundIntersection::class, $result);
        self::assertCount(2, $result->operands);
        self::assertInstanceOf(BoundLeaf::class, $result->operands[0]);
        self::assertSame('App\\Named', $result->operands[0]->type->name);
        self::assertInstanceOf(BoundLeaf::class, $result->operands[1]);
        self::assertSame('App\\Product', $result->operands[1]->type->name);
    }

    public function testUnionRecursesOverEveryOperand(): void
    {
        $result = self::ground(
            new BoundUnion(
                new BoundLeaf(new TypeRef('E', isTypeParam: true)),
                new BoundLeaf(new TypeRef('App\\Fallback')),
            ),
            ['E' => new TypeRef('App\\Product')],
        );

        self::assertInstanceOf(BoundUnion::class, $result);
        self::assertCount(2, $result->operands);
        self::assertInstanceOf(BoundLeaf::class, $result->operands[0]);
        self::assertSame('App\\Product', $result->operands[0]->type->name);
        self::assertInstanceOf(BoundLeaf::class, $result->operands[1]);
        self::assertSame('App\\Fallback', $result->operands[1]->type->name);
    }

    public function testDnfGroundsLeafNestedInsideUnionOfIntersections(): void
    {
        // (E & A) | B  — the type-param leaf is two levels deep; recursion must reach it.
        $result = self::ground(
            new BoundUnion(
                new BoundIntersection(
                    new BoundLeaf(new TypeRef('E', isTypeParam: true)),
                    new BoundLeaf(new TypeRef('App\\A')),
                ),
                new BoundLeaf(new TypeRef('App\\B')),
            ),
            ['E' => new TypeRef('App\\Product')],
        );

        self::assertInstanceOf(BoundUnion::class, $result);
        $inner = $result->operands[0];
        self::assertInstanceOf(BoundIntersection::class, $inner);
        self::assertInstanceOf(BoundLeaf::class, $inner->operands[0]);
        self::assertSame('App\\Product', $inner->operands[0]->type->name);
        self::assertInstanceOf(BoundLeaf::class, $inner->operands[1]);
        self::assertSame('App\\A', $inner->operands[1]->type->name);
        self::assertInstanceOf(BoundLeaf::class, $result->operands[1]);
        self::assertSame('App\\B', $result->operands[1]->type->name);
    }
}
