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

        $content = file_get_contents($file);
        self::assertStringContainsString('private array $items', $content, 'T[] property must lower to `array`');
        self::assertStringContainsString('public function all(): array', $content, 'T[] return type must lower to `array`');
        self::assertStringContainsString(
            'public function first(): ?\\App\\ArraySugar\\Models\\User',
            $content,
            '?T return type must specialize to ?<concrete>',
        );
        self::assertStringContainsString(
            'public function __construct(\\App\\ArraySugar\\Models\\User ...$items)',
            $content,
            'variadic T must specialize to <concrete>',
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

    public function testRuntimeNullableReturnEnforcesConcreteType(): void
    {
        $this->compile();

        $fqn = Registry::generatedFqn(
            'App\\ArraySugar\\Containers\\Collection',
            [new TypeRef('App\\ArraySugar\\Models\\User')],
        );
        $collectionFile = $this->fqnToPath($fqn);

        $runScript = $this->workDir . '/run.php';
        file_put_contents($runScript, <<<PHP
        <?php
        declare(strict_types=1);
        require '{$this->targetDir}/Models/User.php';
        require '{$this->targetDir}/Containers/Collection.php';
        require '{$collectionFile}';

        \$c = new \\{$fqn}(new \\App\\ArraySugar\\Models\\User('alice'), new \\App\\ArraySugar\\Models\\User('bob'));

        \$first = \$c->first();
        echo \$first instanceof \\App\\ArraySugar\\Models\\User ? "FIRST_OK" : "FIRST_BAD";
        echo "\\n";

        \$all = \$c->all();
        echo (is_array(\$all) && count(\$all) === 2) ? "ALL_OK" : "ALL_BAD";
        echo "\\n";

        \$empty = new \\{$fqn}();
        echo \$empty->first() === null ? "EMPTY_NULL_OK" : "EMPTY_NULL_BAD";
        echo "\\n";

        // Reflection: the ?T return type must show the concrete class, not "T" or "mixed".
        \$rt = (new \\ReflectionMethod('\\{$fqn}', 'first'))->getReturnType();
        echo \$rt instanceof \\ReflectionNamedType ? \$rt->getName() : 'UNEXPECTED_TYPE';
        echo "\\n";
        echo \$rt instanceof \\ReflectionNamedType && \$rt->allowsNull() ? "NULLABLE_OK" : "NULLABLE_BAD";
        echo "\\n";
        PHP);

        $output = [];
        $exit = 0;
        exec('php ' . escapeshellarg($runScript) . ' 2>&1', $output, $exit);
        self::assertSame(0, $exit, "run.php failed:\n" . implode("\n", $output));
        self::assertSame('FIRST_OK', $output[0]);
        self::assertSame('ALL_OK', $output[1]);
        self::assertSame('EMPTY_NULL_OK', $output[2]);
        self::assertSame('App\\ArraySugar\\Models\\User', $output[3]);
        self::assertSame('NULLABLE_OK', $output[4]);
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
