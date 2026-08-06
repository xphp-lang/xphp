<?php

declare(strict_types=1);

/**
 * Runtime verify for `attributed_generic_closures`. Attributed and/or
 * static generic closures and arrows must specialize (not silently keep
 * raw type-param hints), and the emitted program must execute.
 *
 * Driver contract: the driver invokes the returned closure with the `CompiledFixture`.
 */

use PHPUnit\Framework\Assert;
use XPHP\TestSupport\CompiledFixture;

return function (CompiledFixture $fixture): void {
    require $fixture->targetDir . '/Use.php';

    Assert::assertSame(7, $a);
    Assert::assertSame('b', $b);
    Assert::assertSame(3, $c);
    Assert::assertSame('d', $d);
    Assert::assertSame(11, $e);
};
