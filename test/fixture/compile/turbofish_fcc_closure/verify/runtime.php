<?php

declare(strict_types=1);

/**
 * Runtime verify for `turbofish_fcc_closure`: a first-class callable of a turbofish
 * specialization emits a valid forwarding closure (the file parses, or this require would
 * fatal) that routes through the dispatcher and preserves callable semantics.
 *
 * Driver contract: `$fixture` (CompiledFixture) in scope.
 */

use PHPUnit\Framework\Assert;

require $fixture->targetDir . '/Use.php';

Assert::assertSame(50, $viaCall);
Assert::assertSame([10, 20, 30], $viaMap);
Assert::assertSame(42, $named);
Assert::assertSame(7, $defaulted);
Assert::assertSame(4, $direct);
Assert::assertSame(8, $viaFcc);
