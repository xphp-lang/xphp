<?php

declare(strict_types=1);

namespace XPHP\Transpiler\Monomorphize;

use PHPUnit\Framework\TestCase;

/**
 * {@see Specializer::substituteClosureSignature} grounds a `Closure(...)` target's
 * type-parameter leaves with their concrete types during specialization, while
 * carrying every non-type-parameter leaf, structural flag, and gradual raw leaf
 * through untouched. This is what turns `Closure(T $x): T` into
 * `Closure(int $x): int` for the post-specialization conformance pass.
 */
final class SpecializerClosureSignatureTest extends TestCase
{
    public function testTypeParameterLeavesInParametersAndReturnAreSubstituted(): void
    {
        $sig = new ClosureSignature(
            [new ClosureSignatureParam(new SigTypeRef(self::typeParam('T')))],
            new SigTypeRef(self::typeParam('T')),
        );

        $grounded = Specializer::substituteClosureSignature($sig, ['T' => self::scalar('int')]);

        self::assertSame('int', self::leafName($grounded->params[0]->type));
        self::assertSame('int', self::leafName($grounded->return));
    }

    public function testNonTypeParameterLeavesAreUnchanged(): void
    {
        $sig = new ClosureSignature(
            [new ClosureSignatureParam(new SigTypeRef(self::scalar('string')))],
            new SigTypeRef(new TypeRef('App\\Fruit')),
        );

        $grounded = Specializer::substituteClosureSignature($sig, ['T' => self::scalar('int')]);

        self::assertSame('string', self::leafName($grounded->params[0]->type));
        self::assertSame('App\\Fruit', self::leafName($grounded->return));
    }

    public function testNestedClosureLeafIsSubstitutedRecursively(): void
    {
        // A parameter whose own type is `Closure(T): T` — the inner type parameter
        // must ground too.
        $inner = new ClosureSignature(
            [new ClosureSignatureParam(new SigTypeRef(self::typeParam('T')))],
            new SigTypeRef(self::typeParam('T')),
        );
        $sig = new ClosureSignature([new ClosureSignatureParam(new SigClosure($inner))], null);

        $grounded = Specializer::substituteClosureSignature($sig, ['T' => self::scalar('int')]);

        $outerParam = $grounded->params[0]->type;
        self::assertInstanceOf(SigClosure::class, $outerParam);
        self::assertSame('int', self::leafName($outerParam->signature->params[0]->type));
        self::assertSame('int', self::leafName($outerParam->signature->return));
    }

    public function testTypeParameterInsideUnionMemberIsSubstituted(): void
    {
        // Closure(T|int $x) grounds the T member alongside the concrete int member.
        $sig = new ClosureSignature(
            [new ClosureSignatureParam(new SigUnion([
                new SigTypeRef(self::typeParam('T')),
                new SigTypeRef(self::scalar('int')),
            ]))],
            null,
        );

        $grounded = Specializer::substituteClosureSignature($sig, ['T' => new TypeRef('App\\Apple')]);

        $param = $grounded->params[0]->type;
        self::assertInstanceOf(SigUnion::class, $param);
        self::assertSame('App\\Apple', self::leafName($param->members[0]));
        self::assertSame('int', self::leafName($param->members[1]));
    }

    public function testTypeParameterInsideIntersectionMemberIsSubstituted(): void
    {
        $sig = new ClosureSignature([], new SigIntersection([
            new SigTypeRef(self::typeParam('T')),
            new SigTypeRef(new TypeRef('App\\Countable')),
        ]));

        $grounded = Specializer::substituteClosureSignature($sig, ['T' => new TypeRef('App\\Apple')]);

        $ret = $grounded->return;
        self::assertInstanceOf(SigIntersection::class, $ret);
        self::assertSame('App\\Apple', self::leafName($ret->members[0]));
        self::assertSame('App\\Countable', self::leafName($ret->members[1]));
    }

    public function testRawLeafIsCarriedThroughUnchanged(): void
    {
        $raw = new SigRaw('int|string');
        $sig = new ClosureSignature([new ClosureSignatureParam($raw)], null);

        $grounded = Specializer::substituteClosureSignature($sig, ['T' => self::scalar('int')]);

        self::assertSame($raw, $grounded->params[0]->type, 'a gradual raw leaf is untouched');
    }

    public function testStructuralFlagsAndNullabilityArePreserved(): void
    {
        $sig = new ClosureSignature(
            [
                new ClosureSignatureParam(new SigTypeRef(self::typeParam('T')), byRef: true),
                new ClosureSignatureParam(new SigTypeRef(self::scalar('int')), variadic: true),
            ],
            null,
            nullable: true,
        );

        $grounded = Specializer::substituteClosureSignature($sig, ['T' => self::scalar('int')]);

        self::assertTrue($grounded->params[0]->byRef);
        self::assertTrue($grounded->params[1]->variadic);
        self::assertTrue($grounded->nullable);
        self::assertNull($grounded->return, 'an absent return stays absent');
    }

    // ---- closureSignatureGroundsAny (the grounded-flag predicate) --------

