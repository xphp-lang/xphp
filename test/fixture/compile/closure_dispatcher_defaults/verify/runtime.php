<?php

declare(strict_types=1);

/**
 * Runtime verify for `closure_dispatcher_defaults`.
 *
 * Driver contract: the driver invokes the returned closure with the `CompiledFixture`.
 * After requiring the compiled `Use.php`, the empty-turbofish and
 * explicit calls leave their results in `$resultPaddedClosure`,
 * `$resultExplicitClosure`, and `$resultPaddedArrow`.
 */

use PHPUnit\Framework\Assert;
use XPHP\TestSupport\CompiledFixture;

return function (CompiledFixture $fixture): void {
    require $fixture->targetDir . '/Use.php';

    Assert::assertSame('#42', $resultPaddedClosure);
    Assert::assertSame('#hi', $resultExplicitClosure);
    Assert::assertSame('world', $resultPaddedArrow);
};
