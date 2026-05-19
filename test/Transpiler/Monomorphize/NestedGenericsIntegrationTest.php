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

final class NestedGenericsIntegrationTest extends TestCase
{
    private string $workDir;
    private string $targetDir;
    private string $cacheDir;

    protected function setUp(): void
    {
        $this->workDir = sys_get_temp_dir() . '/xphp-nested-' . uniqid('', true);
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

    public function testNestedInstantiationGeneratesInnerAndOuterSpecializations(): void
    {
        $sourceDir = realpath(__DIR__ . '/../../fixture/compile/nested_instantiation/source')
            ?: throw new RuntimeException('Fixture missing');

        $result = $this->compile($sourceDir);

        self::assertSame(2, $result->generatedCount, 'expected Lst<Plastic> + Box<Lst<Plastic>>');

        $plastic = new TypeRef('App\\Models\\Plastic');
        $lstOfPlastic = new TypeRef('App\\Containers\\Lst', [$plastic]);

        $lstFqn = Registry::generatedFqn('App\\Containers\\Lst', [$plastic]);
        $boxFqn = Registry::generatedFqn('App\\Containers\\Box', [$lstOfPlastic]);

        $lstFile = $this->fqnToPath($lstFqn);
        $boxFile = $this->fqnToPath($boxFqn);
        self::assertFileExists($lstFile, "expected {$lstFile}");
        self::assertFileExists($boxFile, "expected {$boxFile}");

        $lstContent = file_get_contents($lstFile);
        self::assertStringContainsString('namespace XPHP\\Generated\\App\\Containers\\Lst', $lstContent);
        self::assertStringContainsString('public function push(\\App\\Models\\Plastic $val)', $lstContent);
        self::assertStringContainsString('public function first(): \\App\\Models\\Plastic', $lstContent);

        $boxContent = file_get_contents($boxFile);
        self::assertStringContainsString('namespace XPHP\\Generated\\App\\Containers\\Box', $boxContent);
        self::assertStringContainsString('public \\' . $lstFqn . ' $item', $boxContent);
        self::assertStringContainsString('public function set(\\' . $lstFqn . ' $val)', $boxContent);

        $useFile = $this->targetDir . '/Use.php';
        self::assertFileExists($useFile);
        $useContent = file_get_contents($useFile);
        self::assertStringContainsString('new \\' . $boxFqn . '()', $useContent);
        self::assertStringContainsString('new \\' . $lstFqn . '()', $useContent);

        $this->assertAllSyntacticallyValid();
    }

    public function testTypeHintInsideTemplateBodyGeneratesTransitiveSpecialization(): void
    {
        $sourceDir = realpath(__DIR__ . '/../../fixture/compile/nested_typehint/source')
            ?: throw new RuntimeException('Fixture missing');

        $result = $this->compile($sourceDir);

        self::assertSame(2, $result->generatedCount, 'expected Wrapper<Plastic> + transitively Box<Plastic>');

        $plastic = new TypeRef('App\\Models\\Plastic');
        $boxFqn = Registry::generatedFqn('App\\Containers\\Box', [$plastic]);
        $wrapperFqn = Registry::generatedFqn('App\\Containers\\Wrapper', [$plastic]);

        $boxFile = $this->fqnToPath($boxFqn);
        $wrapperFile = $this->fqnToPath($wrapperFqn);
        self::assertFileExists($wrapperFile);
        self::assertFileExists($boxFile);

        $wrapperContent = file_get_contents($wrapperFile);
        self::assertStringContainsString('namespace XPHP\\Generated\\App\\Containers\\Wrapper', $wrapperContent);
        self::assertStringContainsString('public \\' . $boxFqn . ' $box', $wrapperContent);
        self::assertStringContainsString('$this->box = new \\' . $boxFqn . '()', $wrapperContent);
        self::assertStringContainsString('public function setBoxed(\\App\\Models\\Plastic $val)', $wrapperContent);
        self::assertStringContainsString('public function getBoxed(): \\App\\Models\\Plastic', $wrapperContent);

        $boxContent = file_get_contents($boxFile);
        self::assertStringContainsString('public \\App\\Models\\Plastic $item', $boxContent);

        $useFile = $this->targetDir . '/Use.php';
        $useContent = file_get_contents($useFile);
        self::assertStringContainsString('new \\' . $wrapperFqn . '()', $useContent);

        $this->assertAllSyntacticallyValid();
    }

    public function testRegistryContainsTransitiveInstantiations(): void
    {
        $sourceDir = realpath(__DIR__ . '/../../fixture/compile/nested_typehint/source')
            ?: throw new RuntimeException('Fixture missing');

        $this->compile($sourceDir);

        $registry = json_decode(file_get_contents($this->cacheDir . '/registry.json'), true);
        $fqns = array_column($registry['instantiations'], 'generatedFqn');

        $plastic = new TypeRef('App\\Models\\Plastic');
        self::assertContains(
            Registry::generatedFqn('App\\Containers\\Wrapper', [$plastic]),
            $fqns,
        );
        self::assertContains(
            Registry::generatedFqn('App\\Containers\\Box', [$plastic]),
            $fqns,
        );
        self::assertCount(2, $fqns);
    }

    public function testRuntimeReflectionAndTypeErrorOnNestedSpecialization(): void
    {
        $sourceDir = realpath(__DIR__ . '/../../fixture/compile/nested_typehint/source')
            ?: throw new RuntimeException('Fixture missing');

        $this->compile($sourceDir);

        $plastic = new TypeRef('App\\Models\\Plastic');
        $boxFqn = Registry::generatedFqn('App\\Containers\\Box', [$plastic]);
        $wrapperFqn = Registry::generatedFqn('App\\Containers\\Wrapper', [$plastic]);
        $boxFile = $this->fqnToPath($boxFqn);
        $wrapperFile = $this->fqnToPath($wrapperFqn);

        $runScript = $this->workDir . '/run.php';
        file_put_contents($runScript, <<<PHP
        <?php
        declare(strict_types=1);
        require '{$this->targetDir}/Models/Plastic.php';
        require '{$boxFile}';
        require '{$wrapperFile}';
        \$type = (new ReflectionProperty('{$wrapperFqn}', 'box'))->getType();
        echo \$type instanceof ReflectionNamedType ? \$type->getName() : 'unknown';
        echo "\\n";
        try {
            \$cls = '{$wrapperFqn}';
            \$w = new \$cls();
            \$w->setBoxed('not a plastic');
            echo "MISSED_TYPE_ERROR";
        } catch (TypeError \$e) {
            echo "TYPE_ERROR_OK";
        }
        PHP);

        $output = [];
        $exit = 0;
        exec('php ' . escapeshellarg($runScript) . ' 2>&1', $output, $exit);

        self::assertSame(0, $exit, "run.php failed:\n" . implode("\n", $output));
        self::assertSame($boxFqn, $output[0]);
        self::assertSame('TYPE_ERROR_OK', $output[1]);
    }

    private function compile(string $sourceDir): CompileResult
    {
        $compiler = $this->buildCompiler();
        $sources = (new NativeFileFinder())
            ->find($sourceDir)
            ->filter(static fn (string $f): bool => str_ends_with($f, '.xphp'));
        return $compiler->compile($sources, $sourceDir, $this->targetDir, $this->cacheDir);
    }

    private function fqnToPath(string $fqn): string
    {
        $prefix = Registry::GENERATED_NAMESPACE_PREFIX . '\\';
        $rel = str_starts_with($fqn, $prefix) ? substr($fqn, strlen($prefix)) : $fqn;
        return $this->cacheDir . '/Generated/' . str_replace('\\', '/', $rel) . '.php';
    }

    private function assertAllSyntacticallyValid(): void
    {
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
