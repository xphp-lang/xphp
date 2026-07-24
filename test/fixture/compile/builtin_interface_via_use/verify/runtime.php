<?php

declare(strict_types=1);

/**
 * Runtime verify for `builtin_interface_via_use`.
 *
 * The specialized `ArrayBag<User>` is relocated into the `XPHP\Generated\...`
 * namespace. Before the fix, its `implements`/`extends` chain and body kept the
 * bare `use`-imported names (`Countable`, `IteratorAggregate`, `ArrayIterator`,
 * `EmptyBagError`), which resolved into the generated namespace and threw
 * "Interface ... not found" the moment the class autoloaded. This file proves
 * the class now loads and the built-ins resolve to the real global symbols.
 *
 * Driver contract: the driver invokes the returned closure with the `CompiledFixture`, autoload registered
 * for `App\BuiltinViaUse\` + the generated namespace.
 */

use App\BuiltinViaUse\Models\User;
use PHPUnit\Framework\Assert;
use XPHP\Transpiler\Monomorphize\Registry;
use XPHP\Transpiler\Monomorphize\TypeRef;
use XPHP\TestSupport\CompiledFixture;

return function (CompiledFixture $fixture): void {
    $bagFqn = Registry::generatedFqn(
        'App\\BuiltinViaUse\\ArrayBag',
        [new TypeRef('App\\BuiltinViaUse\\Models\\User')],
    );

    // Autoloads without a "not found" error — this is the regression under test.
    $bag = new $bagFqn();

    // `extends Countable, IteratorAggregate` (imported, bare) resolved to the real built-ins.
    Assert::assertInstanceOf(\Countable::class, $bag);
    Assert::assertInstanceOf(\IteratorAggregate::class, $bag);

    $bag->add(new User('alice'));
    $bag->add(new User('bob'));

    // Countable::count() via the bare `\count(...)` call (must have stayed bare).
    Assert::assertCount(2, $bag);

    // `getIterator(): Traversable { return new ArrayIterator(...); }` — return-type
    // hint + `new` of an imported built-in, both relocation-proof now.
    $iter = $bag->getIterator();
    Assert::assertInstanceOf(\Traversable::class, $iter);
    Assert::assertInstanceOf(\ArrayIterator::class, $iter);
    Assert::assertCount(2, $iter);

    // `first()` happy path returns the concrete substituted element type.
    Assert::assertInstanceOf(User::class, $bag->first());
    Assert::assertSame('alice', $bag->first()->name);

    // `extends AbstractBag` (bare, same-namespace) resolved to the real base class.
    Assert::assertInstanceOf(\App\BuiltinViaUse\AbstractBag::class, $bag);
    Assert::assertTrue((new $bagFqn())->isEmpty([]));

    // Param-typed / instanceof / class-const-fetch positions all load and run.
    Assert::assertTrue($bag->accepts(new \App\BuiltinViaUse\Errors\EmptyBagError('probe')));

    // Closure with an imported return type, nested in a relocated method.
    $factory = $bag->makeFactory();
    Assert::assertInstanceOf(\Traversable::class, $factory());

    // Typed (enum) class constant whose type + value reference a same-namespace class.
    Assert::assertSame(\App\BuiltinViaUse\Color::Red, $bag::DEFAULT_COLOR);

    // `catch (EmptyBagError $e)` + `new EmptyBagError(...)` path on the empty bag.
    $empty = new $bagFqn();
    try {
        $empty->first();
        Assert::fail('expected RuntimeException from the empty-bag path');
    } catch (\RuntimeException $e) {
        Assert::assertSame('bag is empty', $e->getMessage());
        Assert::assertInstanceOf(\App\BuiltinViaUse\Errors\EmptyBagError::class, $e->getPrevious());
    }
};
