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
