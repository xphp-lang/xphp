<?php

declare(strict_types=1);

/**
 * Runtime verify for `closure_dispatcher_runtime_routing`. After
 * requiring the compiled Use.php, the two top-level vars `$a` and
 * `$b` hold the values routed through the dispatcher arms.
 *
 * Driver contract: `$fixture` (CompiledFixture) in scope.
 */

use PHPUnit\Framework\Assert;

require $fixture->targetDir . '/Use.php';

Assert::assertSame(42, $a);
Assert::assertSame('hello', $b);
