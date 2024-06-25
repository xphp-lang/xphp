<?php

declare(strict_types=1);

namespace XPHP\Transpiler\SyntaxValidator\Attribute\Generic;

use Roave\BetterReflection\Reflection\ReflectionClass;
use Roave\BetterReflection\Reflection\ReflectionMethod;
use XPHP\Transpiler\SyntaxValidator\SyntaxErrorException;

final class GenericReturnAttributeValidatorException extends SyntaxErrorException
{
    public static function genericTypeNotFoundWithinClass(
        string $genericTypeAlias,
        ReflectionClass $reflectionClass,
    ): self {

        return new self(
            sprintf(
                'GenericType "%s" not found within class "%s"',
                $genericTypeAlias,
                $reflectionClass->getName(),
            ),
        );
    }

    public static function genericTypeNotFoundInStaticMethod(
        string $genericTypeAlias,
        ReflectionMethod $reflectionMethod,
    ): self {
        return new self(
            sprintf(
                'GenericType "%s" not found in method "%s::%s()"',
                $genericTypeAlias,
                $reflectionMethod->getDeclaringClass()->getName(),
                $reflectionMethod->getName(),
            ),
        );
    }

    public static function mustBeUniqueInMethod(ReflectionMethod $method): self
    {
        $methodName = $method->getName();
        $className = $method->getDeclaringClass()->getName();

        return new self(
            sprintf(
                'GenericReturn MUST be unique in method "%s::%s()"',
                $className,
                $methodName,
            ),
        );
    }
}
