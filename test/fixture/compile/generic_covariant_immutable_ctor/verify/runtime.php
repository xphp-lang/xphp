<?php

declare(strict_types=1);

/**
 * Runtime verify for `generic_covariant_immutable_ctor` (ticket 0005):
 * a covariant immutable collection `ImmutableList<+T>` with a `T`-typed
 * constructor. `ImmutableList<Banana>` is usable where `ImmutableList<Fruit>`
 * is expected (covariant `extends` edge), and the variance-erased `mixed`
 * constructor does NOT PHP-fatal at autoload across that edge.
 *
 * Driver contract: `$fixture` (CompiledFixture) in scope, autoload registered.
 */

use PHPUnit\Framework\Assert;

require $fixture->targetDir . '/Use.php';

Assert::assertSame(2, $cnt);
Assert::assertSame('banana', $name);
