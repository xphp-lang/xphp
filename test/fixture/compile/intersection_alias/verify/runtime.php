<?php

declare(strict_types=1);

/**
 * Runtime verify for `intersection_alias`: an intersection body (`Both = A & B`) and a DNF body
 * (`Dnf = (A & B) | C`) are erased to real PHP `A&B` / `(A&B)|C` type nodes in the property, param,
 * and return slots. The non-negotiable gate is that the emitted program LOADS (a malformed
 * intersection / DNF node would fatal at class-load) and that PHP's own native type check accepts the
 * conforming objects flowing through those slots.
 *
 * Driver contract: the driver invokes the returned closure with the `CompiledFixture`. The user file
 * isn't PSR-4, so require it directly; the top-level statements run on require and expose the values.
 */

use PHPUnit\Framework\Assert;
use XPHP\TestSupport\CompiledFixture;

return function (CompiledFixture $fixture): void {
    require $fixture->targetDir . '/Shapes.php';

    // The `Both $both` property + constructor param (`A&B`) accepted an object implementing both.
    Assert::assertInstanceOf('App\\Inter\\AB', $holder->both, 'intersection slot A&B accepted an A&B object');

    // The composed `Deduped` slot (`Inner & B` = A&B&B → deduped to A&B) loaded and accepted an A&B
    // object — proving the emitted intersection has no duplicate member (which would fatal at load).
    Assert::assertInstanceOf('App\\Inter\\AB', $holder->deduped, 'composed intersection deduped to A&B and loaded');

    // The `Dnf` slot `(A&B)|C` accepted the A&B arm and the C arm, round-tripping each.
    Assert::assertInstanceOf('App\\Inter\\AB', $dnfAB, 'DNF (A&B)|C accepted the A&B arm');
    Assert::assertInstanceOf('App\\Inter\\ABC', $dnfC, 'DNF (A&B)|C accepted the C arm');
};
