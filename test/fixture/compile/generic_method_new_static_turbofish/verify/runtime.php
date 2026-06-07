<?php

declare(strict_types=1);

/**
 * Runtime verify for `generic_method_new_static_turbofish`:
 * `new static::<T>(…)` strips to bare `new static(…)`; late-static
 * binding resolves `static` against the specialized class. With no
 * subclassing in this fixture, `$a` and `$b` end up in the same class.
 *
 * Driver contract: `$fixture` (CompiledFixture) in scope.
 */

use PHPUnit\Framework\Assert;
use XPHP\Transpiler\Monomorphize\Registry;
use XPHP\Transpiler\Monomorphize\TypeRef;

require $fixture->targetDir . '/Builder.php';
require $fixture->targetDir . '/Use.php';

Assert::assertSame(2, $b->value);
Assert::assertSame(get_class($a), get_class($b));

$specializedFqn = Registry::generatedFqn(
    'App\\GenericMethodNewStaticTurbofish\\Builder',
    [new TypeRef('int', isScalar: true)],
);
Assert::assertSame($specializedFqn, get_class($a));
