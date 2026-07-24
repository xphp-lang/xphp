<?php

declare(strict_types=1);

/**
 * Runtime verify for `closure_conformance_grounded_runtime`.
 *
 * The type parameter grounds to `int`; the conforming factory closure compiles,
 * its `Closure(...)` target erases to `\Closure`, and it runs.
 *
 * Driver contract: the driver invokes the returned closure with the `CompiledFixture`.
 */

use PHPUnit\Framework\Assert;
use XPHP\TestSupport\CompiledFixture;

return function (CompiledFixture $fixture): void {
    require $fixture->targetDir . '/Use.php';

    Assert::assertSame(42, $result, 'the grounded factory closure ran');
};
