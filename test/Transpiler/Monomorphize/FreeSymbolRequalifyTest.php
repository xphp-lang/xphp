<?php

declare(strict_types=1);

namespace XPHP\Transpiler\Monomorphize;

use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use XPHP\TestSupport\CompiledFixture;

/**
 * A generic class body is relocated out of its origin namespace into XPHP\Generated\… when it
 * specializes, so an unqualified free-function call or const fetch would rebind against the
 * generated namespace (then the global fallback) instead of the origin — a wrong symbol or a
 * runtime fatal. The Specializer re-qualifies each such reference to the FQN the resolver
 * recorded, but only when the compilation unit defines it; builtins, magic constants, and any
 * unknown name keep PHP's global resolution. These tests compile AND execute the output.
 */
final class FreeSymbolRequalifyTest extends TestCase
{
    #[RunInSeparateProcess]
    public function testBareQualifiedConstAndBuiltinReferencesBindCorrectlyAtRuntime(): void
    {
        // scale(1)=10 + BONUS=5 + Sub\tweak(2)=3 + strlen('ab')=2 + (true?0:99)=0 = 20.
        // Proves: bare fn + bare const + qualified fn re-qualify to the App symbols, while the
        // builtin `strlen` and magic constant `true` stay on the global fallback (a wrong bind
        // would fatal with "undefined function XPHP\Generated\…\scale" or miscompute).
        $fixture = CompiledFixture::compile(
            __DIR__ . '/../../fixture/compile/free_symbol_requalify/source',
            'free-symbol-requalify',
        );
        try {
            $fixture->registerAutoload('App\\FreeSym');
            require __DIR__ . '/../../fixture/compile/free_symbol_requalify/verify/runtime.php';
        } finally {
            $fixture->cleanup();
        }
    }

    #[RunInSeparateProcess]
    public function testUseFunctionAndUseConstImportsBindTheImportedNamespaceAtRuntime(): void
    {
        // `use function Vendor\make; use const Vendor\RATE;` in an App-namespaced template:
        // make(3)=6 + RATE=100 = 106. The imports do not travel with the relocated body, so the
        // references are fully-qualified to Vendor's symbols — a naive `App\make` would fatal.
        $fixture = CompiledFixture::compile(
            __DIR__ . '/../../fixture/compile/free_symbol_use_import/source',
            'free-symbol-use-import',
        );
        try {
            $fixture->registerAutoload('App', 'Vendor');
            require __DIR__ . '/../../fixture/compile/free_symbol_use_import/verify/runtime.php';
        } finally {
            $fixture->cleanup();
        }
    }

    #[RunInSeparateProcess]
    public function testGroupUseFunctionAndConstImportsBindTheImportedNamespaceAtRuntime(): void
    {
        // `use function Vendor\{make, scale}; use Vendor\{const RATE, const STEP};` in an App template:
        // make(3)=6 + scale(1)=10 + RATE=100 + STEP=7 = 123. Group-form imports must reach the same
        // function/const symbol maps as the single-import form, or the relocated body fatals on `make`.
        $fixture = CompiledFixture::compile(
            __DIR__ . '/../../fixture/compile/free_symbol_group_use_import/source',
            'free-symbol-group-use-import',
        );
        try {
            $fixture->registerAutoload('App', 'Vendor');
            require __DIR__ . '/../../fixture/compile/free_symbol_group_use_import/verify/runtime.php';
        } finally {
            $fixture->cleanup();
        }
    }

    #[RunInSeparateProcess]
    public function testGapFilledCovariantMembersReQualifyTheirFreeSymbolsAtRuntime(): void
    {
        // A covariant diamond gap-fills `contains`/`indexOf` onto the concrete specs (Lst, Bag) AFTER the
        // Phase 2.3 relocation sweep. Those erasable bodies call an in-unit free function `tally` and read
        // an in-unit const `OFFSET`; without a re-qualify pass over the gap-filled members the bare names
        // rebind to XPHP\Generated and fatal on the upcast call. contains(absent)=true, indexOf(absent)=1.
        $fixture = CompiledFixture::compile(
            __DIR__ . '/../../fixture/compile/covariant_gapfill_free_symbol/source',
            'covariant-gapfill-free-symbol',
        );
        try {
            $fixture->registerAutoload('App');
            require __DIR__ . '/../../fixture/compile/covariant_gapfill_free_symbol/verify/runtime.php';
        } finally {
            $fixture->cleanup();
        }
    }
}
