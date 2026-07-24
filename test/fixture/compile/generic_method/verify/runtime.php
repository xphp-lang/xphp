<?php

declare(strict_types=1);

/**
 * Runtime verify for `generic_method`: the two specialized
 * `Util::identity_T_<hash>` static methods round-trip their argument
 * through the substituted concrete type.
 *
 * Driver contract: the driver invokes the returned closure with the `CompiledFixture`. Static
 * methods on user classes load via the registered autoloader.
 */

use PHPUnit\Framework\Assert;
use XPHP\Transpiler\Monomorphize\Registry;
use XPHP\Transpiler\Monomorphize\TypeRef;
use XPHP\TestSupport\CompiledFixture;

return function (CompiledFixture $fixture): void {
    require $fixture->targetDir . '/Util.php';

    $intMangle = 'identity_T_' . Registry::canonicalHash([new TypeRef('int', isScalar: true)]);
    $intCallable = ['App\\GenericMethod\\Util', $intMangle];
    $intResult = $intCallable(42);
    Assert::assertSame(42, $intResult);
    Assert::assertSame('integer', gettype($intResult));

    $stringMangle = 'identity_T_' . Registry::canonicalHash([new TypeRef('string', isScalar: true)]);
    $stringCallable = ['App\\GenericMethod\\Util', $stringMangle];
    $stringResult = $stringCallable('hello');
    Assert::assertSame('hello', $stringResult);
    Assert::assertSame('string', gettype($stringResult));
};
