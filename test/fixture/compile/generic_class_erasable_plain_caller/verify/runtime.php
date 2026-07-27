<?php

declare(strict_types=1);

/**
 * Runtime verify for `generic_class_erasable_plain_caller`: the plain caller's
 * grounded forward reuses the single erasure-lowered member (no duplicate append —
 * which would have been a "cannot redeclare method" load fatal) and executes.
 */

use PHPUnit\Framework\Assert;
use XPHP\TestSupport\CompiledFixture;

return function (CompiledFixture $fixture): void {
    require $fixture->targetDir . '/Use.php';

    Assert::assertTrue($hit);
    Assert::assertFalse($miss);

    $containsMembers = array_values(array_filter(
        get_class_methods($b),
        static fn (string $m): bool => str_starts_with($m, 'contains_'),
    ));
    Assert::assertCount(1, $containsMembers, 'the erasure-lowered member is reused, never duplicated');
};
