<?php

declare(strict_types=1);

namespace XPHP\Transpiler\SyntaxValidator\Attribute\Generic;

use PHPUnit\Framework\TestCase;
use Roave\BetterReflection\BetterReflection;
use XPHP\Attribute\GenericType;
use XPHP\Transpiler\SyntaxValidator\Attribute\Generic\GenericType\InvalidClass;
use XPHP\Transpiler\SyntaxValidator\Attribute\Generic\GenericType\InvalidNonStaticMethod;
use XPHP\Transpiler\SyntaxValidator\Attribute\Generic\GenericType\InvalidStaticMethod;
use XPHP\Transpiler\SyntaxValidator\Attribute\Generic\GenericType\ValidClassAndMethod;

final class GenericTypeAttributeValidatorTest extends TestCase
{
    public function testItMustAcceptValidClass(): void
    {
        $fqcn = ValidClassAndMethod::class;

        $reflection = (new BetterReflection())->reflector()->reflectClass($fqcn);
        $attr = $reflection->getAttributesByInstance(GenericType::class)[0];

        GenericTypeAttributeValidator::validateForClass($reflection, $attr);

        self::assertTrue(true);
    }

    public function testItMustRejectInvalidClassAttribute(): void
    {
        $fqcn = InvalidClass::class;

        $reflection = (new BetterReflection())->reflector()->reflectClass($fqcn);
        $attr = $reflection->getAttributesByInstance(GenericType::class)[0];

        self::expectExceptionMessage(
            sprintf(
                'GenericType "T" MUST be unique for class "%s"',
                $fqcn,
            ),
        );

        GenericTypeAttributeValidator::validateForClass($reflection, $attr);

        self::assertTrue(true);
    }

    public function testItMustRejectInvalidMethodAttribute(): void
    {
        $fqcn = InvalidStaticMethod::class;

        $reflection = (new BetterReflection())->reflector()->reflectClass($fqcn)->getMethod('doNothing');
        $attr = $reflection->getAttributesByInstance(GenericType::class)[0];

        self::expectExceptionMessage(
            sprintf(
                'GenericType "Z" MUST be unique for method "%s()"',
                "{$fqcn}::doNothing",
            ),
        );

        GenericTypeAttributeValidator::validateForMethod($reflection, $attr);

        self::assertTrue(true);
    }

    public function testItMustRejectNonStaticMethod(): void
    {
        $fqcn = InvalidNonStaticMethod::class;

        $reflection = (new BetterReflection())->reflector()->reflectClass($fqcn)->getMethod('doNothing');
        $attr = $reflection->getAttributesByInstance(GenericType::class)[0];

        self::expectExceptionMessage(
            sprintf(
                'GenericType "T" CAN NOT be declared for non-static method "%s()"',
                "{$fqcn}::doNothing",
            ),
        );

        GenericTypeAttributeValidator::validateForMethod($reflection, $attr);

        self::assertTrue(true);
    }
}
