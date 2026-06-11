<?php

declare(strict_types=1);

/**
 * Runtime verify for `closure_dispatcher_unknown_tag`. The dispatcher's
 * synthesized `default` match arm throws RuntimeException carrying
 * the bogus tag. The compiled Use.php defines `$id` (the dispatcher
 * closure) at top level; the verify file then invokes it with a
 * tag that no real call site emits.
 *
 * Driver contract: `$fixture` (CompiledFixture) in scope.
 */

use PHPUnit\Framework\Assert;

require $fixture->targetDir . '/Use.php';

$caught = null;
try {
    $id('T_bogus', 99);
} catch (\RuntimeException $e) {
    $caught = $e;
}
Assert::assertInstanceOf(\RuntimeException::class, $caught);
Assert::assertSame('Unknown generic specialization tag: T_bogus', $caught->getMessage());
