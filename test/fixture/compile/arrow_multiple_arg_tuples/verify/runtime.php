<?php

declare(strict_types=1);

use PHPUnit\Framework\Assert;

require $fixture->targetDir . '/Use.php';

Assert::assertSame(10, $a);
Assert::assertSame('hi', $b);
