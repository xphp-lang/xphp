<?php

declare(strict_types=1);

/**
 * Runtime verify for `generic_class_method_turbofish`: enclosing-param-grounded method
 * turbofish executes end to end — `Box<int>::make(5)` dispatches to the spec's own
 * `gen_T_<hash>` member, `viaMaker` to the shared `Maker::wrap_T_<hash>` — and the
 * dedup invariants hold at runtime: exactly one gen member per specialization (two
 * call sites), exactly two wrap members on Maker (one per unique argument tuple,
 * shared across the two forwarding generic classes).
 *
 * Driver contract: the driver invokes the returned closure with the `CompiledFixture`.
 */

use PHPUnit\Framework\Assert;
use XPHP\TestSupport\CompiledFixture;

return function (CompiledFixture $fixture): void {
    require $fixture->targetDir . '/Maker.php';
    require $fixture->targetDir . '/Use.php';

    Assert::assertSame(5, $r1);
    Assert::assertSame(6, $r2);
    Assert::assertSame([7], $r3);
    Assert::assertSame('hi', $r4);
    Assert::assertSame([8], $r5);

    $genMembers = array_values(array_filter(
        get_class_methods($b),
        static fn (string $m): bool => str_starts_with($m, 'gen_T_'),
    ));
    Assert::assertCount(1, $genMembers, 'two call sites, one appended gen member per spec');

    $wrapMembers = array_values(array_filter(
        get_class_methods(\App\MethodTurbofish\Maker::class),
        static fn (string $m): bool => str_starts_with($m, 'wrap_T_'),
    ));
    Assert::assertCount(2, $wrapMembers, 'one wrap member per unique argument tuple (int, string)');
};
