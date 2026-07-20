<?php

declare(strict_types=1);

/**
 * Runtime verify for `free_symbol_group_use_import`: group-form `use function Vendor\{make, scale}`
 * and `use Vendor\{const RATE, const STEP}` imports in the template resolve to Vendor's symbols, and
 * the relocated specialization still binds them. make(3)=6 + scale(1)=10 + RATE=100 + STEP=7 = 123.
 *
 * Driver contract: `$fixture` (CompiledFixture) in scope, autoloader registered.
 */

use PHPUnit\Framework\Assert;

require $fixture->targetDir . '/lib.php';
require $fixture->targetDir . '/Use.php';

Assert::assertSame(123, $computed);
