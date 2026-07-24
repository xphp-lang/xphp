<?php

declare(strict_types=1);

/**
 * Runtime verify for `closure_conformance_builtin_ok`.
 *
 * The exception factory compiles (no false-reject against the built-in
 * `\Throwable` target), erases to `\Closure`, and runs.
 *
 * Driver contract: the driver invokes the returned closure with the `CompiledFixture`.
 */

use PHPUnit\Framework\Assert;
use XPHP\TestSupport\CompiledFixture;

return function (CompiledFixture $fixture): void {
    require $fixture->targetDir . '/Use.php';

    Assert::assertInstanceOf(\Throwable::class, $thrown, 'the factory returned a Throwable');
    Assert::assertSame('boom', $thrown->getMessage());
};
