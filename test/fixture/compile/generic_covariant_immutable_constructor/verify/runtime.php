<?php

declare(strict_types=1);

/**
 * Runtime verify for `generic_covariant_immutable_constructor`:
 * a covariant immutable collection `ImmutableList<out T>` with a `T`-typed
 * constructor. `ImmutableList<Banana>` is usable where `ImmutableList<Fruit>`
 * is expected (covariant `extends` edge), the constructor keeps its REAL element
 * type on each specialization (no erasure), and that real type is enforced by
 * PHP at construction — passing a non-Banana to `ImmutableList<Banana>` throws.
 *
 * Driver contract: `$fixture` (CompiledFixture) in scope, autoload registered.
 */

use App\CovariantConstructor\Banana;
use App\CovariantConstructor\Fruit;
use PHPUnit\Framework\Assert;
use XPHP\Transpiler\Monomorphize\Registry;
use XPHP\Transpiler\Monomorphize\TypeRef;

require $fixture->targetDir . '/Use.php';

Assert::assertSame(2, $cnt);
Assert::assertSame('banana', $name);

// The constructor element type is REAL (not erased to `mixed`) and runtime-checked:
// an `ImmutableList<Banana>` rejects a plain `Fruit` at construction.
$bananaListFqn = Registry::generatedFqn(
    'App\\CovariantConstructor\\ImmutableList',
    [new TypeRef('App\\CovariantConstructor\\Banana')],
);
$threw = false;
try {
    new $bananaListFqn(new Fruit('apple'));
} catch (\TypeError) {
    $threw = true;
}
Assert::assertTrue($threw, 'ImmutableList<Banana> must reject a non-Banana element at construction');

// And it accepts a real Banana.
$ok = new $bananaListFqn(new Banana());
Assert::assertSame('banana', $ok->get(0)->name);
