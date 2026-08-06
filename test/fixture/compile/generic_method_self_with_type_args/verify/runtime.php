<?php

declare(strict_types=1);

/**
 * Runtime verify for `generic_method_self_with_type_args`:
 * `self<T>` in a return position lowers to bare `self` after
 * specialization, so `Container<int>::withItem(2)` mutates and
 * returns `$this` with `item = 2`.
 *
 * Driver contract: the driver invokes the returned closure with the `CompiledFixture`. The
 * autoloader resolves `App\GenericMethodSelfReturnTypeArgs\Container`
 * (an interface stub) plus the generated `T_<hash>` class.
 */

use PHPUnit\Framework\Assert;
use XPHP\Transpiler\Monomorphize\Registry;
use XPHP\Transpiler\Monomorphize\TypeRef;
use XPHP\TestSupport\CompiledFixture;

return function (CompiledFixture $fixture): void {
    require $fixture->targetDir . '/Container.php';
    require $fixture->targetDir . '/Use.php';

    Assert::assertSame(2, $b->item);

    // The specialized class lives under XPHP\Generated\…\Container\T_<hash>.
    $specializedFqn = Registry::generatedFqn(
        'App\\GenericMethodSelfReturnTypeArgs\\Container',
        [new TypeRef('int', isScalar: true)],
    );
    Assert::assertTrue(class_exists($specializedFqn));
    Assert::assertInstanceOf($specializedFqn, $b);
};
