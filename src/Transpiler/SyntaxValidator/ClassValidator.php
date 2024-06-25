<?php

declare(strict_types=1);

namespace XPHP\Transpiler\SyntaxValidator;

use Exception;
use Roave\BetterReflection\Reflection\ReflectionClass;
use XPHP\Attribute\GenericType;
use XPHP\Transpiler\SyntaxValidator\Attribute\Generic\GenericTypeAttributeValidator;

final readonly class ClassValidator
{
    /**
     * @throws Exception
     */
    public static function validate(ReflectionClass $classReflection): void
    {
        foreach ($classReflection->getAttributesByInstance(GenericType::class) as $attr) {
            GenericTypeAttributeValidator::validateForClass($classReflection, $attr);
        }

        foreach ($classReflection->getMethods() as $method) {
            MethodValidator::validate($method);
        }
    }
}
