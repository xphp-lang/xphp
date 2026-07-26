<?php

declare(strict_types=1);

/**
 * Runtime verify for `generic_class_instance_turbofish`: `$this->dup::<T>` executes
 * against the member grounded onto the specialization (exactly one per spec, two call
 * sites), and `$m->dup::<T>` against the member grounded onto the non-generic Maker.
 *
 * Driver contract: the driver invokes the returned closure with the `CompiledFixture`.
 */

use PHPUnit\Framework\Assert;
use XPHP\TestSupport\CompiledFixture;

return function (CompiledFixture $fixture): void {
    require $fixture->targetDir . '/Use.php';

    Assert::assertSame([1, 1], $r1);
    Assert::assertSame([2, 2], $r2);
    Assert::assertSame([3, 3], $r3);
    Assert::assertSame(['a', 'a'], $r4);

    $dupMembers = array_values(array_filter(
        get_class_methods($h),
        static fn (string $m): bool => str_starts_with($m, 'dup_T_'),
    ));
    Assert::assertCount(1, $dupMembers, 'two $this call sites, one grounded member per spec');

    $makerDup = array_values(array_filter(
        get_class_methods(\App\InstanceTurbofish\Maker::class),
        static fn (string $m): bool => str_starts_with($m, 'dup_T_'),
    ));
    // Grounding is per-spec, not per-executed-path: BOTH specializations ground their
    // whole body, so Maker carries dup<int> AND dup<string> — one per argument tuple,
    // deduped across specs (never one per call site).
    Assert::assertCount(2, $makerDup, 'one grounded member per unique argument tuple on the receiver class');
};
