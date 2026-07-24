<?php

declare(strict_types=1);

/**
 * Runtime verify for `enclosing_bound_subinterface_direct_emit`.
 *
 * A `ListColl<Book>` is upcast to `OrderedCollection<Product>` and its erased `indexOf` is called. The
 * body lives on `ListColl`, which has a parent (`AbstractColl`), so the member can't be inherited through
 * a covariant edge — it must be emitted directly onto `ListColl<Book>`, with the parameter widened to
 * `Product` and the body reading the inherited `Book`-typed `$items` (Book <: Product). `contains` still
 * resolves via the inheritance path. That both calls run proves direct emission and inheritance coexist.
 *
 * Driver contract: the driver invokes the returned closure with the `CompiledFixture`, autoload registered.
 */

use PHPUnit\Framework\Assert;
use XPHP\TestSupport\CompiledFixture;

return function (CompiledFixture $fixture): void {
    require $fixture->targetDir . '/Use.php';

    Assert::assertSame(-1, $idx, 'indexOf via direct emission must resolve, run, and report the fresh Product absent');
    Assert::assertFalse($has, 'contains via inheritance must still resolve');
};
