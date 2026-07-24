<?php

declare(strict_types=1);

/**
 * Runtime verify for `generic_trait_adaptation`: a class uses two generic traits in
 * SEPARATE `use` statements and resolves their colliding `pick` method in the second
 * statement's adaptation block. The `insteadof` picks A's `pick`; the `as` re-exposes
 * B's `pick` as `bpick`; each trait's distinct method still runs. The adaptation
 * operands (`A`, `B`) must have been rewritten to the SAME specialized FQNs as their
 * `use`-list entries -- a bare operand would fatal at class load ("Trait App\... not
 * found").
 *
 * Driver contract: the driver invokes the returned closure with the `CompiledFixture`, autoloader registered.
 */

use PHPUnit\Framework\Assert;
use XPHP\TestSupport\CompiledFixture;

return function (CompiledFixture $fixture): void {
    require $fixture->targetDir . '/Use.php';

    Assert::assertSame('A:5', $pick);   // insteadof: A's pick wins
    Assert::assertSame('B:6', $bpick);  // as: B's pick reachable under the alias
    Assert::assertSame(3, $aOnly);      // A's distinct method
    Assert::assertSame(4, $bOnly);      // B's distinct method
};
