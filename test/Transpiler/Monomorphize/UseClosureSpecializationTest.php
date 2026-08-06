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
 * Tests for P5.6: generic closures with explicit `use (...)` clauses.
 * The dispatcher carries the user's `use` verbatim and each capture is
 * lifted as a trailing `mixed` (or `mixed &`) param on the specialized
 * function.
 */
final class UseClosureSpecializationTest extends TestCase
{
    #[RunInSeparateProcess]
    public function testUseClauseByValueRuntimeContract(): void
    {
        $fixture = CompiledFixture::compile(
            __DIR__ . '/../../fixture/compile/closure_use_by_value/source',
            'use-byval',
        );
        try {
            $runtime = require __DIR__ . '/../../fixture/compile/closure_use_by_value/verify/runtime.php';
            $runtime($fixture);
        } finally {
            $fixture->cleanup();
        }
    }

    #[RunInSeparateProcess]
    public function testUseClauseByRefCaptureMutatesOuter(): void
    {
        $fixture = CompiledFixture::compile(
            __DIR__ . '/../../fixture/compile/closure_use_by_ref/source',
            'use-byref',
        );
        try {
            $runtime = require __DIR__ . '/../../fixture/compile/closure_use_by_ref/verify/runtime.php';
            $runtime($fixture);
        } finally {
            $fixture->cleanup();
        }
    }

    public function testUseClauseByRefIsEmittedInBothDispatcherAndSpecialization(): void
    {
        // Inspect the compiled PHP shape to confirm `byRef` propagates
        // through both layers.
        $dir = $this->mkdir('use-byref-emit');
        file_put_contents($dir . '/Use.xphp', <<<'PHP'
        <?php
        namespace App\UseByRefEmit;
        $y = 1;
        $f = function<T>(T $x) use (&$y): T { return $x; };
        $f::<int>(1);
        PHP);

        $this->compile($dir);
        $out = file_get_contents($dir . '/dist/Use.php');
        self::assertIsString($out);
        SnapshotHash::assertMatches(
            __DIR__ . '/UseClosureSpecializationTest/testUseClauseByRefIsEmittedInBothDispatcherAndSpecialization/Use.expected.php',
            $out,
        );

        $this->rrmdir(dirname($dir));
    }

    #[RunInSeparateProcess]
    public function testUseClauseMultipleMixedRefAndValueCaptures(): void
    {
        $fixture = CompiledFixture::compile(
            __DIR__ . '/../../fixture/compile/closure_use_multiple_mixed_captures/source',
            'use-mixed',
        );
        try {
            $runtime = require __DIR__ . '/../../fixture/compile/closure_use_multiple_mixed_captures/verify/runtime.php';
            $runtime($fixture);
        } finally {
            $fixture->cleanup();
        }
    }

    #[RunInSeparateProcess]
    public function testUseClauseMultipleArgTuples(): void
    {
        $fixture = CompiledFixture::compile(
            __DIR__ . '/../../fixture/compile/closure_use_multiple_arg_tuples/source',
            'use-tuples',
        );
        try {
            $out = file_get_contents($fixture->targetDir . '/Use.php');
            self::assertIsString($out);

            // Structural invariant kept: two distinct specializations
            // (T=int and T=string).
            preg_match_all('/function closure_f_T_[0-9a-f]+\(/', $out, $matches);
            self::assertCount(2, $matches[0]);
            SnapshotHash::assertMatches(
                __DIR__ . '/../../fixture/compile/closure_use_multiple_arg_tuples/verify/testUseClauseMultipleArgTuples/Use.expected.php',
                $out,
            );

            $runtime = require __DIR__ . '/../../fixture/compile/closure_use_multiple_arg_tuples/verify/runtime.php';
            $runtime($fixture);
        } finally {
            $fixture->cleanup();
        }
    }

    public function testUseClauseStaticClosureStillRejected(): void
    {
        // P5.6 leaves the static-closure rejection in place. PHP's
        // `static function() use ($y) { ... }` semantics prevent
        // `$this` binding, which isn't a target we ship in this commit.
        $dir = $this->mkdir('use-static');
        file_put_contents($dir . '/Use.xphp', <<<'PHP'
        <?php
        namespace App\UseStatic;
        $y = 1;
        $f = static function<T>(T $x) use ($y) { return $x + $y; };
        $f::<int>(1);
        PHP);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Generic static closures cannot yet be specialized');
        $this->compile($dir);
        $this->rrmdir(dirname($dir));
    }

    public function testUseClauseClosureCapturingThisRejected(): void
    {
        // Closures (unlike arrows) can be bound to a `$this`. P5.6
        // rejects them eagerly because PHP doesn't allow `use ($this)`
        // and the specialized top-level function can't see the
        // enclosing class's `$this`.
        $dir = $this->mkdir('use-this');
        file_put_contents($dir . '/Use.xphp', <<<'PHP'
        <?php
        namespace App\UseThis;
        class Holder {
            public int $v = 5;
            public function go(): int {
                $f = function<T>(T $x): int {
                    return $x + $this->v;
                };
                return $f::<int>(2);
            }
        }
        PHP);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('captures `$this`');
        $this->compile($dir);
        $this->rrmdir(dirname($dir));
    }

    #[RunInSeparateProcess]
    public function testUseClauseCaptureNamedXphpArgsAutoRenames(): void
    {
        // Regression guard: a user variable captured via `use ($__xphp_args)`
        // collides with the dispatcher's own variadic param name. The
        // auto-rename machinery from P5.5 applies to closures too.
        $fixture = CompiledFixture::compile(
            __DIR__ . '/../../fixture/compile/closure_use_capture_named_xphp_args/source',
            'use-reserved',
        );
        try {
            $runtime = require __DIR__ . '/../../fixture/compile/closure_use_capture_named_xphp_args/verify/runtime.php';
            $runtime($fixture);
        } finally {
            $fixture->cleanup();
        }
    }

    // ----- helpers --------------------------------------------------------

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
        $dist = dirname($sourceDir) . '/dist';
        if (is_dir($dist) && !is_dir($sourceDir . '/dist')) {
            symlink($dist, $sourceDir . '/dist');
        }
    }

    /**
     * @return array{0: int, 1: list<string>}
     */
    private function execScript(string $script): array
    {
        $output = [];
        $exit = 0;
        exec('php ' . escapeshellarg($script) . ' 2>&1', $output, $exit);
        return [$exit, $output];
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
