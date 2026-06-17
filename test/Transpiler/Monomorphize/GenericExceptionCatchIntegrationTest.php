<?php

declare(strict_types=1);

namespace XPHP\Transpiler\Monomorphize;

use PhpParser\ParserFactory;
use PhpParser\PrettyPrinter\Standard as StandardPrinter;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use XPHP\FileSystem\FileFinder\NativeFileFinder;
use XPHP\FileSystem\FileReader\NativeFileReader;
use XPHP\FileSystem\FileWriter\NativeFileWriter;
use XPHP\TestSupport\CompiledFixture;
use XPHP\TestSupport\SnapshotHash;

/**
 * Catching an exception by its generic specialization. Because each
 * `HttpError<X>` monomorphizes into a distinct concrete class (extending the
 * native exception, implementing the original name as a marker interface), a
 * `catch (HttpError<X> $e)` clause is just another type-hint position that
 * CallSiteRewriter rewrites to the specialized FQN. PHP's native catch then
 * discriminates by concrete class.
 *
 * These tests lock that behavior in: it works today as emergent behavior, with
 * no production code dedicated to it, so a future narrowing of CallSiteRewriter
 * or the scanner's type-hint detection could regress it silently.
 */
final class GenericExceptionCatchIntegrationTest extends TestCase
{
    private string $sourceDir;
    private string $workDir;
    private string $targetDir;
    private string $cacheDir;

    protected function setUp(): void
    {
        $this->sourceDir = realpath(__DIR__ . '/../../fixture/compile/generic_exception_catch/source')
            ?: throw new RuntimeException('Fixture missing');
        $this->workDir = sys_get_temp_dir() . '/xphp-generic-catch-' . uniqid('', true);
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

    #[RunInSeparateProcess]
    public function testGenericCatchDiscriminatesBySpecializationAtRuntime(): void
    {
        $fixture = CompiledFixture::compile($this->sourceDir, 'generic-catch-runtime');
        $fixture->registerAutoload('App\\GenericExceptionCatch\\');
        try {
            require __DIR__ . '/../../fixture/compile/generic_exception_catch/verify/catch_runtime.php';
        } finally {
            $fixture->cleanup();
        }
    }

    public function testCatchClausesRewriteToDistinctSpecializedFqns(): void
    {
        $this->compile();

        $notFoundFqn = Registry::generatedFqn(
            'App\\GenericExceptionCatch\\Errors\\HttpError',
            [new TypeRef('App\\GenericExceptionCatch\\Models\\NotFound')],
        );
        $forbiddenFqn = Registry::generatedFqn(
            'App\\GenericExceptionCatch\\Errors\\HttpError',
            [new TypeRef('App\\GenericExceptionCatch\\Models\\Forbidden')],
        );

        // The two specializations MUST be distinct FQNs — that distinctness is
        // what lets the two catch arms discriminate at all. This (plus the
        // snapshot) pins the rewrite shape, but it is position-insensitive: a
        // regression that swapped the two arm *bodies* would slip past here
        // because both FQNs would still appear and keep their first-seen order
        // (SnapshotHash's documented blind spot). The runtime test
        // (`classify('forbidden')` must skip the NotFound arm) is what actually
        // guards the arm/body pairing.
        self::assertNotSame($notFoundFqn, $forbiddenFqn);

        $clientPath = $this->targetDir . '/Client.php';
        self::assertFileExists($clientPath);
        $content = file_get_contents($clientPath);

        // Both specialized FQNs appear as rewritten catch targets, and the bare
        // catch-all arm stays on the unspecialized marker name.
        self::assertStringContainsString('catch (\\' . $notFoundFqn . ' $e)', $content);
        self::assertStringContainsString('catch (\\' . $forbiddenFqn . ' $e)', $content);
        self::assertStringContainsString('catch (HttpError $e)', $content);

        SnapshotHash::assertMatches(
            __DIR__ . '/../../fixture/compile/generic_exception_catch/verify/testCatchClausesRewriteToDistinctSpecializedFqns/Client.expected.php',
            $content,
        );
    }

    public function testAllOutputFilesAreSyntacticallyValid(): void
    {
        $this->compile();

        $files = array_merge(
            self::globRecursive($this->targetDir, '*.php'),
            self::globRecursive($this->cacheDir . '/Generated', '*.php'),
        );
        self::assertNotEmpty($files);

        foreach ($files as $file) {
            $output = [];
            $exit = 0;
            exec('php -l ' . escapeshellarg($file) . ' 2>&1', $output, $exit);
            self::assertSame(0, $exit, "Syntax error in {$file}:\n" . implode("\n", $output));
        }
    }

    private function compile(): void
    {
        $compiler = $this->buildCompiler();
        $sources = (new NativeFileFinder())
            ->find($this->sourceDir)
            ->filter(static fn (string $f): bool => str_ends_with($f, '.xphp'));
        $compiler->compile($sources, $this->sourceDir, $this->targetDir, $this->cacheDir);
    }

    /**
     * @return list<string>
     */
    private static function globRecursive(string $dir, string $pattern): array
    {
        if (!is_dir($dir)) {
            return [];
        }
        $found = glob(rtrim($dir, '/') . '/' . $pattern) ?: [];
        foreach (glob(rtrim($dir, '/') . '/*', GLOB_ONLYDIR) ?: [] as $subdir) {
            $found = array_merge($found, self::globRecursive($subdir, $pattern));
        }
        return $found;
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

    private static function rrmdir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            is_dir($path) ? self::rrmdir($path) : unlink($path);
        }
        rmdir($dir);
    }
}
