<?php

declare(strict_types=1);

/**
 * Runtime verify for `use_trait_generic`: a generic trait-use survives the WI-08
 * use-import reject, specializes, and runs -- the inlined trait method returns.
 *
 * Driver contract: the driver invokes the returned closure with the `CompiledFixture`, autoloader registered.
 */

use PHPUnit\Framework\Assert;
use XPHP\TestSupport\CompiledFixture;

return function (CompiledFixture $fixture): void {
    require $fixture->targetDir . '/Use.php';

    Assert::assertSame('held', $label);
};
