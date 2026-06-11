<?php

declare(strict_types=1);

/**
 * Runtime verify file for the `array_sugar` fixture.
 *
 * Driver contract: the caller has compiled `array_sugar/source/`
 * via `CompiledFixture::compile()` and registered an autoloader for
 * the `App\ArraySugar\` user prefix plus the generated-namespace
 * prefix. This file references those classes directly and never
 * touches filesystem paths.
 */

use PHPUnit\Framework\Assert;
use XPHP\Transpiler\Monomorphize\Registry;
use XPHP\Transpiler\Monomorphize\TypeRef;

$collectionFqn = Registry::generatedFqn(
    'App\\ArraySugar\\Containers\\Collection',
    [new TypeRef('App\\ArraySugar\\Models\\User')],
);

// Non-empty: first() returns the concrete typed instance, all() is an array.
$collection = new $collectionFqn(
    new \App\ArraySugar\Models\User('alice'),
    new \App\ArraySugar\Models\User('bob'),
);
Assert::assertInstanceOf(\App\ArraySugar\Models\User::class, $collection->first());
$all = $collection->all();
Assert::assertIsArray($all);
Assert::assertCount(2, $all);

// Empty: first() returns null (the nullable arm of `?T`).
$empty = new $collectionFqn();
Assert::assertNull($empty->first());

// Reflection: the `?T` return type must lower to the concrete class,
// not survive as a literal `T` or be widened to `mixed`.
$returnType = (new \ReflectionMethod($collectionFqn, 'first'))->getReturnType();
Assert::assertInstanceOf(\ReflectionNamedType::class, $returnType);
Assert::assertSame('App\\ArraySugar\\Models\\User', $returnType->getName());
Assert::assertTrue($returnType->allowsNull());
