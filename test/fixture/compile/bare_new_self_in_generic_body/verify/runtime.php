<?php

declare(strict_types=1);

/**
 * Runtime verify for `bare_new_self_in_generic_body`: a bare `new self` inside a
 * non-defaults generic body compiles (not rejected by the WI-06 bare-new guard) and
 * runs -- `$c` is a copy of `$n` produced by `new self`, carrying the same value.
 *
 * Driver contract: `$fixture` (CompiledFixture) in scope, autoloader registered.
 */

use PHPUnit\Framework\Assert;

require $fixture->targetDir . '/Use.php';

Assert::assertSame(5, $n->v);
Assert::assertSame(5, $c->v);
Assert::assertNotSame($n, $c);
