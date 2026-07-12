<?php

declare(strict_types=1);

/**
 * Runtime verify for `closure_named_user_function_runtime`.
 *
 * The user function named `Closure` is CALLED (not erased) in every
 * expression position that shares a `) :` token pair with return-type slots.
 *
 * Driver contract: `$fixture` (CompiledFixture) in scope.
 */

use PHPUnit\Framework\Assert;

require $fixture->targetDir . '/Use.php';

Assert::assertSame(42, $ternary, 'ternary-else call to App\\...\\Closure() executed');
Assert::assertSame(20, $alt, 'alt-syntax-if call executed');
Assert::assertSame(12, $case, 'case-label call executed');

// The calls survive verbatim — no bare `\Closure` constant fetch anywhere.
$emitted = file_get_contents($fixture->targetDir . '/Use.php');
Assert::assertIsString($emitted);
Assert::assertStringNotContainsString('\\Closure ', $emitted);
Assert::assertStringContainsString('Closure(HALF)', $emitted);
