<?php

declare(strict_types=1);

namespace XPHP\Transpiler\SyntaxValidator\Attribute\Generic;

use Roave\BetterReflection\Reflection\ReflectionClass;
use XPHP\Transpiler\SyntaxValidator\SyntaxErrorException;

final class GenericValueAttributeValidatorException extends SyntaxErrorException
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
}
