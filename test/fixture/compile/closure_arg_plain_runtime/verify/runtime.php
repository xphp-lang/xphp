<?php

declare(strict_types=1);

/**
 * Runtime verify for `closure_arg_plain_runtime`.
 *
 * A conforming closure literal passed to a NON-generic method's Closure(Book): string
 * parameter compiles, erases to `\Closure`, and runs.
 *
 * Driver contract: the driver invokes the returned closure with the `CompiledFixture`.
 */

use PHPUnit\Framework\Assert;
use XPHP\TestSupport\CompiledFixture;

return function (CompiledFixture $fixture): void {
    require $fixture->targetDir . '/Use.php';

    Assert::assertSame('PHP', $result, 'the conforming plain-method closure argument ran');
};
