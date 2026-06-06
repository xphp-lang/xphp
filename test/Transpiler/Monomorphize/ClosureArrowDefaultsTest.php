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
 * Tests for P5.7: defaults on closure / arrow generic parameters
 * (`function<T = int>(...)`, `fn<T = string>(...)`).
 */
final class ClosureArrowDefaultsTest extends TestCase
{
    public function testClosureWithSingleDefaultUsesPadding(): void
    {
        $dir = $this->mkdir('cdef-single');
        file_put_contents($dir . '/Use.xphp', <<<'PHP'
        <?php
        namespace App\CDefSingle;
        $f = function<T = int>(T $x): T { return $x; };
        $r = $f::<>(42);
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
        self::assertContains('r=42;', $output);

        $this->rrmdir(dirname($dir));
    }

    public function testArrowWithSingleDefaultUsesPadding(): void
    {
        $dir = $this->mkdir('adef-single');
        file_put_contents($dir . '/Use.xphp', <<<'PHP'
        <?php
        namespace App\ADefSingle;
        $f = fn<T = int>(T $x): T => $x;
        $r = $f::<>(7);
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
        self::assertContains('r=7;', $output);

        $this->rrmdir(dirname($dir));
    }

    public function testClosureWithTrailingDefaultPadsAtCallSite(): void
    {
        $dir = $this->mkdir('cdef-trail');
        file_put_contents($dir . '/Use.xphp', <<<'PHP'
        <?php
        namespace App\CDefTrail;
        $f = function<A, B = string>(A $a, B $b): array { return [$a, $b]; };
        $r = $f::<int>(10, 'hi');
        PHP);

        $this->compile($dir);
        $out = file_get_contents($dir . '/dist/Use.php');
        self::assertIsString($out);
        // Padded to <int, string> -- the specialized function reflects that.
        self::assertMatchesRegularExpression(
            '/function closure_f_T_[0-9a-f]+\(int \$a, string \$b\)/',
            $out,
        );

        $this->rrmdir(dirname($dir));
    }

    public function testClosureWithDefaultReferringToEarlierParam(): void
    {
        $dir = $this->mkdir('cdef-ref');
        file_put_contents($dir . '/Use.xphp', <<<'PHP'
        <?php
        namespace App\CDefRef;
        $f = function<A, B = A>(A $a, B $b): array { return [$a, $b]; };
        $r = $f::<int>(1, 2);
        PHP);

        $this->compile($dir);
        $out = file_get_contents($dir . '/dist/Use.php');
        self::assertIsString($out);
        // B padded to int (same as A), so both params end up as int.
        self::assertMatchesRegularExpression(
            '/function closure_f_T_[0-9a-f]+\(int \$a, int \$b\)/',
            $out,
        );

        $this->rrmdir(dirname($dir));
    }

    public function testMissingRequiredParamRaises(): void
    {
        // Function<A, B = int> -- A is required, no default. Calling
        // with `<>` must error: padding fails on missing required A.
        $dir = $this->mkdir('cdef-req');
        file_put_contents($dir . '/Use.xphp', <<<'PHP'
        <?php
        namespace App\CDefReq;
        $f = function<A, B = int>(A $a, B $b): array { return [$a, $b]; };
        $r = $f::<>(1, 2);
        PHP);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('has no default');
        $this->compile($dir);
        $this->rrmdir(dirname($dir));
    }

    public function testStaticClosureDefaultStillRejectedAtParse(): void
    {
        // Per-form gating: static-closure specialization didn't ship,
        // so defaults stay rejected for the `static function<T = ...>` form.
        $dir = $this->mkdir('cdef-static');
        file_put_contents($dir . '/Use.xphp', <<<'PHP'
        <?php
        namespace App\CDefStatic;
        $f = static function<T = int>(T $x): T { return $x; };
        PHP);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('static closures');
        $this->compile($dir);
        $this->rrmdir(dirname($dir));
    }

    public function testDefaultOnClosureWithUseClause(): void
    {
        // Defaults + use(): both features compose.
        $dir = $this->mkdir('cdef-use');
        file_put_contents($dir . '/Use.xphp', <<<'PHP'
        <?php
        namespace App\CDefUse;
        $base = 100;
        $f = function<T = int>(T $x) use ($base) { return $x + $base; };
        $r = $f::<>(5);
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
        self::assertContains('r=105;', $output);

        $this->rrmdir(dirname($dir));
    }

    public function testDefaultOnArrowWithImplicitCapture(): void
    {
        // Defaults + arrow implicit captures together.
        $dir = $this->mkdir('adef-cap');
        file_put_contents($dir . '/Use.xphp', <<<'PHP'
        <?php
        namespace App\ADefCap;
        $y = 10;
        $f = fn<T = int>(T $x): T => $x + $y;
        $r = $f::<>(3);
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
        self::assertContains('r=13;', $output);

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
