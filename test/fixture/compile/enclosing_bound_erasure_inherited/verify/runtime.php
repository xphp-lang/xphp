<?php

declare(strict_types=1);

/**
 * Runtime verify for `enclosing_bound_erasure_inherited`.
 *
 * An erasable `contains<U:E>` declared on a generic base is emitted as `contains_<Fruit>` on the
 * Base<Fruit> specialization; ArrayList<Fruit> extends it and inherits the member. The call site,
 * keyed on the receiver's E threaded to the DECLARING class, must produce the same mangled name —
 * this is the cross-cutting mangling invariant where call-site and Specializer name computation could
 * silently drift. That the call resolves and runs proves they agree.
 *
 * Driver contract: the driver invokes the returned closure with the `CompiledFixture`, autoload registered.
 */

use PHPUnit\Framework\Assert;
use XPHP\TestSupport\CompiledFixture;

return function (CompiledFixture $fixture): void {
    require $fixture->targetDir . '/Use.php';

    Assert::assertTrue($inherited, 'inherited erasable member must resolve via the same E-mangled name');
};
