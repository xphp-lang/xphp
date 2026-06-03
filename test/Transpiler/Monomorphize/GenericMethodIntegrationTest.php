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

final class GenericMethodIntegrationTest extends TestCase
{
    private string $sourceDir;
    private string $workDir;
    private string $targetDir;
    private string $cacheDir;

    protected function setUp(): void
    {
        $this->sourceDir = realpath(__DIR__ . '/../../fixture/compile/generic_method/source')
            ?: throw new RuntimeException('Fixture missing');
        $this->workDir = sys_get_temp_dir() . '/xphp-genmethod-' . uniqid('', true);
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

    public function testGenericMethodSpecializesPerUniqueCallSiteArgs(): void
    {
        $this->compile();

        $utilPath = $this->targetDir . '/Util.php';
        self::assertFileExists($utilPath);
        $content = file_get_contents($utilPath);

        // Two unique args (int, string) -> two mangled methods.
        self::assertSame(2, preg_match_all('/function identity_T_[0-9a-f]+\\(/', $content), 'expected two mangled identity_T_<hash> methods (one per unique call-site arg)');
        // Each specialized method takes its concrete type.
        self::assertStringContainsString('public static function identity_T_', $content);
        self::assertMatchesRegularExpression('/public static function identity_T_[0-9a-f]+\\(int \\$x\\): int/', $content);
        self::assertMatchesRegularExpression('/public static function identity_T_[0-9a-f]+\\(string \\$x\\): string/', $content);

        // Original generic-method template must be removed from the emitted class.
        self::assertStringNotContainsString('function identity(', $content);
        self::assertStringNotContainsString('function identity ', $content);
    }

    public function testCallSitesAreRewrittenToMangledNames(): void
    {
        $this->compile();

        $usePath = $this->targetDir . '/Use.php';
        self::assertFileExists($usePath);
        $content = file_get_contents($usePath);

        // Three call sites (2 int + 1 string), all rewritten to fully-qualified mangled refs.
        self::assertSame(3, preg_match_all('/\\\\App\\\\GenericMethod\\\\Util::identity_T_[0-9a-f]+\\(/', $content));
        // The two int call sites must share the same mangled name (single specialization
        // per unique arg list, not per call site — locks the alreadyGenerated dedupe).
        \preg_match_all('/identity_T_([0-9a-f]+)/', $content, $matches);
        self::assertCount(3, $matches[1]);
        self::assertSame($matches[1][0], $matches[1][2], 'both `identity<int>` call sites must share a mangled name');
        self::assertNotSame($matches[1][0], $matches[1][1], 'int and string mangles must differ');
        // No raw `identity<int>` left over (the generic-args clause must be stripped or rewritten).
        self::assertStringNotContainsString('identity<', $content);
        // No bare `identity(` either — every call should be mangled.
        self::assertStringNotContainsString('::identity(', $content);
    }

    public function testRuntimeExecutionPreservesGenericMethodSemantics(): void
    {
        $this->compile();

        $utilPath = $this->targetDir . '/Util.php';
        $usePath = $this->targetDir . '/Use.php';

        $runScript = $this->workDir . '/run.php';
        file_put_contents($runScript, <<<PHP
        <?php
        declare(strict_types=1);
        require '{$utilPath}';

        \$asInt = \\App\\GenericMethod\\Util::identity_T_FILLED_AT_RUNTIME(42);
        PHP);

        // Pull the mangled FQNs out of Util.php and call them directly. We assert each
        // one round-trips its arg through the matching native type.
        \preg_match_all('/function (identity_T_[0-9a-f]+)\((\w+) \$x\): \2/', file_get_contents($utilPath), $matches);
        self::assertCount(2, $matches[1], 'expected to find two mangled methods');
        $gettypeName = ['int' => 'integer', 'string' => 'string'];
        $script = "<?php\ndeclare(strict_types=1);\nrequire " . var_export($utilPath, true) . ";\n";
        foreach ($matches[1] as $i => $mangled) {
            $type = $matches[2][$i];
            $sample = $type === 'int' ? '42' : "'hello'";
            $expected = $gettypeName[$type];
            $script .= "\$out{$i} = \\App\\GenericMethod\\Util::{$mangled}({$sample});\n";
            $script .= "echo gettype(\$out{$i}) === '{$expected}' ? 'OK_{$type}' : 'BAD_{$type}', \"\\n\";\n";
        }
        file_put_contents($runScript, $script);

        $output = [];
        $exit = 0;
        exec('php ' . escapeshellarg($runScript) . ' 2>&1', $output, $exit);
        self::assertSame(0, $exit, "Run failed:\n" . implode("\n", $output));
        self::assertContains('OK_int', $output);
        self::assertContains('OK_string', $output);
    }

    public function testMethodLevelBoundViolationFailsCompilation(): void
    {
        // D2 from the review: `Util::identity<T: \Stringable>` called with `<int>` is
        // now caught at compile time, not silently passed through to a runtime TypeError.
        $sourceDir = sys_get_temp_dir() . '/xphp-genmethod-bound-' . uniqid('', true);
        mkdir($sourceDir, 0o755, true);
        $utilPath = $sourceDir . '/Util.xphp';
        $usePath = $sourceDir . '/Use.xphp';
        file_put_contents($utilPath, <<<'PHP'
        <?php
        declare(strict_types=1);
        namespace App;
        class Util {
            public static function describe<T: \Stringable>(T $x): string { return (string) $x; }
        }
        PHP);
        file_put_contents($usePath, <<<'PHP'
        <?php
        declare(strict_types=1);
        namespace App;
        $out = Util::describe<int>(42);
        PHP);

        $compiler = $this->buildCompiler();
        $sources = (new NativeFileFinder())->find($sourceDir)
            ->filter(static fn (string $f): bool => str_ends_with($f, '.xphp'));
        $targetDir = sys_get_temp_dir() . '/xphp-mb-out-' . uniqid('', true) . '/dist';
        $cacheDir = dirname($targetDir) . '/.xphp-cache';
        mkdir(dirname($targetDir), 0o755, true);

        try {
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('Generic bound violated');
            $this->expectExceptionMessage('Stringable');
            $compiler->compile($sources, $sourceDir, $targetDir, $cacheDir);
        } finally {
            unlink($utilPath);
            unlink($usePath);
            @rmdir($sourceDir);
            if (is_dir(dirname($targetDir))) {
                self::rrmdir(dirname($targetDir));
            }
        }
    }

    public function testMultilineStaticCallSiteIsStillRewrittenToMangledName(): void
    {
        // Regression for F2: nikic's StaticCall::getStartLine() returns the receiver's
        // line (`Foo`), but the scanner used to record the marker against the identifier
        // line (`identity`). Splitting them across lines desyncs the two and the
        // marker never attaches. The line-range fix anchors the marker to the receiver.
        $sourceDir = sys_get_temp_dir() . '/xphp-multiline-' . uniqid('', true);
        mkdir($sourceDir, 0o755, true);
        $utilPath = $sourceDir . '/Util.xphp';
        $usePath = $sourceDir . '/Use.xphp';
        file_put_contents($utilPath, <<<'PHP'
        <?php
        declare(strict_types=1);
        namespace App;
        class Util {
            public static function identity<T>(T $x): T { return $x; }
        }
        PHP);
        file_put_contents($usePath, <<<'PHP'
        <?php
        declare(strict_types=1);
        namespace App;
        $asInt = Util::
            identity<int>(42);
        PHP);

        $compiler = $this->buildCompiler();
        $sources = (new NativeFileFinder())->find($sourceDir)
            ->filter(static fn (string $f): bool => str_ends_with($f, '.xphp'));
        $targetDir = sys_get_temp_dir() . '/xphp-multiline-out-' . uniqid('', true) . '/dist';
        $cacheDir = dirname($targetDir) . '/.xphp-cache';
        mkdir(dirname($targetDir), 0o755, true);

        try {
            $compiler->compile($sources, $sourceDir, $targetDir, $cacheDir);
            $useContent = file_get_contents($targetDir . '/Use.php');
            self::assertMatchesRegularExpression(
                '/Util::identity_T_[0-9a-f]+\(42\)/',
                $useContent,
                'multi-line `Util::\n    identity<int>` must still rewrite to the mangled name',
            );
        } finally {
            unlink($utilPath);
            unlink($usePath);
            @rmdir($sourceDir);
            if (is_dir(dirname($targetDir))) {
                self::rrmdir(dirname($targetDir));
            }
        }
    }

    public function testEmittedFilesAreSyntacticallyValid(): void
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
