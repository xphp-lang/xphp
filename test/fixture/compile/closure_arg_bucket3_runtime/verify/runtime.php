<?php

declare(strict_types=1);

/**
 * Runtime verify for `closure_arg_bucket3_runtime`.
 *
 * A closure literal passed to a `$this->each(...)` self-call inside a generic class body,
 * whose `Closure(E): string` target grounds to `Closure(int): string` under Box<int>,
 * conforms, erases to `\Closure`, and runs.
 *
 * Driver contract: `$fixture` (CompiledFixture) in scope.
 */

use PHPUnit\Framework\Assert;

require $fixture->targetDir . '/Use.php';

Assert::assertSame('value=42', $result, 'the grounded bucket-3 self-call argument ran');
