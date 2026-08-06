<?php

declare(strict_types=1);

/**
 * Runtime verify for `closure_arg_static_runtime`.
 *
 * A conforming closure literal passed to a grounded `Closure(int $x): int`
 * static-method parameter compiles, erases to `\Closure`, and runs.
 *
 * Driver contract: the driver invokes the returned closure with the `CompiledFixture`.
 */

use PHPUnit\Framework\Assert;
use XPHP\TestSupport\CompiledFixture;

return function (CompiledFixture $fixture): void {
    require $fixture->targetDir . '/Use.php';

    Assert::assertSame(42, $result, 'the conforming static closure argument ran');
};
