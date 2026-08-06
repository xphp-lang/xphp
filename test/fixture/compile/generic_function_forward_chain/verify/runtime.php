<?php

declare(strict_types=1);

/**
 * Runtime verify for `generic_function_forward_chain`: the 2-hop forward chain
 * (`wrap<int>` → `mid<int>` → `identity<int>`) and the same-args mutual-recursion pair
 * (`ping<string>` ↔ `pong<string>`) both execute against the emitted specializations.
 */

use PHPUnit\Framework\Assert;
use XPHP\Transpiler\Monomorphize\Registry;
use XPHP\Transpiler\Monomorphize\TypeRef;
use XPHP\TestSupport\CompiledFixture;

return function (CompiledFixture $fixture): void {
    require $fixture->targetDir . '/Use.php';

    $intHash = Registry::canonicalHash([new TypeRef('int', isScalar: true)]);
    foreach (['wrap', 'mid', 'identity'] as $fn) {
        Assert::assertTrue(
            function_exists('App\\ForwardChain\\' . $fn . '_T_' . $intHash),
            $fn . '<int> specialization is declared',
        );
    }
    $wrapInt = 'App\\ForwardChain\\wrap_T_' . $intHash;
    Assert::assertSame(7, $wrapInt(7));

    $stringHash = Registry::canonicalHash([new TypeRef('string', isScalar: true)]);
    foreach (['ping', 'pong'] as $fn) {
        Assert::assertTrue(
            function_exists('App\\ForwardChain\\' . $fn . '_T_' . $stringHash),
            $fn . '<string> specialization is declared',
        );
    }
    $pingString = 'App\\ForwardChain\\ping_T_' . $stringHash;
    Assert::assertSame('x', $pingString('x', 3));
};
