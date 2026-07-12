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
use XPHP\TestSupport\CompiledFixture;
use XPHP\TestSupport\SnapshotHash;

final class ArraySugarIntegrationTest extends TestCase
{
    private string $sourceDir;
    private string $workDir;
    private string $targetDir;
    private string $cacheDir;

    protected function setUp(): void
    {
        $this->sourceDir = realpath(__DIR__ . '/../../fixture/compile/array_sugar/source')
            ?: throw new RuntimeException('Fixture missing');
        $this->workDir = sys_get_temp_dir() . '/xphp-array-sugar-' . uniqid('', true);
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

    public function testCollectionWithArraySugarSpecializesToArray(): void
    {
        $this->compile();

        $fqn = Registry::generatedFqn(
            'App\\ArraySugar\\Containers\\Collection',
            [new TypeRef('App\\ArraySugar\\Models\\User')],
        );
        $file = $this->fqnToPath($fqn);
        self::assertFileExists($file);

        SnapshotHash::assertMatches(
            __DIR__ . '/../../fixture/compile/array_sugar/verify/testCollectionWithArraySugarSpecializesToArray/Collection.expected.php',
            file_get_contents($file),
        );
    }

    public function testGeneratedAndRewrittenFilesAreSyntacticallyValid(): void
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

    /**
     * Verify-file tests autoload generated classes whose definitions PHP
     * cannot unload from the process symbol table. Run in a fresh PHP
     * subprocess so no class definitions leak into sibling tests.
     */
    #[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
    public function testNullableReturnEnforcesConcreteType(): void
    {
        $fixture = CompiledFixture::compile($this->sourceDir, 'array-sugar-verify');
        $fixture->registerAutoload('App\\ArraySugar\\');
        try {
            require __DIR__ . '/../../fixture/compile/array_sugar/verify/nullable_return.php';
        } finally {
            $fixture->cleanup();
        }
    }

    /**
     * A length-changing `Name[]` -> `array` rewrite BEFORE a generic closure
     * shifts every following byte of the stripped source; the closure's
     * marker (recorded at the ORIGINAL `function` byte) must map back through
     * the byte-offset map, or the closure silently loses its type params and
     * the emitted output fatals at runtime.
     */
    #[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
    public function testSugarBeforeGenericClosureStillSpecializes(): void
    {
        $source = realpath(__DIR__ . '/../../fixture/compile/array_sugar_before_generic_closure/source')
            ?: throw new RuntimeException('Fixture missing');
        $fixture = CompiledFixture::compile($source, 'array-sugar-marker-offset');
        try {
            require __DIR__ . '/../../fixture/compile/array_sugar_before_generic_closure/verify/runtime.php';
        } finally {
            $fixture->cleanup();
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

    private function fqnToPath(string $fqn): string
    {
        $prefix = Registry::GENERATED_NAMESPACE_PREFIX . '\\';
        $rel = str_starts_with($fqn, $prefix) ? substr($fqn, strlen($prefix)) : $fqn;
        return $this->cacheDir . '/Generated/' . str_replace('\\', '/', $rel) . '.php';
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
