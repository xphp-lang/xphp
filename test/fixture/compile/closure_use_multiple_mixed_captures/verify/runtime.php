<?php

declare(strict_types=1);

/**
 * Runtime verify for `closure_use_multiple_mixed_captures`.
 *
 * Driver contract: the driver invokes the returned closure with the `CompiledFixture`.
 */

use PHPUnit\Framework\Assert;
use XPHP\TestSupport\CompiledFixture;

return function (CompiledFixture $fixture): void {
    require $fixture->targetDir . '/Use.php';

    Assert::assertSame(36, $r);
    Assert::assertSame(10, $a);
    Assert::assertSame(25, $b);
};
