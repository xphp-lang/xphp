<?php

declare(strict_types=1);

namespace XPHP\Transpiler\SyntaxValidator\Attribute\Generic;

use Roave\BetterReflection\Reflection\ReflectionAttribute;
use Roave\BetterReflection\Reflection\ReflectionClass;
use Roave\BetterReflection\Reflection\ReflectionMethod;
use XPHP\Attribute\GenericReturn;
use XPHP\Attribute\GenericType;

final readonly class GenericReturnAttributeValidator
{
    /**
     * @throws GenericReturnAttributeValidatorException
     */
    public static function validateForMethod(
        ReflectionMethod $method,
        ReflectionAttribute $attributeReflection
    ): void {
        $returnTypes = $method->getAttributesByInstance(GenericReturn::class);

        if (count($returnTypes) > 1) {
            throw GenericReturnAttributeValidatorException::mustBeUniqueInMethod($method);
        }

        $genericTypeAlias = $attributeReflection->getArguments()[0];

        // static method MUST have their own generic types
        if ($method->isStatic()) {
            self::validateStaticMethod($method, $genericTypeAlias);

            return;
        }

        self::validateWithinClass(
            $method->getDeclaringClass(),
            $genericTypeAlias,
        );
    }

    private static function validateStaticMethod(
        ReflectionMethod $method,
        string $genericTypeAlias,
    ): void {
        $matchingGenericTypeAttribute = self::findGenericTypeByAlias(
            $genericTypeAlias,
            $method->getAttributesByInstance(GenericType::class)
        );

        if (empty($matchingGenericTypeAttribute)) {
            throw GenericReturnAttributeValidatorException::genericTypeNotFoundInStaticMethod($genericTypeAlias, $method);
        }
    }

    private static function validateWithinClass(
        ReflectionClass $reflectionClass,
        string $genericTypeAlias,
    ): void {
        $matchingGenericTypeAttribute = self::findGenericTypeByAlias(
            $genericTypeAlias,
            $reflectionClass->getAttributesByInstance(GenericType::class),
        );

        if (empty($matchingGenericTypeAttribute)) {
            throw GenericReturnAttributeValidatorException::genericTypeNotFoundWithinClass($genericTypeAlias, $reflectionClass);
        }
    }

    private static function findGenericTypeByAlias(string $genericTypeAlias, array $attributes): array
    {
        return array_filter(
            $attributes,
            fn (ReflectionAttribute $genericTypeAttribute) => $genericTypeAlias === $genericTypeAttribute->getArguments()[0],
        );
    }
}
