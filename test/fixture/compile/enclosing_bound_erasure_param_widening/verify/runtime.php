<?php

declare(strict_types=1);

/**
 * Runtime verify for `enclosing_bound_erasure_param_widening`.
 *
 * Under erasure, `contains<U:E>` lowers to ONE `contains_<Fruit>(Fruit)` member per Box<Fruit>, and
 * the parameter is widened to the bound E (Fruit). So two distinct call-site turbofish types
 * (Banana, Cherry) both lower to that single member, which accepts each as a Fruit. Both calls
 * running proves the per-E collapse and the param widening.
 *
 * Driver contract: the driver invokes the returned closure with the `CompiledFixture`, autoload registered.
 */

use PHPUnit\Framework\Assert;
use XPHP\TestSupport\CompiledFixture;

return function (CompiledFixture $fixture): void {
    require $fixture->targetDir . '/Use.php';

    Assert::assertTrue($banana, 'contains::<Banana> runs on the widened Fruit-typed member');
    Assert::assertTrue($cherry, 'contains::<Cherry> runs on the SAME widened member');
};
