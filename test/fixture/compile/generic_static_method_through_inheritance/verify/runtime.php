<?php

declare(strict_types=1);

/**
 * Runtime verify for `generic_static_method_through_inheritance`:
 * a static generic method (`make<U>`) declared on `Base` resolves and runs when
 * called as `Derived::make::<...>()` on a subclass. The specialization is emitted
 * onto Base and reached through PHP's static-method inheritance.
 *
 * Driver contract: `$fixture` (CompiledFixture) in scope.
 */

use PHPUnit\Framework\Assert;

require $fixture->targetDir . '/Base.php';
require $fixture->targetDir . '/Derived.php';
require $fixture->targetDir . '/Use.php';

Assert::assertSame('hi', $s);
Assert::assertSame(7, $n);
