<?php

declare(strict_types=1);

/**
 * Runtime verify for `multi_type`: a specialized Pair<User, Plastic>
 * accepts (User, Plastic) but rejects (Plastic, User) with a TypeError.
 *
 * Driver contract: the driver invokes the returned closure with the `CompiledFixture`, autoload
 * registered for `App\MultiType\` + the generated namespace.
 */

use PHPUnit\Framework\Assert;
use XPHP\Transpiler\Monomorphize\Registry;
use XPHP\Transpiler\Monomorphize\TypeRef;
use XPHP\TestSupport\CompiledFixture;

return function (CompiledFixture $fixture): void {
    $pairFqn = Registry::generatedFqn(
        'App\\MultiType\\Containers\\Pair',
        [new TypeRef('App\\MultiType\\Models\\User'), new TypeRef('App\\MultiType\\Models\\Plastic')],
    );

    // Correct order: constructor accepts (User, Plastic).
    $ok = new $pairFqn(
        new \App\MultiType\Models\User('alice'),
        new \App\MultiType\Models\Plastic('red'),
    );
    Assert::assertInstanceOf($pairFqn, $ok);

    // Swapped order: constructor expects User in slot 0, Plastic in slot 1;
    // passing them flipped triggers a TypeError on the first slot mismatch.
    $caught = null;
    try {
        new $pairFqn(
            new \App\MultiType\Models\Plastic('red'),
            new \App\MultiType\Models\User('alice'),
        );
    } catch (\TypeError $e) {
        $caught = $e;
    }
    Assert::assertInstanceOf(\TypeError::class, $caught);
};
