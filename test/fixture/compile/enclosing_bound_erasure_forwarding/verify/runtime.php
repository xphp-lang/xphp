<?php

declare(strict_types=1);

/**
 * Runtime verify for `enclosing_bound_erasure_forwarding`.
 *
 * `probe<U:E>(U)` forwards its parameter to `$this->contains::<U>()`. Both are erasable, so the
 * Specializer lowers them to E-mangled members (`contains_<Fruit>`, `probe_<Fruit>`) on Box<Fruit>
 * and rewrites the forward to call `contains_<Fruit>`. If the forward were left as a bare
 * `$this->contains(...)` (the old silent break), this would fatal with "undefined method" the
 * moment `probe` ran. That it runs and returns the contained-element verdict proves the lowering.
 *
 * Driver contract: the driver invokes the returned closure with the `CompiledFixture`, autoload registered.
 */

use PHPUnit\Framework\Assert;
use XPHP\TestSupport\CompiledFixture;

return function (CompiledFixture $fixture): void {
    require $fixture->targetDir . '/Use.php';

    Assert::assertTrue($viaForward, 'forwarded self-call must resolve to the emitted contains_<E> method');
    Assert::assertTrue($viaDirect, 'a direct erasable call must run too');
};
