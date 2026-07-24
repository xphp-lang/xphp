<?php

declare(strict_types=1);

/**
 * Runtime verify for `comparator_param_covariant_upcast`.
 *
 * A `Box<Book>` is upcast to `Box<Product>` and its `pick(Comparator<E> $c)` is called with a
 * `ById` (a `Comparator<Product>`, hence by contravariance a `Comparator<Book>`). For the program to
 * LOAD and run, the covariant `Box<Book> ⊑ Box<Product>` edge and the contravariant
 * `Comparator<Product> ⊑ Comparator<Book>` edge must both be emitted, and `Box<Book>::pick` must accept
 * the comparator (its parameter widens to `Comparator<Book>` — sound contravariant param widening).
 *
 * The whole shape was previously REJECTED at compile time (`xphp.variance_position`) even though it is
 * sound; the fix routes the nested `Comparator<E>` verdict through the composing variance pass, which
 * accepts it. That the program runs and `pick` returns the max Book proves the acceptance is sound.
 *
 * Driver contract: the driver invokes the returned closure with the `CompiledFixture`, autoload registered.
 */

use PHPUnit\Framework\Assert;
use XPHP\TestSupport\CompiledFixture;

return function (CompiledFixture $fixture): void {
    require $fixture->targetDir . '/Use.php';

    Assert::assertInstanceOf(\App\Book::class, $best, 'pick returned a Book element through the upcast');
    Assert::assertSame(3, $best->id, 'pick selected the max-id Book via the Product comparator');
};
