<?php

declare(strict_types=1);

use PHPUnit\Framework\Assert;
use XPHP\TestSupport\CompiledFixture;

return function (CompiledFixture $fixture): void {
    require $fixture->targetDir . '/Use.php';

    Assert::assertSame(43, $result);
    Assert::assertSame(2, $y);
};
