<?php

declare(strict_types=1);

/**
 * Runtime verify for `closure_conformance_grounded_runtime`.
 *
 * The type parameter grounds to `int`; the conforming factory closure compiles,
 * its `Closure(...)` target erases to `\Closure`, and it runs.
 *
 * Driver contract: `$fixture` (CompiledFixture) in scope.
 */

use PHPUnit\Framework\Assert;

require $fixture->targetDir . '/Use.php';

Assert::assertSame(42, $result, 'the grounded factory closure ran');
