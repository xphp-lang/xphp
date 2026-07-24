<?php

declare(strict_types=1);

/**
 * Runtime verify for `same_line_marker_pairs`. Every same-line
 * same-spelling pair must have bound its markers to the right sites and
 * the whole program must execute.
 *
 * Driver contract: the driver invokes the returned closure with the `CompiledFixture`.
 */

use PHPUnit\Framework\Assert;
use XPHP\TestSupport\CompiledFixture;

return function (CompiledFixture $fixture): void {
    require $fixture->targetDir . '/Use.php';
    require $fixture->targetDir . '/Aliased.php';

    Assert::assertSame(9, $m);
    Assert::assertSame(7, $a);
    Assert::assertSame(8, $b);
    Assert::assertSame(1, $k->v);
    Assert::assertSame(2, $p1->v);
    Assert::assertSame('s', $p2->v);
    Assert::assertNotSame(get_class($p1), get_class($p2));
    Assert::assertSame(11, $t);
    Assert::assertSame('App\\SameLinePairs\\B', get_class($bb));
    Assert::assertSame(3, $sum);
    Assert::assertSame('v=9', $msg);
    Assert::assertSame('al', $hv);
};
