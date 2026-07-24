<?php

declare(strict_types=1);

/**
 * Runtime verify for `generic_interface`: the specialized class
 * implements the specialized interface, and the interface's
 * `get()` reflection reports the concrete substituted return type.
 *
 * Driver contract: the driver invokes the returned closure with the `CompiledFixture`, autoload
 * registered for `App\GenericInterface\` + the generated namespace.
 */

use PHPUnit\Framework\Assert;
use XPHP\Transpiler\Monomorphize\Registry;
use XPHP\Transpiler\Monomorphize\TypeRef;
use XPHP\TestSupport\CompiledFixture;

return function (CompiledFixture $fixture): void {
    $interfaceFqn = Registry::generatedFqn(
        'App\\GenericInterface\\Containers\\Container',
        [new TypeRef('App\\GenericInterface\\Models\\Plastic')],
    );
    $boxFqn = Registry::generatedFqn(
        'App\\GenericInterface\\Containers\\Box',
        [new TypeRef('App\\GenericInterface\\Models\\Plastic')],
    );

    $box = new $boxFqn(new \App\GenericInterface\Models\Plastic('red'));

    Assert::assertInstanceOf($interfaceFqn, $box);
    Assert::assertSame('red', $box->get()->color);

    // Reflection: the interface's get() return type must be the
    // concrete substituted class, not the unspecialized `T`.
    $returnType = (new \ReflectionMethod($interfaceFqn, 'get'))->getReturnType();
    Assert::assertInstanceOf(\ReflectionNamedType::class, $returnType);
    Assert::assertSame('App\\GenericInterface\\Models\\Plastic', $returnType->getName());
};
