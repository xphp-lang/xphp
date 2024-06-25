<?php

declare(strict_types=1);

namespace XPHP\Transpiler\SyntaxValidator\Attribute\Generic;

use Roave\BetterReflection\Reflection\ReflectionClass;
use Roave\BetterReflection\Reflection\ReflectionMethod;
use XPHP\Transpiler\SyntaxValidator\SyntaxErrorException;

final class GenericTypeAttributeValidatorException extends SyntaxErrorException
{
    public static function aliasMustBeUniqueForClass(
        string $genericTypeAlias,
        ReflectionClass $class,
    ): self {
        return new self(
            sprintf(
                'GenericType "%s" MUST be unique for class "%s"',
                $genericTypeAlias,
                $class->getName(),
            ),
        );
    }

    public static function aliasMustBeUniqueForMethod(
        string $genericTypeAlias,
        ReflectionMethod $method,
    ): self {
        return new self(
            sprintf(
                'GenericType "%s" MUST be unique for method "%s::%s()"',
                $genericTypeAlias,
                $method->getDeclaringClass()->getName(),
                $method->getName(),
            ),
        );
    }

    public static function nonStaticMethodIsNotSupported(
        string $genericTypeAlias,
        ReflectionMethod $method,
    ): self {
        return new self(
            sprintf(
                'GenericType "%s" CAN NOT be declared for non-static method "%s::%s()"',
                $genericTypeAlias,
                $method->getDeclaringClass()->getName(),
                $method->getName(),
            ),
        );
    }
}
