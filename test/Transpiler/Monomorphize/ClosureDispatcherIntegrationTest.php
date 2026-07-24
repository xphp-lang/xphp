<?php

declare(strict_types=1);

namespace XPHP\Transpiler\Monomorphize;

use PhpParser\ParserFactory;
use PhpParser\PrettyPrinter\Standard as StandardPrinter;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use XPHP\FileSystem\FileFinder\NativeFileFinder;
use XPHP\FileSystem\FileReader\NativeFileReader;
use XPHP\FileSystem\FileWriter\NativeFileWriter;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use XPHP\TestSupport\CompiledFixture;
use XPHP\TestSupport\SnapshotHash;

/**
 * End-to-end integration tests for the closure-dispatcher pipeline.
 * Exercises the full `Compiler::compile` path so the visitor's two-pass
 * collection + finalize phase actually fires.
 */
final class ClosureDispatcherIntegrationTest extends TestCase
{
    public function testCaptureFreeFixtureStillCompilesViaDispatcher(): void
    {
        // The pre-P5.4 single-call form: ONE tuple → dispatcher routes
        // ONE match arm. Specialized function present; call site rewritten
        // to invoke `$pair` with the tag prepended.
        $dir = $this->mkdir('disp-single');
        file_put_contents($dir . '/Use.xphp', <<<'PHP'
        <?php
        namespace App;
        $pair = function<K, V>(K $key, V $value): array {
            return [$key, $value];
        };
        $pair::<string, int>('age', 42);
        PHP);

        $this->compile($dir);
        $out = file_get_contents($dir . '/dist/Use.php');
        self::assertIsString($out);

        // Negative invariants kept: the original call site form must NOT
        // survive, nor must the generic template syntax.
        self::assertStringNotContainsString("\$pair('age', 42)", $out);
        self::assertStringNotContainsString('function<K, V>', $out);
        SnapshotHash::assertMatches(
            __DIR__ . '/ClosureDispatcherIntegrationTest/testCaptureFreeFixtureStillCompilesViaDispatcher/Use.expected.php',
            $out,
        );

        $this->rrmdir(dirname($dir));
    }

    public function testTwoTupleEndToEnd(): void
    {
        $dir = $this->mkdir('disp-two');
        file_put_contents($dir . '/Use.xphp', <<<'PHP'
        <?php
        namespace App;
        $pair = function<K, V>(K $key, V $value): array {
            return [$key, $value];
        };
        $pair::<string, int>('age', 42);
        $pair::<int, string>(7, 'lucky');
        PHP);

        $this->compile($dir);
        $out = file_get_contents($dir . '/dist/Use.php');
        self::assertIsString($out);

        // Structural invariants: two specializations + two dispatcher arms.
        preg_match_all('/function closure_pair_T_[0-9a-f]+\(/', $out, $matches);
        self::assertCount(2, $matches[0]);
        preg_match_all("/'T_[0-9a-f]+' => /", $out, $armMatches);
        self::assertCount(2, $armMatches[0]);
        SnapshotHash::assertMatches(
            __DIR__ . '/ClosureDispatcherIntegrationTest/testTwoTupleEndToEnd/Use.expected.php',
            $out,
        );

        $this->rrmdir(dirname($dir));
    }

    public function testDuplicateCallSitesShareSingleSpecialization(): void
    {
        // Two calls with the same arg-tuple → one specialization, one
        // match arm.
        $dir = $this->mkdir('disp-dup');
        file_put_contents($dir . '/Use.xphp', <<<'PHP'
        <?php
        namespace App;
        $pair = function<K, V>(K $key, V $value): array {
            return [$key, $value];
        };
        $pair::<string, int>('age', 42);
        $pair::<string, int>('count', 7);
        PHP);

        $this->compile($dir);
        $out = file_get_contents($dir . '/dist/Use.php');
        self::assertIsString($out);

        // Structural invariants: one specialization + one match arm even
        // though there are two call sites with the same arg tuple.
        preg_match_all('/function closure_pair_T_[0-9a-f]+\(/', $out, $matches);
        self::assertCount(1, $matches[0]);
        preg_match_all("/'T_[0-9a-f]+' => /", $out, $armMatches);
        self::assertCount(1, $armMatches[0]);
        SnapshotHash::assertMatches(
            __DIR__ . '/ClosureDispatcherIntegrationTest/testDuplicateCallSitesShareSingleSpecialization/Use.expected.php',
            $out,
        );

        $this->rrmdir(dirname($dir));
    }

