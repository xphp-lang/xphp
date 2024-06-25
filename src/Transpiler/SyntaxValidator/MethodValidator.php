<?php

declare(strict_types=1);

namespace XPHP\Transpiler\SyntaxValidator;

use Exception;
use Roave\BetterReflection\Reflection\ReflectionMethod;
use XPHP\Attribute\GenericReturn;
use XPHP\Attribute\GenericType;
use XPHP\Attribute\GenericValue;
use XPHP\Transpiler\SyntaxValidator\Attribute\Generic\GenericReturnAttributeValidator;
use XPHP\Transpiler\SyntaxValidator\Attribute\Generic\GenericTypeAttributeValidator;
use XPHP\Transpiler\SyntaxValidator\Attribute\Generic\GenericValueAttributeValidator;

final readonly class MethodValidator
{
    /**
     * @throws Exception
     */
    public static function validate(ReflectionMethod $method): void
    {
        foreach ($method->getAttributesByInstance(GenericType::class) as $attr) {
            GenericTypeAttributeValidator::validateForMethod($method, $attr);
        }

        foreach ($method->getAttributesByInstance(GenericReturn::class) as $attr) {
            GenericReturnAttributeValidator::validateForMethod($method, $attr);
        }

        foreach ($method->getParameters() as $param) {
            foreach ($param->getAttributesByInstance(GenericValue::class) as $attr) {
                GenericValueAttributeValidator::validateForMethod($method, $attr);
            }
        }
    }
}
