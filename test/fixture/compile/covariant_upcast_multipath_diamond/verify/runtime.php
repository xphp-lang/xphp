<?php
declare(strict_types=1);
/**
 * Runtime verify for `covariant_upcast_multipath_diamond`.
 *
 * The same Tuple element specs are reached as covariant-upcast implementers through two interface chains
 * (Collection, Lookup) AND two concrete classes (Lst, Bag) — several discovery paths converging on each
 * concrete spec. Loading `Use.php` links every spec; before the post-edge gap-fill each concrete spec was
 * emitted abstract-incomplete on at least one diamond sibling and fataled at class load. That an upcast
 * `contains` (via Lst) and an upcast `indexOf` (via Bag) both resolve and run proves the gap-fill supplies
 * every diamond sibling across interfaces and concretes, independent of discovery order.
 *
 * Driver contract: the driver invokes the returned closure with the `CompiledFixture`, autoload registered.
 */
use PHPUnit\Framework\Assert;
use XPHP\TestSupport\CompiledFixture;

return function (CompiledFixture $fixture): void {
    require $fixture->targetDir . '/Use.php';
    Assert::assertFalse($found, 'the upcast contains-call (via Lst) must resolve, run, and report the tuple absent');
    Assert::assertSame(-1, $index, 'the upcast indexOf-call (via Bag) must resolve, run, and report the tuple absent');
};
