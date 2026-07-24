<?php

declare(strict_types=1);

/**
 * Runtime verify for `closure_conformance_array_sugar_runtime`.
 *
 * Array-sugar leaves in signature parameter and return positions lower to
 * `array`, the signatures erase, and the compiled output executes.
 *
 * Driver contract: the driver invokes the returned closure with the `CompiledFixture`.
 */

use PHPUnit\Framework\Assert;
use XPHP\TestSupport\CompiledFixture;

return function (CompiledFixture $fixture): void {
    require $fixture->targetDir . '/Use.php';

    Assert::assertSame(42, $sum, 'sugared-parameter factory closure ran');
    Assert::assertSame([40, 2], $list, 'sugared-return factory closure ran');

    // Full erasure: no bracket-pair residue may survive into the emitted output
    // (the trailing `[40, 2]` array literal is body code, not a type).
    $emitted = file_get_contents($fixture->targetDir . '/Use.php');
    Assert::assertIsString($emitted);
    Assert::assertStringContainsString('\\Closure', $emitted);
    Assert::assertStringNotContainsString('Item[]', $emitted);
    Assert::assertStringNotContainsString('int[]', $emitted);
};