    public function testGroundsAnyDetectsAGroundedParameterLeaf(): void
    {
        $sig = new ClosureSignature(
            [new ClosureSignatureParam(new SigTypeRef(self::typeParam('E')))],
            new SigTypeRef(self::scalar('string')),
        );

        self::assertTrue(Specializer::closureSignatureGroundsAny($sig, ['E' => self::scalar('int')]));
    }

    public function testGroundsAnyDetectsAGroundedReturnLeafWhenParametersAreConcrete(): void
    {
        // Return-only grounding: the parameter is concrete, only the return references E.
        $sig = new ClosureSignature(
            [new ClosureSignatureParam(new SigTypeRef(self::scalar('int')))],
            new SigTypeRef(self::typeParam('E')),
        );

        self::assertTrue(Specializer::closureSignatureGroundsAny($sig, ['E' => self::scalar('int')]));
    }

    public function testGroundsAnyIsFalseForAFullyConcreteSignature(): void
    {
        $sig = new ClosureSignature(
            [new ClosureSignatureParam(new SigTypeRef(self::scalar('int')))],
            new SigTypeRef(self::scalar('string')),
        );

        self::assertFalse(Specializer::closureSignatureGroundsAny($sig, ['E' => self::scalar('int')]));
    }

    public function testGroundsAnyIsFalseForATypeParameterAbsentFromTheSubstitution(): void
    {
        // A type-parameter leaf whose name is NOT a substitution key is not grounded by
        // this substitution — the predicate must require BOTH isTypeParam AND membership.
        $sig = new ClosureSignature(
            [new ClosureSignatureParam(new SigTypeRef(self::typeParam('U')))],
            null,
        );

        self::assertFalse(Specializer::closureSignatureGroundsAny($sig, ['E' => self::scalar('int')]));
    }

    public function testGroundsAnyIsFalseForANonTypeParameterLeafNamedLikeASubstitutionKey(): void
    {
        // A concrete class leaf that happens to be named `E` is not a type parameter, so
        // the substitution does not ground it.
        $sig = new ClosureSignature(
            [new ClosureSignatureParam(new SigTypeRef(new TypeRef('E')))],
            null,
        );

        self::assertFalse(Specializer::closureSignatureGroundsAny($sig, ['E' => self::scalar('int')]));
    }

    public function testGroundsAnyRecursesIntoANestedClosureLeaf(): void
    {
        // `Closure(Closure(E): string): string` — grounding lives one closure deep.
        $sig = new ClosureSignature(
            [new ClosureSignatureParam(new SigClosure(new ClosureSignature(
                [new ClosureSignatureParam(new SigTypeRef(self::typeParam('E')))],
                new SigTypeRef(self::scalar('string')),
            )))],
            new SigTypeRef(self::scalar('string')),
        );

        self::assertTrue(Specializer::closureSignatureGroundsAny($sig, ['E' => self::scalar('int')]));
    }

    public function testGroundsAnyDetectsAGroundedUnionMember(): void
    {
        $sig = new ClosureSignature(
            [new ClosureSignatureParam(new SigUnion([
                new SigTypeRef(self::scalar('int')),
                new SigTypeRef(self::typeParam('E')),
            ]))],
            null,
        );

        self::assertTrue(Specializer::closureSignatureGroundsAny($sig, ['E' => self::scalar('int')]));
    }

    public function testGroundsAnyDetectsAGroundedIntersectionMember(): void
    {
        $sig = new ClosureSignature(
            [new ClosureSignatureParam(new SigIntersection([
                new SigTypeRef(new TypeRef('App\\Countable')),
                new SigTypeRef(self::typeParam('E')),
            ]))],
            null,
        );

        self::assertTrue(Specializer::closureSignatureGroundsAny($sig, ['E' => self::scalar('int')]));
    }

    public function testGroundsAnyIsFalseForAConcreteUnionWithNoTypeParameter(): void
    {
        $sig = new ClosureSignature(
            [new ClosureSignatureParam(new SigUnion([
                new SigTypeRef(self::scalar('int')),
                new SigTypeRef(self::scalar('string')),
            ]))],
            null,
        );

        self::assertFalse(Specializer::closureSignatureGroundsAny($sig, ['E' => self::scalar('int')]));
    }

    public function testGroundsAnyIsFalseForAGradualRawLeaf(): void
    {
        // A SigRaw (unstructured DNF / scalar-bearing intersection) is gradual — it
        // grounds nothing, and must be handled without being mistaken for a union.
        $sig = new ClosureSignature(
            [new ClosureSignatureParam(new SigRaw('(A&B)|C'))],
            null,
        );

        self::assertFalse(Specializer::closureSignatureGroundsAny($sig, ['E' => self::scalar('int')]));
    }

    // ---- Helpers ---------------------------------------------------------

    private static function typeParam(string $name): TypeRef
    {
        return new TypeRef($name, [], isScalar: false, isTypeParam: true);
    }

    private static function scalar(string $name): TypeRef
    {
        return new TypeRef($name, [], isScalar: true);
    }

    private static function leafName(?SigType $type): ?string
    {
        return $type instanceof SigTypeRef ? $type->type->name : null;
    }
}
