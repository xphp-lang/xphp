<?php

declare(strict_types=1);

/**
 * Runtime verify for `enclosing_bound_interface_upcast_map`.
 *
 * A two-parameter `HashMap<Id, Book>` (K invariant, out V covariant) is upcast to `MMap<Id, Product>`.
 * The erased `containsValue<U:V>` mangles on V, so the closer must schedule `AbstractMap<Id, Product>`
 * — varying only the covariant V to the supertype arg while keeping the invariant K = Id — with NO
 * explicit `HashMap<Id, Product>` anywhere. Executing the output proves the threading is correct.
 *
 * Driver contract: the driver invokes the returned closure with the `CompiledFixture`, autoload registered.
 */

use PHPUnit\Framework\Assert;
use XPHP\TestSupport\CompiledFixture;

return function (CompiledFixture $fixture): void {
    require $fixture->targetDir . '/Use.php';

    Assert::assertFalse($found, 'the multi-param upcast containsValue-call must resolve and run');
};
