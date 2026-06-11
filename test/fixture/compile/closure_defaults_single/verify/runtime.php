<?php

declare(strict_types=1);

/**
 * Runtime verify for `closure_defaults_single`.
 *
 * Driver contract: `$fixture` (CompiledFixture) in scope.
 */

use PHPUnit\Framework\Assert;

require $fixture->targetDir . '/Use.php';

Assert::assertSame(42, $r);
