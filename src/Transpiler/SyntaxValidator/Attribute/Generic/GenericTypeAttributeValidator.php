<?php

declare(strict_types=1);

namespace XPHP\Transpiler\SyntaxValidator\Attribute\Generic;

use Roave\BetterReflection\Reflection\ReflectionAttribute;
use Roave\BetterReflection\Reflection\ReflectionClass;
use Roave\BetterReflection\Reflection\ReflectionMethod;
use XPHP\Attribute\GenericType;

final readonly class GenericTypeAttributeValidator
{
    /**
     * @throws GenericTypeAttributeValidatorException
     */
    public static function validateForMethod(
        ReflectionMethod $method,
        ReflectionAttribute $attribute
    ): void {
        $genericTypeAlias = $attribute->getArguments()[0];

        if (!$method->isStatic()) {
            throw GenericTypeAttributeValidatorException::nonStaticMethodIsNotSupported($genericTypeAlias, $method);
        }

        $matchingGenericTypeAttribute = array_filter(
            $method->getAttributesByInstance(GenericType::class),
            fn (ReflectionAttribute $genericTypeAttribute) => $genericTypeAlias === $genericTypeAttribute->getArguments()[0],
        );


        if (count($matchingGenericTypeAttribute) > 1) {
            throw GenericTypeAttributeValidatorException::aliasMustBeUniqueForMethod($genericTypeAlias, $method);
        }
    }

    /**
     * @throws GenericTypeAttributeValidatorException
     */
    public static function validateForClass(
        ReflectionClass $class,
        ReflectionAttribute $attribute
    ): void {
        $genericTypeAlias = $attribute->getArguments()[0];

        $matchingGenericTypeAttribute = array_filter(
            $class->getAttributesByInstance(GenericType::class),
            fn (ReflectionAttribute $genericTypeAttribute) => $genericTypeAlias === $genericTypeAttribute->getArguments()[0],
        );

        if (count($matchingGenericTypeAttribute) > 1) {
            throw GenericTypeAttributeValidatorException::aliasMustBeUniqueForClass($genericTypeAlias, $class);
        }
    }
}
