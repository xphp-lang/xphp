<?php

declare(strict_types=1);

/**
 * Runtime verify for `cross_template_generic_arg_upcast`.
 *
 * A `Couple<ImmutableList<Book>, Tag>` is upcast to `Tuple<Collection<Product>, Tag>` and used through
 * the interface. For the program to LOAD, the covariant edge
 *   Tuple<ImmutableList<Book>, Tag>  ⊑  Tuple<Collection<Product>, Tag>
 * must have been emitted — and that requires the per-argument subtype `ImmutableList<Book> ⊑
 * Collection<Product>` to be recognized across DIFFERENT templates. Before the fix the edge is
 * silently omitted, `xphp check` passes, and this `require` fatals with a `TypeError` (the Couple
 * specialization never implements the `Tuple<Collection<Product>, Tag>` marker).
 *
 * That the program loads, the call resolves, and `first()` returns the Book proves the cross-template
 * edge was emitted and the covariance holds at runtime — not just at `check`.
 *
 * Driver contract: `$fixture` (CompiledFixture) in scope, autoload registered.
 */

use PHPUnit\Framework\Assert;

require $fixture->targetDir . '/Use.php';

Assert::assertInstanceOf(\App\Book::class, $first, 'the upcast tuple resolved and yielded its Book element');
echo "OK\n";
