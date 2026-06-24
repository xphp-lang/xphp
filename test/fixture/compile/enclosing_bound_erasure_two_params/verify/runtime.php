<?php

declare(strict_types=1);

/**
 * Runtime verify for `enclosing_bound_erasure_two_params`.
 *
 * A method with TWO enclosing-bounded parameters (`<U : E, V : E>`) erases both to E and mangles on
 * `[E, E]`. Both parameters widen to the bound (Fruit), so `bothAreFruit::<Banana, Cherry>` resolves
 * to the one emitted member and runs.
 *
 * Driver contract: `$fixture` (CompiledFixture) in scope, autoload registered.
 */

use PHPUnit\Framework\Assert;

require $fixture->targetDir . '/Use.php';

Assert::assertTrue($both, 'a two-bounded-param erasable method must resolve and run');
