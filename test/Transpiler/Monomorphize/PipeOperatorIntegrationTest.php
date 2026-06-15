<?php

declare(strict_types=1);

namespace XPHP\Transpiler\Monomorphize;

use PhpParser\ParserFactory;
use PhpParser\PrettyPrinter\Standard as StandardPrinter;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RequiresPhp;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use XPHP\FileSystem\FileFinder\NativeFileFinder;
use XPHP\FileSystem\FileReader\NativeFileReader;
use XPHP\FileSystem\FileWriter\NativeFileWriter;
use XPHP\TestSupport\SnapshotHash;

/**
 * Locks in pass-through support for the PHP 8.5 pipe operator (`|>`) via the
 * tracked `pipe_operator` fixture.
 *
 * xphp only owns generic syntax; everything else is plain PHP that must
 * survive the parse -> rewrite -> pretty-print round-trip untouched. The
 * production parser uses `createForHostVersion()` (xphp's runtime is ^8.4),
 * so the `|>` token only lexes when the transpiler itself runs on PHP 8.5+.
 *
 * This case therefore requires an 8.5 runtime: it is tagged `@group php85`
 * so the default 8.4 CI job excludes it, and `#[RequiresPhp]` makes it skip
 * rather than error if ever run on an older PHP. The dedicated 8.5 CI
 * container runs exactly this group. Locally:
 *   docker compose run --rm php85 make test/unit/php85
 */
#[Group('php85')]
#[RequiresPhp('>= 8.5.0')]
final class PipeOperatorIntegrationTest extends TestCase
{
    private string $sourceDir;
    private string $workDir;
    private string $targetDir;
    private string $cacheDir;

    protected function setUp(): void
    {
        $this->sourceDir = realpath(__DIR__ . '/../../fixture/compile/pipe_operator/source')
            ?: throw new RuntimeException('Fixture not found');
        $this->workDir = sys_get_temp_dir() . '/xphp-pipe-' . uniqid('', true);
        $this->targetDir = $this->workDir . '/dist';
        $this->cacheDir = $this->workDir . '/.xphp-cache';
        mkdir($this->workDir, 0o755, true);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->workDir)) {
            self::rrmdir($this->workDir);
        }
    }

    public function testPipeOperatorFixtureCompiles(): void
    {
        $compiler = $this->buildCompiler();

        $sources = (new NativeFileFinder())
            ->find($this->sourceDir)
            ->filter(static fn (string $f): bool => str_ends_with($f, '.xphp'));

        $result = $compiler->compile($sources, $this->sourceDir, $this->targetDir, $this->cacheDir);

        self::assertSame(2, $result->sourceCount, 'expected Box.xphp + Use.xphp');
        self::assertSame(1, $result->generatedCount, 'expected one specialization: Box<string>');

        $useFile = $this->targetDir . '/Use.php';
        self::assertFileExists($useFile);
        $useContent = file_get_contents($useFile);

        // Pipe survives verbatim -- both the standalone chain and the one
        // sitting right next to the rewritten turbofish call site.
        self::assertStringContainsString('$slug = $title |> trim(...) |> strtolower(...);', $useContent);
        self::assertStringContainsString('$shout = $box->value |> trim(...) |> strtoupper(...);', $useContent);

        // Generic was actually specialized: turbofish rewritten to the
        // monomorphized FQN, template syntax gone.
        self::assertStringContainsString('XPHP\Generated\App\PipeOperator\Box\T_', $useContent);
        self::assertStringNotContainsString('Box::<', $useContent);

        // The emitted PHP must itself re-parse on this (8.5) runtime.
        self::assertNotNull(
            (new ParserFactory())->createForHostVersion()->parse($useContent),
            'Transpiled output is not valid PHP',
        );

        // Whole-file snapshot (hash segments normalized) locks the exact
        // emitted bytes, including pipe-operator formatting.
        $snapshotDir = __DIR__ . '/../../fixture/compile/pipe_operator/verify/testPipeOperatorFixtureCompiles';
        SnapshotHash::assertMatches($snapshotDir . '/Use.expected.php', $useContent);
    }

    private function buildCompiler(): Compiler
    {
        // Mirror production wiring (ApplicationConsole): host-version parser.
        // On the 8.5 runtime this group runs under, that tokenizes `|>`.
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
