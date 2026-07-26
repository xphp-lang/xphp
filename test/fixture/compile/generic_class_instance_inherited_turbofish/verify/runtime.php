<?php

declare(strict_types=1);

/**
 * Runtime verify for `generic_class_instance_inherited_turbofish`: the grounded
 * member lives on the Holder specialization (declared, not inherited), and the
 * earlier-instantiated Base<string> spec carries no int member.
 */

use PHPUnit\Framework\Assert;
use XPHP\TestSupport\CompiledFixture;

return function (CompiledFixture $fixture): void {
    require $fixture->targetDir . '/Use.php';

    Assert::assertSame([3, 3], $r1);
    Assert::assertSame('s', $r2);

    $holderDup = new ReflectionMethod($h, array_values(array_filter(
        get_class_methods($h),
        static fn (string $m): bool => str_starts_with($m, 'dup_T_'),
    ))[0]);
    Assert::assertSame(get_class($h), $holderDup->getDeclaringClass()->getName(), 'member declared on the Holder spec itself');

    $baseDup = array_values(array_filter(
        get_class_methods($b),
        static fn (string $m): bool => str_starts_with($m, 'dup_T_'),
    ));
    Assert::assertSame([], $baseDup, 'the Base<string> spec grew no grounded member');
};
