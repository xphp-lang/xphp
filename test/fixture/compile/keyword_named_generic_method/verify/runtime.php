<?php

declare(strict_types=1);

/**
 * Runtime verify for `keyword_named_generic_method`: a generic method whose name is a PHP keyword
 * (`list`, `print`) declares, specializes, and is callable through both an instance turbofish and a
 * static turbofish. Instance `list::<int>(41)` returns 41; static `print::<int>(7)` returns 7.
 *
 * Driver contract: the driver invokes the returned closure with the `CompiledFixture`, autoload registered.
 */

use PHPUnit\Framework\Assert;
use XPHP\TestSupport\CompiledFixture;

return function (CompiledFixture $fixture): void {
    require $fixture->targetDir . '/Use.php';

    Assert::assertSame(41, $instanceResult);
    Assert::assertSame(7, $staticResult);
};
