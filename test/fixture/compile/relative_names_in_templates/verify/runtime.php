<?php

declare(strict_types=1);

/**
 * Runtime verify for `relative_names_in_templates`. Relative names inside
 * generic templates must bind to the current namespace (never a colliding
 * `use` alias, never a type parameter), across extends clauses, method
 * signatures, generic-method receivers, and conformance hierarchies.
 *
 * Driver contract: the driver invokes the returned closure with the `CompiledFixture`.
 */

use PHPUnit\Framework\Assert;
use XPHP\TestSupport\CompiledFixture;

return function (CompiledFixture $fixture): void {
    require $fixture->targetDir . '/LocalDefs.php';
    require $fixture->targetDir . '/OtherDefs.php';
    require $fixture->targetDir . '/Use.php';

    Assert::assertSame(4, $g->v);
    Assert::assertSame('App\\RelativeTemplates\\Base', get_parent_class($g));
    Assert::assertSame(1, $k->generic);
    Assert::assertSame('ok', $lbl);
    Assert::assertSame(5, $r);
    Assert::assertInstanceOf('App\\RelativeTemplates\\Cat', $cat);
    Assert::assertSame('App\\RelativeTemplates\\Base', get_parent_class($cat));
};
