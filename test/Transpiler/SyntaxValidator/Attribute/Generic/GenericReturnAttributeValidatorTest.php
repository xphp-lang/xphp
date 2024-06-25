<?php

declare(strict_types=1);

namespace XPHP\Transpiler\SyntaxValidator\Attribute\Generic;

use PHPUnit\Framework\TestCase;
use Roave\BetterReflection\BetterReflection;
use XPHP\Attribute\GenericReturn;
use XPHP\Transpiler\SyntaxValidator\Attribute\Generic\GenericReturn\InvalidMethodDueDuplicatedReturn;
use XPHP\Transpiler\SyntaxValidator\Attribute\Generic\GenericReturn\InvalidStaticMethodDueBadGenericTypeAlias;
use XPHP\Transpiler\SyntaxValidator\Attribute\Generic\GenericReturn\ValidClassAndMethod;
use XPHP\Transpiler\SyntaxValidator\Attribute\Generic\GenericReturn\InvalidMethodDueBadGenericTypeAlias;
use function Later\later;

final class GenericReturnAttributeValidatorTest extends TestCase
{
    public function testItMustAcceptValidMethod(): void
    {
        $fqcn = ValidClassAndMethod::class;

        $reflection = (new BetterReflection())->reflector()->reflectClass($fqcn)->getMethod('doNothing');
        $attr = $reflection->getAttributesByInstance(GenericReturn::class)[0];

        GenericReturnAttributeValidator::validateForMethod($reflection, $attr);

        self::assertTrue(true);
    }

    public function testItMustRejectInvalidMethodAttribute(): void
    {
        $fqcn = InvalidMethodDueBadGenericTypeAlias::class;

        $reflection = (new BetterReflection())->reflector()->reflectClass($fqcn)->getMethod('doNothing');
        $attr = $reflection->getAttributesByInstance(GenericReturn::class)[0];

        self::expectExceptionMessage(
            sprintf(
                'GenericType "Z" not found within class "%s"',
                InvalidMethodDueBadGenericTypeAlias::class,
            ),
        );

        GenericReturnAttributeValidator::validateForMethod($reflection, $attr);
    }

    public function testItMustRejectInvalidMethodDueDuplicatedReturn(): void
    {
        $fqcn = InvalidMethodDueDuplicatedReturn::class;

        $classReflection = (new BetterReflection())->reflector()->reflectClass($fqcn);
        $methodReflection = $classReflection->getMethod('doNothing');
        $attr = $methodReflection->getAttributesByInstance(GenericReturn::class)[0];

        self::expectExceptionMessage(
            sprintf(
                'GenericReturn MUST be unique in method "%s::%s()"',
                InvalidMethodDueDuplicatedReturn::class,
                'doNothing',
            ),
        );

        GenericReturnAttributeValidator::validateForMethod($methodReflection, $attr);
    }

    public function testItMustRejectInvalidStaticMethodDueBadGenericTypeAlias(): void
    {
        $fqcn = InvalidStaticMethodDueBadGenericTypeAlias::class;

        $classReflection = (new BetterReflection())->reflector()->reflectClass($fqcn);
        $methodReflection = $classReflection->getMethod('doNothing');
        $attr = $methodReflection->getAttributesByInstance(GenericReturn::class)[0];

        self::expectExceptionMessage(
            sprintf(
                'GenericType "Z" not found in method "%s::%s()"',
                InvalidStaticMethodDueBadGenericTypeAlias::class,
                'doNothing',
            ),
        );

        GenericReturnAttributeValidator::validateForMethod($methodReflection, $attr);
    }
}
