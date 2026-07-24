<?php

declare(strict_types=1);

/**
 * Runtime verify for `closure_use_by_ref`.
 *
 * Driver contract: the driver invokes the returned closure with the `CompiledFixture`.
 */

use PHPUnit\Framework\Assert;
use XPHP\TestSupport\CompiledFixture;

return function (CompiledFixture $fixture): void {
    require $fixture->targetDir . '/Use.php';

    Assert::assertSame(99, $a);
    Assert::assertSame(99, $y);
};
