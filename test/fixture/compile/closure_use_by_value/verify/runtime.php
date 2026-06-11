<?php

declare(strict_types=1);

/**
 * Runtime verify for `closure_use_by_value`.
 *
 * Driver contract: `$fixture` (CompiledFixture) in scope.
 */

use PHPUnit\Framework\Assert;

require $fixture->targetDir . '/Use.php';

Assert::assertSame(43, $result);
Assert::assertSame(2, $y);
