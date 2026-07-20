<?php
declare(strict_types=1);
/**
 * Runtime verify for `covariant_upcast_return_enclosing_inherited`.
 *
 * A return-position enclosing-parameter erased method (`firstOr<S:E>(S): E`) on a parent-less covariant
 * base, reached through a PLAIN covariant upcast (no diamond). It is supplied by inheritance, not direct
 * emission; the post-edge gap-fill must recognise it as already provided and leave it alone. That this
 * compiles, loads, and `probe` returns the stored Book proves A3 does not over-emit a return-E member.
 *
 * Driver contract: `$fixture` (CompiledFixture) in scope, autoload registered.
 */
use PHPUnit\Framework\Assert;
require $fixture->targetDir . '/Use.php';
Assert::assertInstanceOf(\App\Book::class, $first, 'firstOr returns the stored Book through the covariant upcast');
echo "OK\n";
