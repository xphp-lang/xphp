<?php

declare(strict_types=1);

/**
 * Runtime verify for `keyword_nongeneric_passthrough`: keyword-named non-generic methods and
 * `list()` destructuring survive the keyword-turbofish support unchanged. list(10)+1=11,
 * print(20)+2=22, unpack([3,4])=7.
 *
 * Driver contract: `$fixture` (CompiledFixture) in scope, autoload registered.
 */

use PHPUnit\Framework\Assert;

require $fixture->targetDir . '/Use.php';

Assert::assertSame(11, $instance);
Assert::assertSame(22, $static);
Assert::assertSame(7, $destructured);
