<?php

declare(strict_types=1);

/**
 * Runtime verify for `closure_dispatcher_use_clause`.
 *
 * Driver contract: the driver invokes the returned closure with the `CompiledFixture`.
 * The compiled `Use.php` defines `$base`, `$counter`, and three call
 * results `$callA`, `$callB`, `$callC` at top level. After require,
 * each is available here. The by-ref `&$counter` capture mutates
 * across the three calls so the post-call value is 3.
 */

use PHPUnit\Framework\Assert;
use XPHP\TestSupport\CompiledFixture;

return function (CompiledFixture $fixture): void {
    require $fixture->targetDir . '/Use.php';

    Assert::assertSame([1, 10, 1], $callA);
    Assert::assertSame([2, 10, 2], $callB);
    Assert::assertSame(['hi', 10, 3], $callC);
    Assert::assertSame(3, $counter);
};
