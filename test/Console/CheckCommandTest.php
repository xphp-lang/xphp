<?php

declare(strict_types=1);

namespace XPHP\Console\Command;

use PhpParser\ParserFactory;
use PhpParser\PrettyPrinter\Standard as StandardPrinter;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Console\Tester\CommandTester;
use XPHP\FileSystem\FileFinder\NativeFileFinder;
use XPHP\FileSystem\FileReader\NativeFileReader;
use XPHP\FileSystem\FileWriter\NativeFileWriter;
use XPHP\Transpiler\Monomorphize\Compiler;
use XPHP\Transpiler\Monomorphize\Specializer;
use XPHP\Transpiler\Monomorphize\SpecializedClassGenerator;
use XPHP\Transpiler\Monomorphize\XphpSourceParser;

final class CheckCommandTest extends TestCase
{
    public function testCleanSourcesExitZero(): void
    {
        $tester = $this->tester();
        $exit = $tester->execute(['source' => $this->fixtureDir('clean')]);

        self::assertSame(0, $exit);
        self::assertStringContainsString('No problems found', $tester->getDisplay());
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
        // An xphp-specific parse rejection (no line): reported at line 1.
        self::assertSame(Compiler::CODE_PARSE_ERROR, $byFile['ClosureVariance.xphp']['code']);
        self::assertSame(1, $byFile['ClosureVariance.xphp']['line']);
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

        return new CommandTester(new CheckCommand(new NativeFileFinder(), $compiler));
    }

    private function fixtureDir(string $fixture): string
    {
        return realpath(__DIR__ . '/../fixture/check/' . $fixture . '/source')
            ?: throw new RuntimeException("Fixture missing: {$fixture}");
    }
}
