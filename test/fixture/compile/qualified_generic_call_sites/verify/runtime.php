<?php

declare(strict_types=1);

/**
 * Runtime verify for `qualified_generic_call_sites`. FQ and relative
 * spellings of generic call sites must all have specialized (no raw
 * `new` against the marker interface, no doubled-namespace template),
 * route to the SAME specializations as the bare forms, and execute.
 *
 * Driver contract: the driver invokes the returned closure with the `CompiledFixture`.
 */

use PHPUnit\Framework\Assert;
use XPHP\TestSupport\CompiledFixture;

return function (CompiledFixture $fixture): void {
    require $fixture->targetDir . '/Use.php';

    Assert::assertSame(1, $a->v);
    Assert::assertSame('r', $b->v);
    Assert::assertSame('k', $c->key);
    Assert::assertSame(5, $d->v);
    Assert::assertSame(9, $e->v);
    Assert::assertSame(3, $f);
    Assert::assertSame('g', $g);

    // FQ, in-template, and same-line-relative int instantiations must all be
    // the one int specialization.
    Assert::assertSame(get_class($a), get_class($d));
    Assert::assertSame(get_class($a), get_class($e));
};
