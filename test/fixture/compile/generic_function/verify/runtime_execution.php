<?php

declare(strict_types=1);

/**
 * Runtime verify for `generic_function`: the specialized
 * `identity_T_<hash>` free functions execute and return values of
 * the substituted concrete types.
 *
 * Driver contract: the driver invokes the returned closure with the `CompiledFixture`. Free
 * functions aren't autoloadable in PHP, so require funcs.php
 * explicitly to bring the specialized declarations into scope.
 */

use PHPUnit\Framework\Assert;
use XPHP\Transpiler\Monomorphize\Registry;
use XPHP\Transpiler\Monomorphize\TypeRef;
use XPHP\TestSupport\CompiledFixture;

return function (CompiledFixture $fixture): void {
    require $fixture->targetDir . '/funcs.php';

    $intMangle = 'identity_T_' . Registry::canonicalHash([new TypeRef('int', isScalar: true)]);
    $intFqn = 'App\\GenericFunction\\' . $intMangle;
    $intResult = $intFqn(42);
    Assert::assertSame(42, $intResult);

    $stringMangle = 'identity_T_' . Registry::canonicalHash([new TypeRef('string', isScalar: true)]);
    $stringFqn = 'App\\GenericFunction\\' . $stringMangle;
    $stringResult = $stringFqn('hi');
    Assert::assertSame('hi', $stringResult);
};
