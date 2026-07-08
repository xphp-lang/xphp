<?php

declare(strict_types=1);

/**
 * Runtime verify for `free_symbol_requalify`: the specialized Box runs and its free-symbol
 * references bind the App-defined function/const (not a phantom in XPHP\Generated), with the
 * builtin and magic constant left to global resolution.
 *   scale(1)=10, BONUS=5, Sub\tweak(2)=3, strlen('ab')=2, true?0 -> 10+5+3+2+0 = 20
 *
 * Driver contract: `$fixture` (CompiledFixture) in scope, autoloader registered.
 */

use PHPUnit\Framework\Assert;

// Free functions/consts aren't autoloadable, so define them before Use.php runs its
// top-level instantiation (the specialized class itself autoloads via PSR-4).
require $fixture->targetDir . '/helpers.php';
require $fixture->targetDir . '/sub.php';
require $fixture->targetDir . '/Use.php';

Assert::assertSame(20, $result);
