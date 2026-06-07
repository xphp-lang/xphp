<?php

declare(strict_types=1);

/**
 * Runtime verify for `closure_use_multiple_arg_tuples`.
 *
 * Driver contract: `$fixture` (CompiledFixture) in scope.
 */

use PHPUnit\Framework\Assert;

require $fixture->targetDir . '/Use.php';

Assert::assertSame('pre:1', $a);
Assert::assertSame('pre:two', $b);
