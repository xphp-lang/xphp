<?php

declare(strict_types=1);

/**
 * Runtime verify for `enclosing_bound_erasure_covariant_chain`.
 *
 * Box<out E> specializes into a covariant `extends` chain (Box<Banana> extends Box<Fruit> extends
 * Box<Food>). Each specialization carries its own E-mangled `contains_<E>` and inherits its
 * ancestors'; the distinct names mean no parameter-narrowing LSP fatal across the chain. A
 * Box<Banana> used where a Box<Fruit> is expected dispatches the inherited `contains_<Fruit>`.
 * That this loads and runs proves erasure is variance-safe through the real pipeline.
 *
 * Driver contract: the driver invokes the returned closure with the `CompiledFixture`, autoload registered.
 */

use PHPUnit\Framework\Assert;
use XPHP\TestSupport\CompiledFixture;

return function (CompiledFixture $fixture): void {
    require $fixture->targetDir . '/Use.php';

    Assert::assertTrue($viaCovariance, 'a Box<Banana> via the Box<Fruit> view runs the inherited contains_<Fruit>');
    Assert::assertTrue($direct);
    Assert::assertTrue($isCovariant, 'Box<Banana> must be an instanceof the Box marker');
};
