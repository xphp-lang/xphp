<?php

declare(strict_types=1);

/**
 * Runtime verify for `closure_defaults_with_use`.
 *
 * Driver contract: `$fixture` (CompiledFixture) in scope.
 */

use PHPUnit\Framework\Assert;

require $fixture->targetDir . '/Use.php';

Assert::assertSame(105, $r);
