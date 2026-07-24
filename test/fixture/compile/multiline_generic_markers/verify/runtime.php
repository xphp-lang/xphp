<?php

declare(strict_types=1);

/**
 * Runtime verify for `multiline_generic_markers`. Multi-line generic
 * clauses, turbofish arg lists, and sugar spans precede several generic
 * declarations; every one of them must have specialized (none emitted
 * raw), and the emitted program must execute.
 *
 * Driver contract: the driver invokes the returned closure with the `CompiledFixture`.
 */

use PHPUnit\Framework\Assert;
use XPHP\TestSupport\CompiledFixture;

return function (CompiledFixture $fixture): void {
    require $fixture->targetDir . '/Use.php';

    Assert::assertSame('w', $w->v);
    Assert::assertSame(7, $b->v);
    Assert::assertSame(41, $n);
    Assert::assertSame('s', $s);
    Assert::assertSame(2, $c);
};
