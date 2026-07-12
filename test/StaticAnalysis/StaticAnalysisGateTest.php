<?php

declare(strict_types=1);

namespace XPHP\StaticAnalysis;

use PhpParser\ParserFactory;
use PhpParser\PrettyPrinter\Standard as StandardPrinter;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use XPHP\Diagnostics\DiagnosticSource;
use XPHP\Diagnostics\Severity;
use XPHP\FileSystem\FileFinder\NativeFileFinder;
use XPHP\FileSystem\FileReader\NativeFileReader;
use XPHP\FileSystem\FileWriter\NativeFileWriter;
use XPHP\FileSystem\FilepathArray;
use XPHP\Transpiler\Monomorphize\Compiler;
use XPHP\Transpiler\Monomorphize\SpecializedClassGenerator;
use XPHP\Transpiler\Monomorphize\Specializer;
use XPHP\Transpiler\Monomorphize\XphpSourceParser;

#[Group('phpstan')]
final class StaticAnalysisGateTest extends TestCase
{
    private string $bin;
    private string $workingDir;

    protected function setUp(): void
    {
        $this->workingDir = realpath(__DIR__ . '/../..') ?: throw new RuntimeException('repo root missing');
        $bin = realpath($this->workingDir . '/vendor/bin/phpstan');
        if ($bin === false) {
            self::markTestSkipped('phpstan binary not installed (vendor/bin/phpstan)');
        }
        $this->bin = $bin;
    }

    public function testReportsBodyTypeErrorMappedToTemplateDeclaration(): void
    {
        $diagnostics = $this->gate()->analyze(
            ...$this->fixtureArgs('check/body_type_error'),
            explicitBin: $this->bin,
            explicitConfig: $this->level5Config(),
        );

        self::assertCount(1, $diagnostics);
        $d = $diagnostics[0];
        self::assertSame(Severity::Error, $d->severity);
        self::assertSame(DiagnosticSource::PhpStan, $d->source);
        self::assertStringContainsString('should return int but returns string', $d->message);
        self::assertSame('App\\Check\\BodyTypeError\\Box<int>', $d->triggeredBy);
        self::assertNotNull($d->location);
        self::assertStringEndsWith('Box.xphp', $d->location->file);
        self::assertSame(7, $d->location->line);
    }

    public function testWorkspaceIsCleanedUpAfterAnalysis(): void
    {
        $before = glob(sys_get_temp_dir() . '/xphp-check-*') ?: [];

        $this->gate()->analyze(
            ...$this->fixtureArgs('check/body_type_error'),
            explicitBin: $this->bin,
            explicitConfig: null,
        );

        $after = glob(sys_get_temp_dir() . '/xphp-check-*') ?: [];
        // The finally-cleanup must leave no workspace behind (guards UnwrapFinally).
        self::assertSame($before, $after);
    }

    public function testCleanSourcesProduceNoDiagnostics(): void
    {
        $diagnostics = $this->gate()->analyze(
            ...$this->fixtureArgs('compile/box_generic'),
            explicitBin: $this->bin,
            explicitConfig: $this->level5Config(),
        );

        self::assertSame([], $diagnostics);
    }

    public function testMissingBinaryYieldsNonFailingWarning(): void
    {
        $diagnostics = $this->gate()->analyze(
            ...$this->fixtureArgs('check/body_type_error'),
            explicitBin: '/definitely/not/a/real/phpstan',
            explicitConfig: null,
        );

        self::assertCount(1, $diagnostics);
        self::assertSame(StaticAnalysisGate::CODE_UNAVAILABLE, $diagnostics[0]->code);
        self::assertSame(Severity::Warning, $diagnostics[0]->severity);
        self::assertFalse($diagnostics[0]->severity->isFailing());
        self::assertSame(
            'PHPStan was not found (looked for --phpstan-bin, then vendor/bin/phpstan, then $PATH); '
                . 'skipping static analysis. Pass --no-phpstan to silence this.',
            $diagnostics[0]->message,
        );
    }

    public function testFailedRunYieldsNonFailingWarning(): void
    {
        $badConfig = sys_get_temp_dir() . '/xphp-bad-config-' . uniqid('', true) . '.neon';
        file_put_contents($badConfig, "includes:\n    - /no/such/file.neon\n");

        try {
            $diagnostics = $this->gate()->analyze(
                ...$this->fixtureArgs('check/body_type_error'),
                explicitBin: $this->bin,
                explicitConfig: $badConfig,
            );

            self::assertCount(1, $diagnostics);
            self::assertSame(StaticAnalysisGate::CODE_RUN_FAILED, $diagnostics[0]->code);
            self::assertSame(Severity::Warning, $diagnostics[0]->severity);
            self::assertStringStartsWith('PHPStan could not complete: ', $diagnostics[0]->message);
        } finally {
            unlink($badConfig);
        }
    }

    public function testSourcesWithoutGenericInstantiationsProduceNoDiagnostics(): void
    {
        // A plain (non-generic) source has no specializations, so there's nothing
        // for PHPStan to add over the generic checks.
        $dir = sys_get_temp_dir() . '/xphp-plain-' . uniqid('', true);
        mkdir($dir . '/source', 0o755, true);
        file_put_contents(
            $dir . '/source/Plain.xphp',
            "<?php\ndeclare(strict_types=1);\nnamespace App\\Plain;\nfinal class Plain { public function n(): void {} }\n",
        );

        try {
            $sources = (new NativeFileFinder())
                ->find($dir . '/source')
                ->filter(static fn (string $f): bool => str_ends_with($f, '.xphp'));

            $diagnostics = $this->gate()->analyze($sources, $dir . '/source', $this->workingDir, $this->bin, null);

            self::assertSame([], $diagnostics);
        } finally {
            unlink($dir . '/source/Plain.xphp');
            rmdir($dir . '/source');
            rmdir($dir);
        }
    }

    private function level5Config(): string
    {
        // A fixed, minimal consumer config so the body-error assertions don't depend
        // on (or drift with) the repo's own phpstan.neon picked up via getcwd().
        return realpath(__DIR__ . '/../fixture/check/phpstan-level5.neon')
            ?: throw new RuntimeException('level5 config fixture missing');
    }

    /** @return array{FilepathArray, string, string} */
    private function fixtureArgs(string $fixture): array
    {
        $sourceDir = realpath(__DIR__ . '/../fixture/' . $fixture . '/source')
            ?: throw new RuntimeException("fixture missing: {$fixture}");
        $sources = (new NativeFileFinder())
            ->find($sourceDir)
            ->filter(static fn (string $f): bool => str_ends_with($f, '.xphp'));

        return [$sources, $sourceDir, $this->workingDir];
    }

    private function gate(): StaticAnalysisGate
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

        return new StaticAnalysisGate($compiler);
    }
}
