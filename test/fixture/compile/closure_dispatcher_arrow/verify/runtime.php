<?php

declare(strict_types=1);

/**
 * Runtime verify for `closure_dispatcher_arrow`.
 *
 * Driver contract: `$fixture` (CompiledFixture) must be in scope.
 * Requiring the compiled `Use.php` brings the top-level variables
 * `$y` and `$resultArrow` into this file's scope, so the assertions
 * read them directly. The capture moment is the assign site, so the
 * call sees y=1 even though the outer y reassigned to 2 before it.
 */

use PHPUnit\Framework\Assert;

require $fixture->targetDir . '/Use.php';

Assert::assertSame(43, $resultArrow);
Assert::assertSame(2, $y);
