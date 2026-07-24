<?php

declare(strict_types=1);

namespace XPHP\Transpiler\Monomorphize;

use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use XPHP\TestSupport\CompiledFixture;

/**
 * End-to-end pins for generic-marker/AST alignment: every declaration that
 * FOLLOWS a stripped span (a multi-line `<…>` clause, turbofish arg list, or
 * `T[]` sugar span) must still specialize, and the emitted program must run.
 * Misalignment is silent — compile and check both pass while the output
 * carries raw un-erased type-parameter hints that fatal at runtime.
 */
final class MarkerAlignmentIntegrationTest extends TestCase
{
    #[RunInSeparateProcess]
    public function testDeclarationsAfterMultiLineSpansStillSpecializeAndRun(): void
    {
        $fixture = CompiledFixture::compile(
            __DIR__ . '/../../fixture/compile/multiline_generic_markers/source',
            'multiline-generic-markers',
        );
        $fixture->registerAutoload('App\\MultilineMarkers');
        try {
            $runtime = require __DIR__ . '/../../fixture/compile/multiline_generic_markers/verify/runtime.php';
            $runtime($fixture);
        } finally {
            $fixture->cleanup();
        }
    }

    #[RunInSeparateProcess]
    public function testAttributedAndStaticGenericClosuresSpecializeAndRun(): void
    {
        $fixture = CompiledFixture::compile(
            __DIR__ . '/../../fixture/compile/attributed_generic_closures/source',
            'attributed-generic-closures',
        );
        $fixture->registerAutoload('App\\AttributedClosures');
        try {
            $runtime = require __DIR__ . '/../../fixture/compile/attributed_generic_closures/verify/runtime.php';
            $runtime($fixture);
        } finally {
            $fixture->cleanup();
        }
    }

    #[RunInSeparateProcess]
    public function testSameLineSameSpellingPairsBindTheirOwnMarkersAndRun(): void
    {
        $fixture = CompiledFixture::compile(
            __DIR__ . '/../../fixture/compile/same_line_marker_pairs/source',
            'same-line-marker-pairs',
        );
        $fixture->registerAutoload('App\\SameLinePairs');
        try {
            $runtime = require __DIR__ . '/../../fixture/compile/same_line_marker_pairs/verify/runtime.php';
            $runtime($fixture);
        } finally {
            $fixture->cleanup();
        }
    }

    #[RunInSeparateProcess]
    public function testSplitDeclarationHeadersSpecializeAndRun(): void
    {
        $fixture = CompiledFixture::compile(
            __DIR__ . '/../../fixture/compile/split_declaration_headers/source',
            'split-declaration-headers',
        );
        $fixture->registerAutoload('App\\SplitHeaders');
        try {
            $runtime = require __DIR__ . '/../../fixture/compile/split_declaration_headers/verify/runtime.php';
            $runtime($fixture);
        } finally {
            $fixture->cleanup();
        }
    }
}
