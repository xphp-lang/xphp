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

/**
 * Tests for P5.6: generic closures with explicit `use (...)` clauses.
 * The dispatcher carries the user's `use` verbatim and each capture is
 * lifted as a trailing `mixed` (or `mixed &`) param on the specialized
 * function.
 */
final class UseClosureSpecializationTest extends TestCase
{
    public function testUseClauseByValueRuntimeContract(): void
    {
        // Capture-at-declaration semantics: `use ($y)` snapshots $y=1
        // even though the outer $y reassigns to 2 before the call.
        $dir = $this->mkdir('use-byval');
        file_put_contents($dir . '/Use.xphp', <<<'PHP'
        <?php
        namespace App\UseByVal;
        $y = 1;
        $f = function<T>(T $x) use ($y) { return $x + $y; };
        $y = 2;
        $result = $f::<int>(42);
        PHP);

        $this->compile($dir);
        $runScript = $dir . '/run.php';
        file_put_contents($runScript, <<<PHP
        <?php
        require '{$dir}/dist/Use.php';
        echo "result={\$result};y={\$y};";
        PHP);
        [$exit, $output] = $this->execScript($runScript);
        self::assertSame(0, $exit, "Run failed:\n" . implode("\n", $output));
        self::assertContains('result=43;y=2;', $output);

        $this->rrmdir(dirname($dir));
    }

    public function testUseClauseByRefCaptureMutatesOuter(): void
    {
        // `use (&$y)`: mutations inside the body propagate to the outer
        // scope. The dispatcher's `use (&$y)` plus the lifted `mixed &$y`
        // param + named-arg forwarding preserves the reference all the
        // way to the specialized function's body.
        $dir = $this->mkdir('use-byref');
        file_put_contents($dir . '/Use.xphp', <<<'PHP'
        <?php
        namespace App\UseByRef;
        $y = 1;
        $f = function<T>(T $x) use (&$y): T {
            $y = $x;
            return $y;
        };
        $a = $f::<int>(99);
        PHP);

        $this->compile($dir);
        $runScript = $dir . '/run.php';
        file_put_contents($runScript, <<<PHP
        <?php
        require '{$dir}/dist/Use.php';
        echo "a={\$a};y={\$y};";
        PHP);
        [$exit, $output] = $this->execScript($runScript);
        self::assertSame(0, $exit, "Run failed:\n" . implode("\n", $output));
        self::assertContains('a=99;y=99;', $output);

        $this->rrmdir(dirname($dir));
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

        // Dispatcher's use clause has the `&`.
        self::assertStringContainsString('use (&$y)', $out);
        // Specialized function declares the lifted param with `&`.
        self::assertMatchesRegularExpression(
            '/function closure_f_T_[0-9a-f]+\(int \$x, mixed &\$y\)/',
            $out,
        );

        $this->rrmdir(dirname($dir));
    }

    public function testUseClauseMultipleMixedRefAndValueCaptures(): void
    {
        $dir = $this->mkdir('use-mixed');
        file_put_contents($dir . '/Use.xphp', <<<'PHP'
        <?php
        namespace App\UseMixed;
        $a = 10;
        $b = 20;
        $f = function<T>(T $x) use ($a, &$b) {
            $b = $b + 5;       // mutates outer $b
            return $x + $a + $b;
        };
        $r = $f::<int>(1);
        PHP);

        $this->compile($dir);
        $runScript = $dir . '/run.php';
        file_put_contents($runScript, <<<PHP
        <?php
        require '{$dir}/dist/Use.php';
        echo "r={\$r};a={\$a};b={\$b};";
        PHP);
        [$exit, $output] = $this->execScript($runScript);
        self::assertSame(0, $exit, "Run failed:\n" . implode("\n", $output));
        // r = 1 + 10 + 25 = 36; a stays at 10; b mutated to 25.
        self::assertContains('r=36;a=10;b=25;', $output);

        $this->rrmdir(dirname($dir));
    }

    public function testUseClauseMultipleArgTuples(): void
    {
        $dir = $this->mkdir('use-tuples');
        file_put_contents($dir . '/Use.xphp', <<<'PHP'
        <?php
        namespace App\UseTuples;
        $tag = 'pre';
        $f = function<T>(T $x) use ($tag) { return $tag . ':' . $x; };
        $a = $f::<int>(1);
        $b = $f::<string>('two');
        PHP);

        $this->compile($dir);
        $out = file_get_contents($dir . '/dist/Use.php');
        self::assertIsString($out);
        preg_match_all('/function closure_f_T_[0-9a-f]+\(/', $out, $matches);
        self::assertCount(2, $matches[0]);

        $runScript = $dir . '/run.php';
        file_put_contents($runScript, <<<PHP
        <?php
        require '{$dir}/dist/Use.php';
        echo "a={\$a};b={\$b};";
        PHP);
        [$exit, $output] = $this->execScript($runScript);
        self::assertSame(0, $exit, "Run failed:\n" . implode("\n", $output));
        self::assertContains('a=pre:1;b=pre:two;', $output);

        $this->rrmdir(dirname($dir));
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

    public function testUseClauseCaptureNamedXphpArgsAutoRenames(): void
    {
        // Regression guard: a user variable captured via `use ($__xphp_args)`
        // collides with the dispatcher's own variadic param name. The
        // auto-rename machinery from P5.5 applies to closures too.
        $dir = $this->mkdir('use-reserved');
        file_put_contents($dir . '/Use.xphp', <<<'PHP'
        <?php
        namespace App\UseReserved;
        $__xphp_args = 200;
        $f = function<T>(T $x) use ($__xphp_args) { return $x + $__xphp_args; };
        $r = $f::<int>(3);
        PHP);

        $this->compile($dir);
        $runScript = $dir . '/run.php';
        file_put_contents($runScript, <<<PHP
        <?php
        require '{$dir}/dist/Use.php';
        echo "r={\$r};";
        PHP);
        [$exit, $output] = $this->execScript($runScript);
        self::assertSame(0, $exit, "Run failed:\n" . implode("\n", $output));
        self::assertContains('r=203;', $output);

        $this->rrmdir(dirname($dir));
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
