<?php

declare(strict_types=1);

namespace XPHP\Transpiler\SyntaxValidator\Attribute\Generic;

use PHPUnit\Framework\TestCase;
use Roave\BetterReflection\BetterReflection;
use XPHP\Attribute\GenericValue;
use XPHP\Transpiler\SyntaxValidator\Attribute\Generic\GenericValue\InvalidMethodDueBadGenericTypeAlias;
use XPHP\Transpiler\SyntaxValidator\Attribute\Generic\GenericValue\ValidClassAndMethod;

final class GenericValueAttributeValidatorTest extends TestCase
{
    public function testItMustAcceptValidMethod(): void
    {
        $fqcn = ValidClassAndMethod::class;

        $reflection = (new BetterReflection())->reflector()->reflectClass($fqcn)->getMethod('doNothing');
        $attr = $reflection->getParameter('value')->getAttributesByInstance(GenericValue::class)[0];

        GenericValueAttributeValidator::validateForMethod($reflection, $attr);

        self::assertTrue(true);
    }

    public function testItMustAcceptValidStaticMethod(): void
    {
        $fqcn = ValidClassAndMethod::class;

        $reflection = (new BetterReflection())->reflector()->reflectClass($fqcn)->getMethod('staticMethod');
        $attr = $reflection->getParameter('value')->getAttributesByInstance(GenericValue::class)[0];

        GenericValueAttributeValidator::validateForMethod($reflection, $attr);

        self::assertTrue(true);
    }

    public function testItRejectInvalidMethodDueBadGenericTypeAlias(): void
    {
        $fqcn = InvalidMethodDueBadGenericTypeAlias::class;

        $reflection = (new BetterReflection())->reflector()->reflectClass($fqcn)->getMethod('doNothing');
        $attr = $reflection->getParameter('value')->getAttributesByInstance(GenericValue::class)[0];

        self::expectExceptionMessage(
            sprintf(
                'GenericType "Z" not found within class "%s"',
                InvalidMethodDueBadGenericTypeAlias::class,
            ),
        );
        GenericValueAttributeValidator::validateForMethod($reflection, $attr);

        self::assertTrue(true);
    }
}
