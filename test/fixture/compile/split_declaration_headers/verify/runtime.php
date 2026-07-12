<?php

declare(strict_types=1);

/**
 * Runtime verify for `split_declaration_headers`. Generic classes, methods,
 * and functions whose headers split across lines (attribute or modifier on
 * its own line, keyword/name split) must all specialize and run.
 *
 * Driver contract: `$fixture` (CompiledFixture) in scope.
 */

use PHPUnit\Framework\Assert;

require $fixture->targetDir . '/Use.php';

Assert::assertSame('bx', $b->v);
Assert::assertSame(4, $p->u);
Assert::assertSame(5, $l);
Assert::assertSame('pk', $s);
Assert::assertSame(9, $i);
Assert::assertSame(['z', 'z'], $d);
