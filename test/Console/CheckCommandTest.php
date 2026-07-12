<?php

declare(strict_types=1);

namespace XPHP\Console\Command;

use PhpParser\ParserFactory;
use PhpParser\PrettyPrinter\Standard as StandardPrinter;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Console\Tester\CommandTester;
use XPHP\Config\ManifestResolver;
use XPHP\Config\SourceResolver;
use XPHP\FileSystem\FileFinder\NativeFileFinder;
use XPHP\FileSystem\FileReader\NativeFileReader;
use XPHP\FileSystem\FileWriter\NativeFileWriter;
use XPHP\StaticAnalysis\CheckGate;
use XPHP\StaticAnalysis\StaticAnalysisGate;
use XPHP\Transpiler\Monomorphize\Compiler;
use XPHP\Transpiler\Monomorphize\Specializer;
use XPHP\Transpiler\Monomorphize\SpecializedClassGenerator;
use XPHP\Transpiler\Monomorphize\XphpSourceParser;

final class CheckCommandTest extends TestCase
{
    public function testCleanSourcesExitZero(): void
    {
        // --no-phpstan isolates the generic-check (Phase 1) contract from the
        // PHPStan pass, which is exercised separately in CheckCommandPhpStanTest.
        $tester = $this->tester();
        $exit = $tester->execute(['source' => $this->fixtureDir('clean'), '--no-phpstan' => true]);

        self::assertSame(0, $exit);
        self::assertStringContainsString('No problems found', $tester->getDisplay());
    }

    public function testResolvesSourcesFromConfigManifest(): void
    {
        // `check --config <xphp.json>` resolves sources from the manifest (no positional source).
        $dir = sys_get_temp_dir() . '/xphp-check-cfg-' . uniqid('', true);
        mkdir($dir . '/src', 0o755, true);
        file_put_contents($dir . '/xphp.json', '{"sources":["src"]}');
        file_put_contents($dir . '/src/Box.xphp', "<?php\nnamespace App;\nclass Box<T> { public function get(): T { throw new \\LogicException; } }\n");

        try {
            $tester = $this->tester();
            $exit = $tester->execute(['--config' => $dir . '/xphp.json', '--no-phpstan' => true]);

            self::assertSame(0, $exit);
            self::assertStringContainsString('No problems found', $tester->getDisplay());
        } finally {
            self::rrmdir($dir);
        }
    }

    public function testGenericErrorsExitOne(): void
    {
        $tester = $this->tester();
        $exit = $tester->execute(['source' => $this->fixtureDir('multi_error')]);

        self::assertSame(1, $exit);
        self::assertStringContainsString('bound violated', $tester->getDisplay());
    }

    public function testParseErrorIsReportedButOtherFilesStillChecked(): void
    {
        $tester = $this->tester();
        $exit = $tester->execute(['source' => $this->fixtureDir('parse_error'), '--format' => 'json']);

        self::assertSame(1, $exit);
        /** @var array{diagnostics: list<array{code: string, file: string, line: int}>} $decoded */
        $decoded = json_decode($tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);

        $byFile = [];
        foreach ($decoded['diagnostics'] as $d) {
            $byFile[basename($d['file'])] = $d;
        }

        // A PHP syntax error: reported with its real line.
        self::assertSame(Compiler::CODE_PARSE_ERROR, $byFile['Broken.xphp']['code']);
        self::assertSame(11, $byFile['Broken.xphp']['line']);
        // An xphp-specific parse rejection: reported at the offending token's real line
        // (the `<out T>` variance marker sits on line 9), not the line-1 fallback.
        self::assertSame(Compiler::CODE_PARSE_ERROR, $byFile['ClosureVariance.xphp']['code']);
        self::assertSame(9, $byFile['ClosureVariance.xphp']['line']);
        // A VALID file is still checked despite the two unparseable files.
        self::assertSame('xphp.bound_violation', $byFile['Use.xphp']['code']);
    }

    public function testMissingSourceDirectoryExitsTwoWithMessage(): void
    {
        $tester = $this->tester();
        $exit = $tester->execute(['source' => sys_get_temp_dir() . '/xphp-does-not-exist-' . uniqid('', true)]);

        self::assertSame(2, $exit);
        self::assertStringContainsString('Source directory not found', $tester->getDisplay());
    }

    public function testUnknownFormatExitsTwoWithMessage(): void
    {
        $tester = $this->tester();
        $exit = $tester->execute(['source' => $this->fixtureDir('clean'), '--format' => 'xml']);

        self::assertSame(2, $exit);
        self::assertStringContainsString('Unknown format', $tester->getDisplay());
    }

    public function testGithubFormatEmitsAnnotations(): void
    {
        $tester = $this->tester();
        $tester->execute(['source' => $this->fixtureDir('multi_error'), '--format' => 'github']);

        self::assertStringContainsString('::error file=', $tester->getDisplay());
    }

    private function tester(): CommandTester
    {
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

        $sourceResolver = new SourceResolver(
            new NativeFileFinder(),
            new ManifestResolver(new NativeFileReader(), new NativeFileFinder()),
        );

        return new CommandTester(
            new CheckCommand($sourceResolver, new CheckGate($compiler, new StaticAnalysisGate($compiler))),
        );
    }

    private function fixtureDir(string $fixture): string
    {
        return realpath(__DIR__ . '/../fixture/check/' . $fixture . '/source')
            ?: throw new RuntimeException("Fixture missing: {$fixture}");
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
