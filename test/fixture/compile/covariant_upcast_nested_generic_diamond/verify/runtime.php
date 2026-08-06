<?php
declare(strict_types=1);
/**
 * Runtime verify for `covariant_upcast_nested_generic_diamond`.
 *
 * Loading `Use.php` links every generated spec, including `Lst<Tuple<Book,Book>>` — the concrete spec
 * that, before the post-edge gap-fill, was emitted abstract-incomplete (its erased
 * `contains_<Tuple<Book,Product>>` sibling unimplemented) and fataled at class load. That it loads and
 * `probe` returns proves the gap-fill supplied every diamond obligation.
 *
 * Driver contract: the driver invokes the returned closure with the `CompiledFixture`, autoload registered.
 */
use PHPUnit\Framework\Assert;
use XPHP\TestSupport\CompiledFixture;

return function (CompiledFixture $fixture): void {
    require $fixture->targetDir . '/Use.php';
    Assert::assertFalse($found, 'the diamond upcast contains-call must resolve, run, and report the tuple absent');
};
