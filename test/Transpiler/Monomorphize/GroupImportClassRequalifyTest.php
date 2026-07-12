<?php

declare(strict_types=1);

namespace XPHP\Transpiler\Monomorphize;

use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use XPHP\TestSupport\CompiledFixture;

/**
 * A generic class body is relocated out of its origin namespace into XPHP\Generated\…, so a class
 * reference in that body must be re-qualified to the import target. A SINGLE `use Vendor\Tool;` was
 * re-qualified correctly, but a GROUP `use Vendor\{Tool};` was not — its member was never stored in
 * the class/namespace use-map, so the reference fell back to the current namespace (`\App\Tool`) and
 * fataled at runtime ("Class App\Tool not found") behind a clean compile. This test compiles AND
 * executes the output.
 */
final class GroupImportClassRequalifyTest extends TestCase
{
    #[RunInSeparateProcess]
    public function testGroupImportedClassReQualifiesInARelocatedBodyAtRuntime(): void
    {
        // Box<int>::run() calls a group-imported Tool::ping() ('pong') and a single-imported
        // Widget::spin() ('spin') -> 'pongspin'. A group form that fell back to \App\Tool would fatal.
        $fixture = CompiledFixture::compile(
            __DIR__ . '/../../fixture/compile/group_import_class_requalify/source',
            'group-import-class-requalify',
        );
        try {
            $fixture->registerAutoload('App', 'Vendor');
            require __DIR__ . '/../../fixture/compile/group_import_class_requalify/verify/runtime.php';
        } finally {
            $fixture->cleanup();
        }
    }
}
