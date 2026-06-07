<?php

declare(strict_types=1);

/**
 * Runtime verify for `generic_method_this_receiver`:
 * `$this->identity::<T>(…)` inside a class body specializes against
 * the enclosing class (no flow analysis needed), so the int and
 * string call sites each land on their own mangled method.
 *
 * Driver contract: `$fixture` (CompiledFixture) in scope.
 */

use PHPUnit\Framework\Assert;

require $fixture->targetDir . '/Util.php';
require $fixture->targetDir . '/Use.php';

Assert::assertSame(42, $i);
Assert::assertSame('hi', $s);
