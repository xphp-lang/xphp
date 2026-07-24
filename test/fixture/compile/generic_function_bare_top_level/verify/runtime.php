<?php

declare(strict_types=1);

/**
 * Runtime verify for `generic_function_bare_top_level`: a bare
 * top-level generic function specializes; the non-generic sibling
 * function survives the strip pass intact; both call sites resolve.
 *
 * Driver contract: the driver invokes the returned closure with the `CompiledFixture`. Bare-
 * top-level functions aren't autoloadable, so requiring funcs.php
 * is the only way to bring `identity_T_<hash>` and
 * `nonGenericDouble` into scope.
 */

use PHPUnit\Framework\Assert;
use XPHP\TestSupport\CompiledFixture;

return function (CompiledFixture $fixture): void {
    require $fixture->targetDir . '/funcs.php';
    require $fixture->targetDir . '/Use.php';

    Assert::assertSame(42, $asInt);
    Assert::assertSame(42, $doubled);
};
