<?php

declare(strict_types=1);

/**
 * Runtime verify for `array_sugar_before_generic_closure`. The
 * `VeryLongRecordName[]` rewrite shortens the file before the generic
 * closure; the closure must still specialize and route both calls.
 *
 * Driver contract: the driver invokes the returned closure with the `CompiledFixture`.
 */

use PHPUnit\Framework\Assert;
use XPHP\TestSupport\CompiledFixture;

return function (CompiledFixture $fixture): void {
    require $fixture->targetDir . '/Use.php';

    Assert::assertSame(1, $n);
    Assert::assertSame(42, $a);
    Assert::assertSame('hello', $b);
};
