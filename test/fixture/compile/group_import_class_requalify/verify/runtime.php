<?php

declare(strict_types=1);

/**
 * Runtime verify for `group_import_class_requalify`: a class imported via a GROUP `use`
 * (`use Vendor\{Tool};`) and one via a single `use Vendor\Widget;` both re-qualify to Vendor in the
 * relocated Box<int> body. Tool::ping()='pong' . Widget::spin()='spin' -> 'pongspin'. A group form
 * that fell back to the current namespace would fatal with "Class App\Tool not found".
 *
 * Driver contract: `$fixture` (CompiledFixture) in scope, autoload registered.
 */

use PHPUnit\Framework\Assert;

// Vendor's two classes share one emitted lib.php (not one-class-per-file), so PSR-4 can't autoload
// them — require it before Use.php runs its top-level instantiation. The specialized Box autoloads
// via XPHP\Generated.
require $fixture->targetDir . '/lib.php';
require $fixture->targetDir . '/Use.php';

Assert::assertSame('pongspin', $result);
