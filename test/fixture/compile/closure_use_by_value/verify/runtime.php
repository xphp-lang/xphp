<?php

declare(strict_types=1);

/**
 * Runtime verify for `closure_use_by_value`.
 *
 * Driver contract: the driver invokes the returned closure with the `CompiledFixture`.
 */

use PHPUnit\Framework\Assert;
use XPHP\TestSupport\CompiledFixture;

return function (CompiledFixture $fixture): void {
    require $fixture->targetDir . '/Use.php';

    Assert::assertSame(43, $result);
    Assert::assertSame(2, $y);
};
