<?php
declare(strict_types=1);
/**
 * Runtime verify for `covariant_gapfill_free_symbol`.
 *
 * A covariant diamond forces `contains`/`indexOf` to be gap-filled onto the concrete specs (Lst, Bag)
 * AFTER the relocation sweep runs. Their erasable bodies read an in-unit const `OFFSET` and call an
 * in-unit free function `tally`; both live in `App` but the specialized bodies live in `XPHP\Generated`.
 * The upcast `contains` (via Lst) returns true — tally(OFFSET)===10 AND the tuple is absent — and the
 * upcast `indexOf` (via Bag) returns -OFFSET + tally(3) = 1 for the absent tuple. A bare reference that
 * rebinds to the generated namespace would fatal ("undefined function XPHP\Generated\App\Lst\tally").
 *
 * Driver contract: the driver invokes the returned closure with the `CompiledFixture`, autoload registered.
 */
use PHPUnit\Framework\Assert;
use XPHP\TestSupport\CompiledFixture;

return function (CompiledFixture $fixture): void {
    // Free functions/consts aren't autoloadable, so define them before Use.php runs.
    require $fixture->targetDir . '/helpers.php';
    require $fixture->targetDir . '/Use.php';

    Assert::assertTrue($found, 'the upcast contains-call (via Lst) must bind the App free symbols and report the tuple absent');
    Assert::assertSame(1, $index, 'the upcast indexOf-call (via Bag) must compute -OFFSET + tally(3) = 1 for the absent tuple');
};
