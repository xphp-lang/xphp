<?php

declare(strict_types=1);

/**
 * Runtime verify for `closure_conformance_dnf_runtime`.
 *
 * DNF-grouped signature types scan as one gradual leaf, the signatures erase,
 * and the compiled output executes. Asserts both factories' closures ran.
 *
 * Driver contract: `$fixture` (CompiledFixture) in scope.
 */

use PHPUnit\Framework\Assert;

require $fixture->targetDir . '/Use.php';

Assert::assertSame(42, $count, 'DNF-parameter factory closure ran');
Assert::assertSame('both', $tag, 'DNF-return factory closure ran');

// Full erasure: no DNF residue may survive into the emitted output.
$emitted = file_get_contents($fixture->targetDir . '/Use.php');
Assert::assertIsString($emitted);
Assert::assertStringContainsString('\\Closure', $emitted);
Assert::assertStringNotContainsString('(Tagged&Counted)', $emitted);
Assert::assertStringNotContainsString('|(', $emitted);
