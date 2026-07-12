<?php

declare(strict_types=1);

/**
 * Runtime verify for `free_symbol_use_import`: a `use function` / `use const` import in the
 * template resolves to another namespace's symbols, and the relocated specialization still
 * binds them.  make(3)=6, RATE=100 -> 106.
 *
 * Driver contract: `$fixture` (CompiledFixture) in scope, autoloader registered.
 */

use PHPUnit\Framework\Assert;

require $fixture->targetDir . '/lib.php';
require $fixture->targetDir . '/Use.php';

Assert::assertSame(106, $computed);
