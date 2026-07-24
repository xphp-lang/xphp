<?php

declare(strict_types=1);

/**
 * Runtime verify for `enclosing_bound_subinterface_structural_class_param`.
 *
 * `firstKind`'s body is `$value instanceof E`. The member is emitted directly onto `ListColl<Book>` under
 * the upcast, with the parameter widened to `Product` but the class `E` resolving to the upcast-source's
 * `Book`. So at runtime the call (with a fresh `Product`) evaluates `$value instanceof Book` → false. If the
 * split substitution were wrong and `E` resolved to `Product`, it would be `$value instanceof Product` →
 * true. The false answer proves the class `E` was substituted with `Book`, not `Product`.
 *
 * Driver contract: the driver invokes the returned closure with the `CompiledFixture`, autoload registered.
 */

use PHPUnit\Framework\Assert;
use XPHP\TestSupport\CompiledFixture;

return function (CompiledFixture $fixture): void {
    require $fixture->targetDir . '/Use.php';

    Assert::assertFalse($result, 'the body class parameter E must substitute to the upcast-source concrete (Book), not the supertype (Product)');
};
