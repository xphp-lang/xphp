<?php

declare(strict_types=1);

/**
 * Runtime verify for `closure_use_multiple_mixed_captures`.
 *
 * Driver contract: `$fixture` (CompiledFixture) in scope.
 */

use PHPUnit\Framework\Assert;

require $fixture->targetDir . '/Use.php';

Assert::assertSame(36, $r);
Assert::assertSame(10, $a);
Assert::assertSame(25, $b);
