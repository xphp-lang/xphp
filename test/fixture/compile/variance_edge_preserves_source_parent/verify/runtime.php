<?php

declare(strict_types=1);

/**
 * Runtime verify for `variance_edge_preserves_source_parent`.
 *
 * `ListColl<out E> extends Base<E>` is instantiated at two covariant args (Fruit, Banana). The variance
 * edge emitter must NOT overwrite each specialization's source `extends Base<E>` with the same-template
 * covariant super (`ListColl<Banana> extends ListColl<Fruit>`): single inheritance allows one parent,
 * and the source parent carries the inherited `contains_<E>` member. Overwriting would drop
 * `ListColl<Banana>`'s path to `contains_<Banana>` and fatal "undefined method" on the call below.
 *
 * That both calls resolve and run proves each specialization kept its source parent and its inherited
 * erased member.
 *
 * Driver contract: the driver invokes the returned closure with the `CompiledFixture`, autoload registered.
 */

use PHPUnit\Framework\Assert;
use XPHP\TestSupport\CompiledFixture;

return function (CompiledFixture $fixture): void {
    require $fixture->targetDir . '/Use.php';

    Assert::assertTrue($fruitHit, 'ListColl<Fruit> must inherit contains_<Fruit> from its source parent Base<Fruit>');
    Assert::assertTrue($bananaHit, 'ListColl<Banana> must keep its source parent (not be overwritten) so contains_<Banana> resolves');
};
