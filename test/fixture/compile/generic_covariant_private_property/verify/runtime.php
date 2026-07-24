<?php

declare(strict_types=1);

/**
 * Runtime verify for `generic_covariant_private_property`:
 * a covariant container `Box<out T>` that stores its element in a PRIVATE promoted
 * property of type `T`. The variance edge `Box<Banana> extends Box<Fruit>`
 * autoloads with no PHP fatal even though each specialization declares a
 * divergent-typed private slot (PHP doesn't type-check private property types
 * across the chain), a `Box<Banana>` is usable where a `Box<Fruit>` is expected
 * (covariant `get(): T`), and the constructor keeps its REAL element type so
 * construction is runtime-type-checked.
 *
 * Driver contract: the driver invokes the returned closure with the `CompiledFixture`, autoload registered.
 */

use App\CovariantPrivateProperty\Banana;
use App\CovariantPrivateProperty\Fruit;
use PHPUnit\Framework\Assert;
use XPHP\Transpiler\Monomorphize\Registry;
use XPHP\Transpiler\Monomorphize\TypeRef;
use XPHP\TestSupport\CompiledFixture;

return function (CompiledFixture $fixture): void {
    require $fixture->targetDir . '/Use.php';

    // Covariant use worked: a Box<Banana> flowed into a Box<Fruit> parameter and the
    // element read back through `get(): T`.
    Assert::assertSame('banana', $name);

    $bananaBoxFqn = Registry::generatedFqn(
        'App\\CovariantPrivateProperty\\Box',
        [new TypeRef('App\\CovariantPrivateProperty\\Banana')],
    );
    $fruitBoxFqn = Registry::generatedFqn(
        'App\\CovariantPrivateProperty\\Box',
        [new TypeRef('App\\CovariantPrivateProperty\\Fruit')],
    );

    // The private slot type is REAL (not erased to `mixed`) and runtime-checked at
    // construction: a `Box<Banana>` rejects a plain `Fruit`.
    $threw = false;
    try {
        new $bananaBoxFqn(new Fruit('apple'));
    } catch (\TypeError) {
        $threw = true;
    }
    Assert::assertTrue($threw, 'Box<Banana> must reject a non-Banana element at construction');

    // And it accepts a real Banana, exposing it through the covariant getter.
    $ok = new $bananaBoxFqn(new Banana());
    Assert::assertSame('banana', $ok->get()->name);

    // The covariant edge is real: a Box<Banana> IS a Box<Fruit> at the type level.
    Assert::assertInstanceOf($fruitBoxFqn, $ok);
};
