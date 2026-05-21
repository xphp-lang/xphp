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

final class GenericFunctionIntegrationTest extends TestCase
{
    private string $sourceDir;
    private string $workDir;
    private string $targetDir;
    private string $cacheDir;

    protected function setUp(): void
    {
        $this->sourceDir = realpath(__DIR__ . '/../../fixture/compile/generic_function/source')
            ?: throw new RuntimeException('Fixture missing');
        $this->workDir = sys_get_temp_dir() . '/xphp-genfn-' . uniqid('', true);
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

    public function testGenericFunctionSpecializesPerUniqueCallSiteArgs(): void
    {
        $this->compile();

        $funcsPath = $this->targetDir . '/funcs.php';
        self::assertFileExists($funcsPath);
        $content = file_get_contents($funcsPath);

        self::assertSame(
            2,
            preg_match_all('/function identity_T_[0-9a-f]+\(/', $content),
            'expected two specialized identity_T_<hash> functions (one per unique call-site arg)',
        );
        self::assertMatchesRegularExpression('/function identity_T_[0-9a-f]+\(int \$x\): int/', $content);
        self::assertMatchesRegularExpression('/function identity_T_[0-9a-f]+\(string \$x\): string/', $content);
        self::assertStringNotContainsString('function identity(', $content, 'original template must be stripped');
    }

    public function testFunctionCallSitesAreRewrittenToMangledFqnNames(): void
    {
        $this->compile();

        $usePath = $this->targetDir . '/Use.php';
        self::assertFileExists($usePath);
        $content = file_get_contents($usePath);

        self::assertSame(2, preg_match_all('/\\\\App\\\\identity_T_[0-9a-f]+\(/', $content));
        self::assertStringNotContainsString('identity<', $content);
    }

    public function testRuntimeExecutionOfSpecializedFunctions(): void
    {
        $this->compile();

        $funcsPath = $this->targetDir . '/funcs.php';
        $usePath = $this->targetDir . '/Use.php';

        $runScript = $this->workDir . '/run.php';
        file_put_contents($runScript, <<<PHP
        <?php
        declare(strict_types=1);
        require '{$funcsPath}';

        // Pull mangled names out of funcs.php and call directly.
        \$content = file_get_contents('{$funcsPath}');
        preg_match_all('/function (identity_T_[0-9a-f]+)\\((\\w+) \\\$x\\): \\\\2/', \$content, \$m);
        foreach (\$m[1] as \$i => \$mangled) {
            \$type = \$m[2][\$i];
            \$sample = \$type === 'int' ? 42 : 'hi';
            \$fqn = '\\\\App\\\\' . \$mangled;
            \$out = \$fqn(\$sample);
            \$expected = \$type === 'int' ? 'integer' : 'string';
            echo gettype(\$out) === \$expected ? "OK_{\$type}" : "BAD_{\$type}", "\\n";
        }
        PHP);

        $output = [];
        $exit = 0;
        exec('php ' . escapeshellarg($runScript) . ' 2>&1', $output, $exit);
        self::assertSame(0, $exit, "Run failed:\n" . implode("\n", $output));
        self::assertContains('OK_int', $output);
        self::assertContains('OK_string', $output);
    }

    public function testEmittedFilesAreSyntacticallyValid(): void
    {
        $this->compile();

        $files = self::globRecursive($this->targetDir, '*.php');
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
        $sources = (new NativeFileFinder())->find($this->sourceDir)
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
