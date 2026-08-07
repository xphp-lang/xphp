<?php

declare(strict_types=1);

/**
 * Runtime verify for `closure_sig_alias`: a closure-signature alias (`Handler = Closure(int): bool`)
 * and a generic one (`Mapper<T, R> = Closure(T): R`) erase to bare `\Closure` slots. The
 * non-negotiable gate is that the emitted program LOADS (the erased `\Closure` is a real type) and
 * that a closure flowing through each slot is invoked and returns the right value.
 *
 * Driver contract: the driver invokes the returned closure with the `CompiledFixture`. The user file
 * isn't PSR-4, so require it directly; the top-level statements run on require and expose the values.
 */

use PHPUnit\Framework\Assert;
use XPHP\TestSupport\CompiledFixture;

return function (CompiledFixture $fixture): void {
    require $fixture->targetDir . '/Handlers.php';

    // The `Handler` (`Closure(int): bool`) property slot held a closure that was invoked: 5 > 0.
    Assert::assertTrue($ok, 'Handler-typed closure slot was invoked and returned bool');

    // A single-head alias to a closure-sig alias (`Aliased = Handler`) also erases to `\Closure` and
    // loads — proving the transitive case emits a real type, not un-loadable / untyped PHP: -2 < 0.
    Assert::assertTrue($aliasOk, 'transitive closure-sig alias slot loaded and was invoked');

    // The generic `Mapper<int, string>` param slot held a closure invoked with an int, returning a string.
    Assert::assertSame('n3', $mapped, 'Mapper<int, string> closure slot was invoked and returned a string');
};
