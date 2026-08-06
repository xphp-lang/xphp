<?php

declare(strict_types=1);

/**
 * Runtime verify for `generic_class_method_forward_chain`: the 2-hop own-template
 * forward executes through both grounded members on the specialization.
 */

use PHPUnit\Framework\Assert;
use XPHP\TestSupport\CompiledFixture;

return function (CompiledFixture $fixture): void {
    require $fixture->targetDir . '/Use.php';

    Assert::assertSame([9, 9], $r);

    $grounded = array_values(array_filter(
        get_class_methods($h),
        static fn (string $m): bool => str_starts_with($m, 'a_T_') || str_starts_with($m, 'b_T_'),
    ));
    Assert::assertCount(2, $grounded, 'both hops grounded onto the spec');
};
