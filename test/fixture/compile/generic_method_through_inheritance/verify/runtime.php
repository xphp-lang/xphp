<?php

declare(strict_types=1);

/**
 * Runtime verify for `generic_method_through_inheritance`:
 * a generic method (`identity<U>`) declared on the base class `Base<T>`
 * resolves and runs when called via turbofish on a `Derived<int>` subclass
 * receiver. The specialization is emitted onto the declaring base and
 * inherited through the class-level `extends` edge.
 *
 * Driver contract: the driver invokes the returned closure with the `CompiledFixture`, autoload registered.
 */

use PHPUnit\Framework\Assert;
use XPHP\TestSupport\CompiledFixture;

return function (CompiledFixture $fixture): void {
    require $fixture->targetDir . '/Use.php';

    Assert::assertSame('hi', $s);
    Assert::assertSame(7, $n);
};
