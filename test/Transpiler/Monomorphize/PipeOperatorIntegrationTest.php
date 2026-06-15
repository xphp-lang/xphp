<?php

declare(strict_types=1);

namespace XPHP\Transpiler\Monomorphize;

use PhpParser\ParserFactory;
use PhpParser\PrettyPrinter\Standard as StandardPrinter;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RequiresPhp;
use PHPUnit\Framework\TestCase;
use XPHP\FileSystem\FileFinder\NativeFileFinder;
use XPHP\FileSystem\FileReader\NativeFileReader;
use XPHP\FileSystem\FileWriter\NativeFileWriter;

/**
 * Locks in pass-through support for the PHP 8.5 pipe operator (`|>`).
 *
 * xphp only owns generic syntax; everything else is plain PHP that must
 * survive the parse -> rewrite -> pretty-print round-trip untouched. The
 * production parser uses `createForHostVersion()` (xphp's runtime is ^8.4),
 * so the `|>` token only lexes when the transpiler itself runs on PHP 8.5+.
 *
 * This case therefore requires an 8.5 runtime: it is tagged `@group php85`
 * so the default 8.4 CI job excludes it, and `#[RequiresPhp]` makes it skip
 * rather than error if ever run on an older PHP. The dedicated 8.5 CI
 * container runs exactly this group.
 */
#[Group('php85')]
#[RequiresPhp('>= 8.5.0')]
final class PipeOperatorIntegrationTest extends TestCase
{
    private string $sourceDir;
    private string $targetDir;
    private string $cacheDir;

    protected function setUp(): void
    {
        $workDir = sys_get_temp_dir() . '/xphp-pipe-' . uniqid('', true);
        $this->sourceDir = $workDir . '/src';
        $this->targetDir = $workDir . '/dist';
        $this->cacheDir = $workDir . '/.xphp-cache';
        mkdir($this->sourceDir, 0o755, true);
    }

    protected function tearDown(): void
    {
        $workDir = \dirname($this->sourceDir);
        if (is_dir($workDir)) {
            self::rrmdir($workDir);
        }
    }

    public function testPipeOperatorRoundTripsThroughTranspiler(): void
    {
        $this->writeSource('pipe.xphp', <<<'PHP'
        <?php
        namespace App;
        $slug = $title |> trim(...) |> strtolower(...);
        PHP);

        $this->compile();

        $out = $this->readOutput('pipe.php');
        self::assertStringContainsString('|>', $out);
        self::assertStringContainsString('$slug = $title |> trim(...) |> strtolower(...);', $out);
        $this->assertValidPhp($out);
    }

    public function testPipeOperatorCoexistsWithGenericSpecialization(): void
    {
        // The pipe lives right beside a turbofish call site, so this also proves
        // xphp's generic byte-offset rewriting does not disturb the `|>` tokens.
        $this->writeSource('box.xphp', <<<'PHP'
        <?php
        namespace App;

        final class Box<T>
        {
            public function __construct(public T $value) {}
        }

        $box = new Box::<string>('  HELLO  ');
        $slug = $box->value |> trim(...) |> strtolower(...);
        PHP);

        $this->compile();

        $out = $this->readOutput('box.php');
        // Pipe survives untouched.
        self::assertStringContainsString('$slug = $box->value |> trim(...) |> strtolower(...);', $out);
        // Generic was actually specialized: the turbofish call site is rewritten
        // to a generated, monomorphized FQN and the template syntax is gone.
        self::assertStringContainsString('XPHP\Generated\App\Box\T_', $out);
        self::assertStringNotContainsString('Box::<', $out);
        $this->assertValidPhp($out);
    }

    private function writeSource(string $name, string $code): void
    {
        file_put_contents($this->sourceDir . '/' . $name, $code);
    }

    private function readOutput(string $name): string
    {
        $path = $this->targetDir . '/' . $name;
        self::assertFileExists($path);

        return file_get_contents($path) ?: '';
    }

    private function assertValidPhp(string $code): void
    {
        // The emitted PHP must itself re-parse on this (8.5) runtime.
        $parser = (new ParserFactory())->createForHostVersion();
        self::assertNotNull(
            $parser->parse($code),
            'Transpiled output is not valid PHP',
        );
    }

    private function compile(): void
    {
        // Mirror production wiring (ApplicationConsole): host-version parser.
        // On the 8.5 runtime this group runs under, that tokenizes `|>`.
        $phpParser = (new ParserFactory())->createForHostVersion();
        $printer = new StandardPrinter();
        $writer = new NativeFileWriter();

        $compiler = new Compiler(
            new NativeFileReader(),
            $writer,
            new XphpSourceParser($phpParser),
            new Specializer(),
            new SpecializedClassGenerator($printer, $writer),
            $printer,
        );

        $sources = (new NativeFileFinder())->find($this->sourceDir)
            ->filter(static fn (string $f): bool => str_ends_with($f, '.xphp'));

        $compiler->compile($sources, $this->sourceDir, $this->targetDir, $this->cacheDir);
    }

    private static function rrmdir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            is_dir($path) ? self::rrmdir($path) : unlink($path);
        }
        rmdir($dir);
    }
}
