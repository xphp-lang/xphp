<?php

declare(strict_types=1);

/**
 * Runtime verify for `generic_trait_adaptation_mixed`: an adaptation block that mixes
 * a plain trait (`Plain`) with two generic traits (`G`, `H`) and excludes both generics
 * in one `insteadof`. The plain operand stays bare (mis-qualifying it would fatal); the
 * generic operands rewrite to their specializations. Plain's `val` wins; the excluded
 * generic `val`s are re-exposed under aliases; Plain's distinct method runs.
 *
 * Driver contract: the driver invokes the returned closure with the `CompiledFixture`, autoloader registered.
 */

use PHPUnit\Framework\Assert;
use XPHP\TestSupport\CompiledFixture;

return function (CompiledFixture $fixture): void {
    require $fixture->targetDir . '/Use.php';

    Assert::assertSame('P:1', $val);        // insteadof: Plain's val wins over G, H
    Assert::assertSame('G:2', $gval);       // as: excluded generic G::val under alias
    Assert::assertSame('H:3', $hval);       // as: excluded generic H::val under alias
    Assert::assertSame('plain', $plainOnly); // Plain's distinct method
};
