<?php

declare(strict_types=1);

namespace XPHP\Transpiler\Monomorphize;

use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use XPHP\TestSupport\CompiledFixture;

/**
 * End-to-end pins for fully-qualified (`\App\Box`) and relative
 * (`namespace\Box`) spellings at generic call sites — turbofish, empty
 * turbofish, bare generic type hints, in-template references, and generic
 * function calls. Every spelling must reach the same specialization as the
 * bare form and the emitted program must run.
 */
final class QualifiedCallSiteIntegrationTest extends TestCase
{
    #[RunInSeparateProcess]
    public function testQualifiedSpellingsSpecializeAndRun(): void
    {
        $fixture = CompiledFixture::compile(
            __DIR__ . '/../../fixture/compile/qualified_generic_call_sites/source',
            'qualified-generic-call-sites',
        );
        $fixture->registerAutoload('App\\QualifiedCalls');
        try {
            require __DIR__ . '/../../fixture/compile/qualified_generic_call_sites/verify/runtime.php';
        } finally {
            $fixture->cleanup();
        }
    }

    #[RunInSeparateProcess]
    public function testRelativeNamesInTemplatesBindToTheCurrentNamespaceAndRun(): void
    {
        $fixture = CompiledFixture::compile(
            __DIR__ . '/../../fixture/compile/relative_names_in_templates/source',
            'relative-names-in-templates',
        );
        $fixture->registerAutoload('App\\RelativeTemplates', 'Other');
        try {
            require __DIR__ . '/../../fixture/compile/relative_names_in_templates/verify/runtime.php';
        } finally {
            $fixture->cleanup();
        }
    }

    #[RunInSeparateProcess]
    public function testQualifiedBareNewsSynthesizeDefaultsAndRun(): void
    {
        $fixture = CompiledFixture::compile(
            __DIR__ . '/../../fixture/compile/qualified_bare_new_defaults/source',
            'qualified-bare-new-defaults',
        );
        $fixture->registerAutoload('App\\QualifiedDefaults', 'Other');
        try {
            require __DIR__ . '/../../fixture/compile/qualified_bare_new_defaults/verify/runtime.php';
        } finally {
            $fixture->cleanup();
        }
    }
}
