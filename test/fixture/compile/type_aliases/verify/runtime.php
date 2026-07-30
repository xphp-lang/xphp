<?php

declare(strict_types=1);

/**
 * Runtime verify for `type_aliases`: a generic alias (`Pair<A,B> = Dict<A, Bag<B>>`), a non-generic
 * plain-class alias (`UserId = Ident`), and a concrete-instantiation alias that references another
 * alias (`UserMap = Pair<int, User>`) are all erased before specialization, and the emitted program
 * executes end to end — the alias uses dispatch to the same specializations the hand-expanded types
 * would, and the plain alias resolves to its target class.
 *
 * Driver contract: the driver invokes the returned closure with the `CompiledFixture`. The user
 * files aren't PSR-4, so require them in dependency order; the generated specializations autoload.
 */

use PHPUnit\Framework\Assert;
use XPHP\TestSupport\CompiledFixture;

return function (CompiledFixture $fixture): void {
    require $fixture->targetDir . '/Types.php';

    // Pair<int, User> === Dict<int, Bag<User>>: the value is a Bag specialization holding a User.
    Assert::assertInstanceOf('App\\Aliases\\User', $pair->value()->get(), 'Pair<int,User> expanded to Dict<int, Bag<User>>');

    // UserId === Ident (a plain class): the alias resolves to its target class.
    Assert::assertInstanceOf('App\\Aliases\\Ident', $idValue, 'UserId expanded to the plain class Ident');

    // UserMap === Pair<int, User> === Dict<int, Bag<User>> (nested alias): same shape as $pair.
    Assert::assertInstanceOf('App\\Aliases\\User', $userMap->value()->get(), 'UserMap expanded through Pair to Dict<int, Bag<User>>');
    Assert::assertSame(
        $pair::class,
        $userMap::class,
        'UserMap and Pair<int, User> expand to the identical specialization',
    );

    // Bag<Elem> === Bag<User>: an alias in generic-argument position expanded; the item is a User.
    Assert::assertInstanceOf('App\\Aliases\\User', $elemBag->get(), 'Bag<Elem> expanded to Bag<User>');
};
