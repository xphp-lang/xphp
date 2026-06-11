<?php

declare(strict_types=1);

/**
 * Runtime verify for `generic_method_new_self_turbofish`:
 * `new self::<T>(…)` is stripped to bare `new self(…)` after
 * monomorphization, and PHP's runtime resolves `self` against the
 * specialized class — so `$a->with(13)` returns a `Container<int>`
 * with `item = 13`, the same class as `$a`.
 *
 * Driver contract: `$fixture` (CompiledFixture) in scope.
 */

use PHPUnit\Framework\Assert;
use XPHP\Transpiler\Monomorphize\Registry;
use XPHP\Transpiler\Monomorphize\TypeRef;

require $fixture->targetDir . '/Container.php';
require $fixture->targetDir . '/Use.php';

Assert::assertSame(13, $b->item);
Assert::assertSame(get_class($a), get_class($b));

$specializedFqn = Registry::generatedFqn(
    'App\\GenericMethodNewSelfTurbofish\\Container',
    [new TypeRef('int', isScalar: true)],
);
Assert::assertSame($specializedFqn, get_class($a));
