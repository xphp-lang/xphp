<?php

declare(strict_types=1);

/**
 * Runtime verify for `qualified_bare_new_defaults`. FQ and relative bare
 * `new` of all-defaults generics must synthesize the defaults tuple and
 * execute; the relative spelling must bind to the CURRENT namespace's
 * template even with a colliding `use` alias in scope, while the aliased
 * bare form keeps targeting the aliased template.
 *
 * Driver contract: `$fixture` (CompiledFixture) in scope.
 */

use PHPUnit\Framework\Assert;

require $fixture->targetDir . '/OtherBox.php';
require $fixture->targetDir . '/Use.php';
require $fixture->targetDir . '/RelativeUse.php';

Assert::assertSame('hi', $a->v);
Assert::assertSame('ho', $b->v);
Assert::assertSame(7, $c->n);

// $a and $b are the same App-side specialization; $c is Other's.
Assert::assertSame(get_class($a), get_class($b));
Assert::assertNotSame(get_class($a), get_class($c));
Assert::assertStringContainsString('Other', get_class($c));
