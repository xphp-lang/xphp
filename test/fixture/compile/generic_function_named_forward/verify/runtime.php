<?php

declare(strict_types=1);

/**
 * Runtime verify for `generic_function_named_forward`: a specialized `wrap_T_<hash>`
 * dispatches its forwarded `identity::<T>` call to a real `identity_T_<hash>`
 * specialization — the emitted chain executes end to end and returns the value
 * through both hops.
 *
 * Driver contract: the driver invokes the returned closure with the `CompiledFixture`.
 * Free functions aren't autoloadable in PHP, so require the emitted Use.php explicitly
 * to bring both specialized declarations into scope (its top-level driver statements
 * run too — they exercise the same calls).
 */

use PHPUnit\Framework\Assert;
use XPHP\Transpiler\Monomorphize\Registry;
use XPHP\Transpiler\Monomorphize\TypeRef;
use XPHP\TestSupport\CompiledFixture;

return function (CompiledFixture $fixture): void {
    require $fixture->targetDir . '/Use.php';

    $intHash = Registry::canonicalHash([new TypeRef('int', isScalar: true)]);
    $wrapInt = 'App\\NamedForward\\wrap_T_' . $intHash;
    $identityInt = 'App\\NamedForward\\identity_T_' . $intHash;
    Assert::assertTrue(function_exists($wrapInt), 'wrap<int> specialization is declared');
    Assert::assertTrue(function_exists($identityInt), 'the forwarded identity<int> specialization is declared');
    Assert::assertSame(3, $wrapInt(3));
    Assert::assertSame(41, $identityInt(41));

    $stringHash = Registry::canonicalHash([new TypeRef('string', isScalar: true)]);
    $wrapString = 'App\\NamedForward\\wrap_T_' . $stringHash;
    $identityString = 'App\\NamedForward\\identity_T_' . $stringHash;
    Assert::assertTrue(function_exists($wrapString), 'wrap<string> specialization is declared');
    Assert::assertTrue(function_exists($identityString), 'the forwarded identity<string> specialization is declared');
    Assert::assertSame('hi', $wrapString('hi'));
};
