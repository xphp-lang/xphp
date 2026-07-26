<?php

declare(strict_types=1);

/**
 * Runtime verify for `generic_class_static_inherited_turbofish`: the static member
 * grounded from a generic base lives on the Holder specialization (declared, not
 * inherited) and executes; the earlier-instantiated Base<string> spec grew nothing.
 */

use PHPUnit\Framework\Assert;
use XPHP\TestSupport\CompiledFixture;

return function (CompiledFixture $fixture): void {
    require $fixture->targetDir . '/Use.php';

    Assert::assertSame([3, 3], $r1);
    Assert::assertSame('s', $r2);

    $genMembers = array_values(array_filter(
        get_class_methods($h),
        static fn (string $m): bool => str_starts_with($m, 'gen_T_'),
    ));
    Assert::assertCount(1, $genMembers);
    Assert::assertSame(
        get_class($h),
        (new ReflectionMethod($h, $genMembers[0]))->getDeclaringClass()->getName(),
        'member declared on the Holder spec itself',
    );
    Assert::assertSame([], array_values(array_filter(
        get_class_methods($b),
        static fn (string $m): bool => str_starts_with($m, 'gen_T_'),
    )), 'the Base<string> spec grew no grounded member');
};
