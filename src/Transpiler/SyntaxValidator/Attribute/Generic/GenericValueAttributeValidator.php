<?php

declare(strict_types=1);

namespace XPHP\Transpiler\SyntaxValidator\Attribute\Generic;

use Roave\BetterReflection\Reflection\ReflectionAttribute;
use Roave\BetterReflection\Reflection\ReflectionMethod;
use XPHP\Attribute\GenericType;

final readonly class GenericValueAttributeValidator
{
    /**
     * @throws GenericValueAttributeValidatorException
     */
    public static function validateForMethod(
        ReflectionMethod $method,
        ReflectionAttribute $attributeReflection
    ): void {
        $genericTypeAlias = $attributeReflection->getArguments()[0];

        // static method can have their own generic types
        if ($method->isStatic()) {
            $matchingGenericTypeAttribute = array_filter(
                $method->getAttributesByInstance(GenericType::class),
                fn (ReflectionAttribute $genericTypeAttribute) => $genericTypeAlias === $genericTypeAttribute->getArguments()[0],
            );

            if (!empty($matchingGenericTypeAttribute)) {
                return;
            }
        }

        $matchingGenericTypeAttribute = array_filter(
            $method->getDeclaringClass()->getAttributesByInstance(GenericType::class),
            fn (ReflectionAttribute $genericTypeAttribute) => $genericTypeAlias === $genericTypeAttribute->getArguments()[0],
        );

        if (empty($matchingGenericTypeAttribute)) {
            throw GenericValueAttributeValidatorException::genericTypeNotFoundWithinClass(
                $genericTypeAlias,
                $method->getDeclaringClass(),
            );
        }
    }
}
