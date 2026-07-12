<?php

declare(strict_types=1);

/**
 * Runtime verify for `closure_conformance_builtin_ok`.
 *
 * The exception factory compiles (no false-reject against the built-in
 * `\Throwable` target), erases to `\Closure`, and runs.
 *
 * Driver contract: `$fixture` (CompiledFixture) in scope.
 */

use PHPUnit\Framework\Assert;

require $fixture->targetDir . '/Use.php';

Assert::assertInstanceOf(\Throwable::class, $thrown, 'the factory returned a Throwable');
Assert::assertSame('boom', $thrown->getMessage());