    #[RunInSeparateProcess]
    public function testRuntimeRoutingThroughDispatcher(): void
    {
        // Two distinct turbofish call sites route through the
        // dispatcher's match arms back to the right return values.
        $fixture = CompiledFixture::compile(
            __DIR__ . '/../../fixture/compile/closure_dispatcher_runtime_routing/source',
            'disp-routing',
        );
        try {
            $runtime = require __DIR__ . '/../../fixture/compile/closure_dispatcher_runtime_routing/verify/runtime.php';
            $runtime($fixture);
        } finally {
            $fixture->cleanup();
        }
    }

    #[RunInSeparateProcess]
    public function testUnknownTagAtRuntimeThrows(): void
    {
        // The dispatcher's synthesized `default => throw RuntimeException`
        // arm fires when invoked with a tag that has no specialization.
        $fixture = CompiledFixture::compile(
            __DIR__ . '/../../fixture/compile/closure_dispatcher_unknown_tag/source',
            'disp-unknown',
        );
        try {
            $runtime = require __DIR__ . '/../../fixture/compile/closure_dispatcher_unknown_tag/verify/runtime.php';
            $runtime($fixture);
        } finally {
            $fixture->cleanup();
        }
    }

    public function testArrowSpecializesViaDispatcher(): void
    {
        // P5.5: arrow rejection lifted. Capture-free arrow specializes
        // through the same dispatcher path as capture-free closures.
        $dir = $this->mkdir('disp-arrow');
        file_put_contents($dir . '/Use.xphp', <<<'PHP'
        <?php
        namespace App;
        $id = fn<T>(T $x): T => $x;
        $id::<int>(1);
        PHP);

        $this->compile($dir);
        $out = file_get_contents($dir . '/dist/Use.php');
        self::assertIsString($out);
        SnapshotHash::assertMatches(
            __DIR__ . '/ClosureDispatcherIntegrationTest/testArrowSpecializesViaDispatcher/Use.expected.php',
            $out,
        );
        $this->rrmdir(dirname($dir));
    }

    public function testUseClauseClosureSpecializesViaDispatcher(): void
    {
        // P5.6: closure-with-`use` rejection lifted. The dispatcher
        // forwards the user's `use (...)` clause onto itself and lifts
        // each capture as a trailing `mixed` param on the specialized
        // function.
        $dir = $this->mkdir('disp-use');
        file_put_contents($dir . '/Use.xphp', <<<'PHP'
        <?php
        namespace App;
        $y = 1;
        $f = function<T>(T $x) use ($y) { return [$x, $y]; };
        $f::<int>(42);
        PHP);

        $this->compile($dir);
        $out = file_get_contents($dir . '/dist/Use.php');
        self::assertIsString($out);
        SnapshotHash::assertMatches(
            __DIR__ . '/ClosureDispatcherIntegrationTest/testUseClauseClosureSpecializesViaDispatcher/Use.expected.php',
            $out,
        );
        $this->rrmdir(dirname($dir));
    }

