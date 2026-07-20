<?php

declare(strict_types=1);

/**
 * Runtime verify for `enclosing_bound_erasure_map_multiparam`.
 *
 * `containsValue<U:V>` on `Map<K, out V>` is bounded by the SECOND class parameter V. The erased member
 * must mangle on V's concrete value (Fruit), not K (string) — the call site (keyed on the receiver's
 * V) and the Specializer must agree on that key. That the call resolves and runs proves the
 * multi-class-param mangle keys on the bound's referent.
 *
 * Driver contract: `$fixture` (CompiledFixture) in scope, autoload registered.
 */

use PHPUnit\Framework\Assert;

require $fixture->targetDir . '/Use.php';

Assert::assertTrue($found, 'containsValue mangled on V (Fruit) must resolve and run');
Assert::assertSame('k', $label);
