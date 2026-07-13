<?php

declare(strict_types=1);

/**
 * Runtime verify for `closure_arg_instance_runtime`.
 *
 * A conforming closure literal passed as a call argument to a grounded
 * `Closure(Book $x): string` parameter compiles, erases to `\Closure`, and runs.
 *
 * Driver contract: `$fixture` (CompiledFixture) in scope.
 */

use PHPUnit\Framework\Assert;

require $fixture->targetDir . '/Use.php';

Assert::assertSame('PHP', $result, 'the conforming closure argument ran');
