<?php

declare(strict_types=1);

/**
 * Runtime verify for `closure_use_by_ref`.
 *
 * Driver contract: `$fixture` (CompiledFixture) in scope.
 */

use PHPUnit\Framework\Assert;

require $fixture->targetDir . '/Use.php';

Assert::assertSame(99, $a);
Assert::assertSame(99, $y);
