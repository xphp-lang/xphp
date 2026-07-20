<?php

declare(strict_types=1);

/**
 * Runtime verify for `closure_arg_plain_fn_runtime`.
 *
 * A conforming closure literal passed to a NON-generic free function's
 * Closure(Book): string parameter compiles, erases to `\Closure`, and runs.
 *
 * Driver contract: `$fixture` (CompiledFixture) in scope.
 */

use PHPUnit\Framework\Assert;

require $fixture->targetDir . '/Use.php';

Assert::assertSame('PHP', $result, 'the conforming free-function closure argument ran');
