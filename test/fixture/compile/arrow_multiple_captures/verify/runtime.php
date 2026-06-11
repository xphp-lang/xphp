<?php

declare(strict_types=1);

use PHPUnit\Framework\Assert;

require $fixture->targetDir . '/Use.php';

Assert::assertSame(31, $result);
