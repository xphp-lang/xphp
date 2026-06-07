<?php

declare(strict_types=1);

/**
 * Runtime verify for `closure_use_capture_named_xphp_args`.
 *
 * Driver contract: `$fixture` (CompiledFixture) in scope.
 */

use PHPUnit\Framework\Assert;

require $fixture->targetDir . '/Use.php';

Assert::assertSame(203, $r);
