<?php

declare(strict_types=1);

namespace XPHP\Transpiler\Monomorphize;

use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use XPHP\TestSupport\CompiledFixture;
use XPHP\TestSupport\SnapshotHash;

/**
 * Fixture-based coverage of the four Phase 5 dispatcher consumers
 * (P5.4 capture-free, P5.5 arrow, P5.6 `use ()`, P5.7 defaults). The
 * inline-source unit tests in `ClosureDispatcherTest`,
 * `ArrowSpecializationTest`, `UseClosureSpecializationTest`, and
 * `ClosureArrowDefaultsTest` cover the same paths at a higher density;
 * this file pins the durable fixture artifacts so the emitted PHP
 * shape (dispatcher closure + match arms + lifted-param specializations)
 * stays observable in `test/fixture/`.
 */
final class DispatcherFixtureIntegrationTest extends TestCase
{
    public function testCaptureFreeFixtureRoutesViaDispatcher(): void
    {
        // Fixture: `closure_generic/`. A capture-free `function<K, V>(...)`
        // with two duplicate-tuple call sites that dedupe to a single
        // specialization.
        $fixture = CompiledFixture::compile(
            __DIR__ . '/../../fixture/compile/closure_generic/source',
            'disp-capture-free',
        );
        try {
            $out = file_get_contents($fixture->targetDir . '/Use.php');

            SnapshotHash::assertMatches(
                __DIR__ . '/../../fixture/compile/closure_generic/verify/testCaptureFreeFixtureRoutesViaDispatcher/Use.expected.php',
                $out,
            );

            // Structural invariants kept alongside the snapshot:
            // two same-tuple calls dedupe to a single specialization.
            preg_match_all('/function closure_pair_T_[0-9a-f]+\(/', $out, $matches);
            self::assertCount(1, $matches[0]);
            // Both call sites carry the tag prefix.
            preg_match_all("/\\\$pair\\('T_[0-9a-f]+', /", $out, $callMatches);
            self::assertCount(2, $callMatches[0]);
        } finally {
            $fixture->cleanup();
        }
    }

    #[RunInSeparateProcess]
    public function testArrowFixtureSynthesizesUseClauseFromImplicitCapture(): void
    {
        // Fixture: `closure_dispatcher_arrow/`. The arrow body references
        // `$y` from the outer scope; the analyzer harvests it and the
        // dispatcher's `use ($y)` snapshots the value at the assign site.
        $fixture = CompiledFixture::compile(
            __DIR__ . '/../../fixture/compile/closure_dispatcher_arrow/source',
            'disp-arrow',
        );
        try {
            $out = file_get_contents($fixture->targetDir . '/Use.php');

            SnapshotHash::assertMatches(
                __DIR__ . '/../../fixture/compile/closure_dispatcher_arrow/verify/testArrowFixtureSynthesizesUseClauseFromImplicitCapture/Use.expected.php',
                $out,
            );

            $runtime = require __DIR__ . '/../../fixture/compile/closure_dispatcher_arrow/verify/runtime.php';
            $runtime($fixture);
        } finally {
            $fixture->cleanup();
        }
    }

    #[RunInSeparateProcess]
    public function testUseClauseFixturePropagatesByRefThroughDispatcher(): void
    {
        // Fixture: `closure_dispatcher_use_clause/`. The `use (&$counter)`
        // by-ref capture must survive: dispatcher's `use (&$counter)`,
        // lifted param `mixed &$counter`, named-arg forwarding to the
        // specialized fn -- all preserve ref-ness so the outer $counter
        // mutates.
        $fixture = CompiledFixture::compile(
            __DIR__ . '/../../fixture/compile/closure_dispatcher_use_clause/source',
            'disp-use-clause',
        );
        try {
            $out = file_get_contents($fixture->targetDir . '/Use.php');

            SnapshotHash::assertMatches(
                __DIR__ . '/../../fixture/compile/closure_dispatcher_use_clause/verify/testUseClauseFixturePropagatesByRefThroughDispatcher/Use.expected.php',
                $out,
            );

            // Structural invariant kept: two distinct specializations
            // (T=int and T=string) survive the snapshot's first-seen-order
            // normalization only if the count is checked explicitly.
            preg_match_all('/function closure_f_T_[0-9a-f]+\(/', $out, $matches);
            self::assertCount(2, $matches[0]);

            $runtime = require __DIR__ . '/../../fixture/compile/closure_dispatcher_use_clause/verify/runtime.php';
            $runtime($fixture);
        } finally {
            $fixture->cleanup();
        }
    }

    #[RunInSeparateProcess]
    public function testDefaultsFixturePadsEmptyTurbofish(): void
    {
        // Fixture: `closure_dispatcher_defaults/`. Empty-turbofish
        // `$f::<>()` calls trigger `Registry::padArgsWithDefaults` at
        // record time; the per-call-site tag stash ensures the runtime
        // tag matches the dispatcher arm built from the padded tuple.
        $fixture = CompiledFixture::compile(
            __DIR__ . '/../../fixture/compile/closure_dispatcher_defaults/source',
            'disp-defaults',
        );
        try {
            $out = file_get_contents($fixture->targetDir . '/Use.php');

            SnapshotHash::assertMatches(
                __DIR__ . '/../../fixture/compile/closure_dispatcher_defaults/verify/testDefaultsFixturePadsEmptyTurbofish/Use.expected.php',
                $out,
            );

            // Structural invariant: the empty turbofish `$f::<>(42)` and the
            // explicit `$f::<string>('hi')` produce two distinct
            // specializations on $f.
            preg_match_all('/function closure_f_T_[0-9a-f]+\(/', $out, $fMatches);
            self::assertCount(2, $fMatches[0]);

            $runtime = require __DIR__ . '/../../fixture/compile/closure_dispatcher_defaults/verify/runtime.php';
            $runtime($fixture);
        } finally {
            $fixture->cleanup();
        }
    }
}
