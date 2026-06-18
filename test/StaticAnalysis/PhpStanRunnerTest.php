<?php

declare(strict_types=1);

namespace XPHP\StaticAnalysis;

use PhpParser\ParserFactory;
use PhpParser\PrettyPrinter\Standard as StandardPrinter;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use XPHP\FileSystem\FileFinder\NativeFileFinder;
use XPHP\FileSystem\FileReader\NativeFileReader;
use XPHP\FileSystem\FileWriter\NativeFileWriter;
use XPHP\Transpiler\Monomorphize\Compiler;
use XPHP\Transpiler\Monomorphize\SpecializedClassGenerator;
use XPHP\Transpiler\Monomorphize\Specializer;
use XPHP\Transpiler\Monomorphize\XphpSourceParser;

#[Group('phpstan')]
final class PhpStanRunnerTest extends TestCase
{
    private string $phpstanBin;

    protected function setUp(): void
    {
        $bin = realpath(__DIR__ . '/../../vendor/bin/phpstan');
        if ($bin === false) {
            self::markTestSkipped('phpstan binary not installed (vendor/bin/phpstan)');
        }
        $this->phpstanBin = $bin;
    }

    public function testReportsBodyTypeErrorInTheRepresentative(): void
    {
        $this->withCompiled('check/body_type_error', function (CompiledWorkspace $w): void {
            $reps = RepresentativeSelector::select($w->registry, $w->generatedDir);
            $result = $this->analyze($w, $reps);

            self::assertTrue($result->ranOk, 'phpstan should run cleanly; got: ' . ($result->errorOutput ?? ''));
            self::assertCount(1, $result->findings);

            $finding = $result->findings[0];
            self::assertSame($reps[0]->filePath, $finding->file);
            self::assertStringContainsString('should return int but returns string', $finding->message);
        });
    }

    public function testCleanSourcesProduceNoFindings(): void
    {
        $this->withCompiled('compile/box_generic', function (CompiledWorkspace $w): void {
            $reps = RepresentativeSelector::select($w->registry, $w->generatedDir);
            $result = $this->analyze($w, $reps);

            self::assertTrue($result->ranOk, 'phpstan should run cleanly; got: ' . ($result->errorOutput ?? ''));
            self::assertSame([], $result->findings);
        });
    }

    public function testBrokenConsumerConfigIsReportedAsRunFailureNotACleanPass(): void
    {
        $this->withCompiled('check/body_type_error', function (CompiledWorkspace $w): void {
            $reps = RepresentativeSelector::select($w->registry, $w->generatedDir);

            // A consumer config that includes a non-existent file makes PHPStan fatal
            // with no JSON on stdout — must surface as a failed run, never 0 findings.
            $badConfig = $w->root . '/bad-consumer.neon';
            file_put_contents($badConfig, "includes:\n    - /no/such/file/phpstan.neon\n");

            $result = (new PhpStanRunner($this->phpstanBin))->run(
                array_map(static fn (Representative $r): string => $r->filePath, $reps),
                [$w->distDir, $w->generatedDir],
                $badConfig,
                $w->root . '/ephemeral.neon',
            );

            self::assertFalse($result->ranOk);
            self::assertSame([], $result->findings);
            self::assertNotNull($result->errorOutput);
            self::assertNotSame('', $result->errorOutput);
        });
    }

    public function testFindingPathMatchesRepresentativeEvenThroughASymlinkedRoot(): void
    {
        // Compile into a workspace whose root is reached via a symlink. PHPStan
        // reports realpath()'d paths; the representative file path must resolve to
        // the same canonical form, or the finding->representative join silently
        // misses and the gate falsely passes. (Regression guard for that join.)
        $realBase = sys_get_temp_dir() . '/xphp-real-' . uniqid('', true);
        $linkBase = sys_get_temp_dir() . '/xphp-link-' . uniqid('', true);
        mkdir($realBase, 0o755, true);
        symlink($realBase, $linkBase);

        $sourceDir = realpath(__DIR__ . '/../fixture/check/body_type_error/source')
            ?: throw new RuntimeException('fixture missing');
        $sources = (new NativeFileFinder())
            ->find($sourceDir)
            ->filter(static fn (string $f): bool => str_ends_with($f, '.xphp'));

        $workspace = CompiledWorkspace::compile($this->compiler(), $sources, $sourceDir, $linkBase . '/ws');
        try {
            $reps = RepresentativeSelector::select($workspace->registry, $workspace->generatedDir);
            $result = $this->analyze($workspace, $reps);

            self::assertTrue($result->ranOk, 'phpstan should run; got: ' . ($result->errorOutput ?? ''));
            self::assertCount(1, $result->findings);
            self::assertSame($reps[0]->filePath, $result->findings[0]->file);
        } finally {
            $workspace->cleanup();
            unlink($linkBase);
            @rmdir($realBase);
        }
    }

    public function testResolvedButInvalidBinaryIsAFailedRunNotAnException(): void
    {
        $this->withCompiled('check/body_type_error', function (CompiledWorkspace $w): void {
            $reps = RepresentativeSelector::select($w->registry, $w->generatedDir);
            $bogusBin = $w->root . '/not-really-phpstan';
            file_put_contents($bogusBin, "<?php\nfwrite(STDERR, 'boom');\nexit(255);\n");

            $result = (new PhpStanRunner($bogusBin))->run(
                array_map(static fn (Representative $r): string => $r->filePath, $reps),
                [$w->distDir, $w->generatedDir],
                null,
                $w->root . '/ephemeral.neon',
            );

            self::assertFalse($result->ranOk);
            self::assertSame([], $result->findings);
        });
    }

    private function analyze(CompiledWorkspace $w, array $reps): PhpStanResult
    {
        $vendorDir = realpath(__DIR__ . '/../../vendor') ?: throw new RuntimeException('vendor dir missing');

        return (new PhpStanRunner($this->phpstanBin))->run(
            array_map(static fn (Representative $r): string => $r->filePath, $reps),
            [$w->distDir, $w->generatedDir, $vendorDir],
            null, // no consumer config → default level
            $w->root . '/ephemeral.neon',
        );
    }

    /** @param callable(CompiledWorkspace): void $assertions */
    private function withCompiled(string $fixture, callable $assertions): void
    {
        $sourceDir = realpath(__DIR__ . '/../fixture/' . $fixture . '/source')
            ?: throw new RuntimeException("fixture missing: {$fixture}");
        $sources = (new NativeFileFinder())
            ->find($sourceDir)
            ->filter(static fn (string $f): bool => str_ends_with($f, '.xphp'));

        $workspace = CompiledWorkspace::compile(
            $this->compiler(),
            $sources,
            $sourceDir,
            sys_get_temp_dir() . '/xphp-runner-' . uniqid('', true),
        );
        try {
            $assertions($workspace);
        } finally {
            $workspace->cleanup();
        }
    }

    private function compiler(): Compiler
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
}
