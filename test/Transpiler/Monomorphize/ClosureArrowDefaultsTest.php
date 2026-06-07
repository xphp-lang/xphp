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
 * Tests for P5.7: defaults on closure / arrow generic parameters
 * (`function<T = int>(...)`, `fn<T = string>(...)`).
 */
final class ClosureArrowDefaultsTest extends TestCase
{
    #[RunInSeparateProcess]
    public function testClosureWithSingleDefaultUsesPadding(): void
    {
        $fixture = CompiledFixture::compile(
            __DIR__ . '/../../fixture/compile/closure_defaults_single/source',
            'cdef-single',
        );
        try {
            require __DIR__ . '/../../fixture/compile/closure_defaults_single/verify/runtime.php';
        } finally {
            $fixture->cleanup();
        }
    }

    #[RunInSeparateProcess]
    public function testArrowWithSingleDefaultUsesPadding(): void
    {
        $fixture = CompiledFixture::compile(
            __DIR__ . '/../../fixture/compile/arrow_defaults_single/source',
            'adef-single',
        );
        try {
            require __DIR__ . '/../../fixture/compile/arrow_defaults_single/verify/runtime.php';
        } finally {
            $fixture->cleanup();
        }
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
        SnapshotHash::assertMatches(
            __DIR__ . '/ClosureArrowDefaultsTest/testClosureWithTrailingDefaultPadsAtCallSite/Use.expected.php',
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
        SnapshotHash::assertMatches(
            __DIR__ . '/ClosureArrowDefaultsTest/testClosureWithDefaultReferringToEarlierParam/Use.expected.php',
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

    #[RunInSeparateProcess]
    public function testDefaultOnClosureWithUseClause(): void
    {
        // Defaults + use(): both features compose.
        $fixture = CompiledFixture::compile(
            __DIR__ . '/../../fixture/compile/closure_defaults_with_use/source',
            'cdef-use',
        );
        try {
            require __DIR__ . '/../../fixture/compile/closure_defaults_with_use/verify/runtime.php';
        } finally {
            $fixture->cleanup();
        }
    }

    #[RunInSeparateProcess]
    public function testDefaultOnArrowWithImplicitCapture(): void
    {
        // Defaults + arrow implicit captures together.
        $fixture = CompiledFixture::compile(
            __DIR__ . '/../../fixture/compile/arrow_defaults_with_implicit_capture/source',
            'adef-cap',
        );
        try {
            require __DIR__ . '/../../fixture/compile/arrow_defaults_with_implicit_capture/verify/runtime.php';
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
