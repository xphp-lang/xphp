<?php

declare(strict_types=1);

/**
 * Runtime verify for `generic_method_local_variable_receiver`:
 * local-variable flow typing. `$u = new Util()` lets the later
 * `$u->identity::<T>(…)` specialize against Util via the visitor's
 * lexical-last-write record.
 *
 * Driver contract: the driver invokes the returned closure with the `CompiledFixture`.
 */

use PHPUnit\Framework\Assert;
use XPHP\TestSupport\CompiledFixture;

return function (CompiledFixture $fixture): void {
    require $fixture->targetDir . '/Util.php';
    require $fixture->targetDir . '/Use.php';

    Assert::assertSame(99, $i);
    Assert::assertSame('world', $s);
};
