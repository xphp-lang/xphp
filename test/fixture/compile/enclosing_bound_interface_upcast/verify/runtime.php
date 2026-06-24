<?php

declare(strict_types=1);

/**
 * Runtime verify for `enclosing_bound_interface_upcast`.
 *
 * A `ListColl<Book>` is upcast to `Collection<Product>` and an erased element-method
 * (`contains<E2:E>`) is called through the interface. The interface specialization
 * `Collection<Product>` declares the abstract `contains_<Product>`; for the program to load, the
 * concrete supertype specialization that implements it (`AbstractColl<Product>`) must have been
 * scheduled automatically — there is NO `new ListColl::<Product>()` anywhere in the source.
 *
 * That the program loads and `probe` returns proves the closer scheduled the implementer and the
 * covariant chain inherited it. `probe` looks for a fresh Product in a list holding one Book, so the
 * expected answer is false — the point is that the call resolves and runs at all.
 *
 * Driver contract: `$fixture` (CompiledFixture) in scope, autoload registered.
 */

use PHPUnit\Framework\Assert;

require $fixture->targetDir . '/Use.php';

Assert::assertFalse($found, 'the upcast contains-call must resolve, run, and report the Product absent');
echo "OK\n";
