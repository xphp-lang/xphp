<?php

declare(strict_types=1);

/**
 * Runtime verify for `closure_conformance_runtime`.
 *
 * Every `Closure(...)` return site holds a CONFORMING literal, so the file
 * compiles clean and the erased-to-`\Closure` output executes. Asserts the
 * factories' closures actually ran with the values they were built from.
 *
 * Driver contract: `$fixture` (CompiledFixture) in scope.
 */

use PHPUnit\Framework\Assert;

require $fixture->targetDir . '/Use.php';

Assert::assertSame(42, $scaled, 'stored property closure ran');
Assert::assertSame(42, $added, 'method-return factory closure ran');
Assert::assertSame(42, $incremented, 'free-function factory closure ran');
Assert::assertSame(42, $tripled, 'arrow-body factory closure ran');

// The erased output must carry a bare \Closure, never a residual `Closure(int`.
$emitted = file_get_contents($fixture->targetDir . '/Use.php');
Assert::assertIsString($emitted);
Assert::assertStringContainsString('\\Closure', $emitted);
Assert::assertStringNotContainsString('Closure(int', $emitted);