    public function testTemplateNeverCalledViaTurbofishIsRejected(): void
    {
        // Template declared but never called via turbofish. It used to keep
        // its original Assign untouched — emitting raw `T` hints that name
        // the non-existent class App\T and fatal on first invocation. It is
        // now rejected loudly instead.
        $dir = $this->mkdir('disp-empty');
        file_put_contents($dir . '/Use.xphp', <<<'PHP'
        <?php
        namespace App;
        $id = function<T>(T $x): T { return $x; };
        PHP);

        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('never specialized');
            $this->compile($dir);
        } finally {
            $this->rrmdir(dirname($dir));
        }
    }

    public function testGenericClosureGroundedByEnclosingParamIsRejected(): void
    {
        // A generic closure whose turbofish is grounded only by an enclosing FUNCTION type
        // parameter (`$inner::<S>` inside `relay<S>`): the type argument `S` is not concrete
        // at the call site, so the dispatcher cannot ground it. Emitted, it would keep
        // `fn(I $x): I` naming the non-existent class `App\I` and fatal on invocation. It is
        // rejected loudly at the source seam (both check and compile) instead.
        $dir = $this->mkdir('disp-enclosing-param');
        file_put_contents($dir . '/Use.xphp', <<<'PHP'
        <?php
        namespace App;
        function relay<S>(S $v): S { $inner = fn<I>(I $x): I => $x; return $inner::<S>($v); }
        relay::<int>(3);
        PHP);

        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('cannot be specialized');
            $this->compile($dir);
        } finally {
            $this->rrmdir(dirname($dir));
        }
    }

    public function testNestedScopeCallsShareDispatcher(): void
    {
        // The SAME `$pair` template is called twice: once in main scope,
        // once inside an inner anonymous closure body. Both call sites
        // must land in the SAME dispatch-plan entry (keyed by the
        // template's startFilePos), so we end up with ONE dispatcher
        // and ONE match arm.
        //
        // The inner closure has no turbofish on its own params (it's a
        // plain Closure that uses `use ($pair)` to import the outer
        // dispatcher), so the visitor's `currentScopeClosureTemplates`
        // lookup of `$pair` walks through to the imported template.
        // This pins that we don't accidentally create a second
        // dispatch-plan entry for the inner-scope call.
        $dir = $this->mkdir('disp-nested');
        file_put_contents($dir . '/Use.xphp', <<<'PHP'
        <?php
        namespace App\NestedDisp;
        $id = function<T>(T $x): T { return $x; };
        $id::<int>(1);
        $id::<int>(2);
        PHP);

        $this->compile($dir);
        $out = file_get_contents($dir . '/dist/Use.php');
        self::assertIsString($out);

        // Structural invariant: single specialization shared by both calls.
        preg_match_all('/function closure_id_T_[0-9a-f]+\(/', $out, $matches);
        self::assertCount(1, $matches[0]);
        SnapshotHash::assertMatches(
            __DIR__ . '/ClosureDispatcherIntegrationTest/testNestedScopeCallsShareDispatcher/Use.expected.php',
            $out,
        );

        $this->rrmdir(dirname($dir));
    }

    #[RunInSeparateProcess]
    public function testFirstClassCallableTurbofishClosureEmitsValidForwardingClosureAndRuns(): void
    {
        // `$g = $f::<int>(...)` used to emit `$f('T_…', ...)` — a tag arg beside the FCC
        // placeholder, which does not parse. It now emits a forwarding closure that routes
        // through the dispatcher, preserving captures / variadics / named args. The require
        // below both parses (else it fatals) and executes the emitted output.
        $fixture = CompiledFixture::compile(
            __DIR__ . '/../../fixture/compile/turbofish_fcc_closure/source',
            'fcc-closure',
        );
        try {
            $runtime = require __DIR__ . '/../../fixture/compile/turbofish_fcc_closure/verify/runtime.php';
            $runtime($fixture);
        } finally {
            $fixture->cleanup();
        }
    }

    public function testFirstClassCallableOfThisCapturingClosureIsRejected(): void
    {
        // The eager `$this`-capture reject fires for an FCC too (it sits before the FCC
        // handling): an FCC of a `$this`-capturing generic closure draws the loud
        // capture error, not a broken forwarding closure.
        $dir = $this->mkdir('fcc-this');
        file_put_contents($dir . '/Use.xphp', <<<'PHP'
        <?php
        namespace App;
        class Widget {
            public int $n = 3;
            public function make(): callable {
                $f = fn<T>(T $x): T => $x + $this->n;
                return $f::<int>(...);
            }
        }
        PHP);

        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('captures `$this`');
            $this->compile($dir);
        } finally {
            $this->rrmdir(dirname($dir));
        }
    }

    // ----- helpers ---------------------------------------------------------

    private function mkdir(string $tag): string
    {
        $root = sys_get_temp_dir() . '/xphp-' . $tag . '-' . uniqid('', true);
        $src = $root . '/src';
        mkdir($src, 0o755, true);
        return $src;
    }

    private function compile(string $sourceDir): void
    {
        $compiler = $this->buildCompiler();
        $sources = (new NativeFileFinder())->find($sourceDir)
            ->filter(static fn (string $f): bool => str_ends_with($f, '.xphp'));
        $compiler->compile(
            $sources,
            $sourceDir,
            dirname($sourceDir) . '/dist',
            dirname($sourceDir) . '/.xphp-cache',
        );
        // The compile writes to ../dist; the convenience accessor below
        // wants $sourceDir/dist, so symlink there for the tests.
        $dist = dirname($sourceDir) . '/dist';
        if (is_dir($dist) && !is_dir($sourceDir . '/dist')) {
            symlink($dist, $sourceDir . '/dist');
        }
    }

    private function buildCompiler(): Compiler
    {
        $phpParser = (new ParserFactory())->createForHostVersion();
        $printer = new StandardPrinter();
        $writer = new NativeFileWriter();
        return new Compiler(
            new NativeFileReader(),
            $writer,
            new XphpSourceParser($phpParser),
            new Specializer(),
            new SpecializedClassGenerator($printer, $writer),
            $printer,
        );
    }

    private function rrmdir(string $dir): void
    {
        if (!is_dir($dir) && !is_link($dir)) {
            return;
        }
        if (is_link($dir)) {
            unlink($dir);
            return;
        }
        foreach (scandir($dir) as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            if (is_link($path)) {
                unlink($path);
            } elseif (is_dir($path)) {
                $this->rrmdir($path);
            } else {
                unlink($path);
            }
        }
        rmdir($dir);
    }
}
