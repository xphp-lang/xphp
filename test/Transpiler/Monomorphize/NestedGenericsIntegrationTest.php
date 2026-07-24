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
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use XPHP\TestSupport\CompiledFixture;
use XPHP\TestSupport\SnapshotHash;

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

        self::assertSame(2, $result->generatedCount, 'expected Collection<Plastic> + Box<Collection<Plastic>>');

        $plastic = new TypeRef('App\\NestedInstantiation\\Models\\Plastic');
        $collectionOfPlastic = new TypeRef('App\\NestedInstantiation\\Containers\\Collection', [$plastic]);

        $collectionFqn = Registry::generatedFqn('App\\NestedInstantiation\\Containers\\Collection', [$plastic]);
        $boxFqn = Registry::generatedFqn('App\\NestedInstantiation\\Containers\\Box', [$collectionOfPlastic]);

        $collectionFile = $this->fqnToPath($collectionFqn);
        $boxFile = $this->fqnToPath($boxFqn);
        self::assertFileExists($collectionFile, "expected {$collectionFile}");
        self::assertFileExists($boxFile, "expected {$boxFile}");

        $collectionContent = file_get_contents($collectionFile);
        $boxContent = file_get_contents($boxFile);

        // Pin parent identities for the nested instantiation: Box<Collection<Plastic>>'s
        // `$item` must point at the Collection<Plastic> specialization specifically.
        self::assertStringContainsString('public \\' . $collectionFqn . ' $item', $boxContent);

        $useFile = $this->targetDir . '/Use.php';
        self::assertFileExists($useFile);
        $useContent = file_get_contents($useFile);
        // Pin which specialized FQN each `new` call refers to.
        self::assertStringContainsString('new \\' . $boxFqn . '()', $useContent);
        self::assertStringContainsString('new \\' . $collectionFqn . '()', $useContent);

        $snapshotDir = __DIR__ . '/../../fixture/compile/nested_instantiation/verify/testNestedInstantiationGeneratesInnerAndOuterSpecializations';
        SnapshotHash::assertMatches($snapshotDir . '/Collection_Plastic.expected.php', $collectionContent);
        SnapshotHash::assertMatches($snapshotDir . '/Box_Collection_Plastic.expected.php', $boxContent);
        SnapshotHash::assertMatches($snapshotDir . '/Use.expected.php', $useContent);

        $this->assertAllSyntacticallyValid();
    }

    public function testTypeHintInsideTemplateBodyGeneratesTransitiveSpecialization(): void
    {
        $sourceDir = realpath(__DIR__ . '/../../fixture/compile/nested_typehint/source')
            ?: throw new RuntimeException('Fixture missing');

        $result = $this->compile($sourceDir);

        self::assertSame(2, $result->generatedCount, 'expected Wrapper<Plastic> + transitively Box<Plastic>');

        $plastic = new TypeRef('App\\NestedTypehint\\Models\\Plastic');
        $boxFqn = Registry::generatedFqn('App\\NestedTypehint\\Containers\\Box', [$plastic]);
        $wrapperFqn = Registry::generatedFqn('App\\NestedTypehint\\Containers\\Wrapper', [$plastic]);

        $boxFile = $this->fqnToPath($boxFqn);
        $wrapperFile = $this->fqnToPath($wrapperFqn);
        self::assertFileExists($wrapperFile);
        self::assertFileExists($boxFile);

        $wrapperContent = file_get_contents($wrapperFile);
        $boxContent = file_get_contents($boxFile);

        // Pin parent identities: the Wrapper specialization must point at
        // the transitively-discovered Box<Plastic> specialization for both
        // the property declaration and the `new` site.
        self::assertStringContainsString('public \\' . $boxFqn . ' $box', $wrapperContent);
        self::assertStringContainsString('$this->box = new \\' . $boxFqn . '()', $wrapperContent);

        $useFile = $this->targetDir . '/Use.php';
        $useContent = file_get_contents($useFile);
        self::assertStringContainsString('new \\' . $wrapperFqn . '()', $useContent);

        $snapshotDir = __DIR__ . '/../../fixture/compile/nested_typehint/verify/testTypeHintInsideTemplateBodyGeneratesTransitiveSpecialization';
        SnapshotHash::assertMatches($snapshotDir . '/Wrapper_Plastic.expected.php', $wrapperContent);
        SnapshotHash::assertMatches($snapshotDir . '/Box_Plastic.expected.php', $boxContent);
        SnapshotHash::assertMatches($snapshotDir . '/Use.expected.php', $useContent);

        $this->assertAllSyntacticallyValid();
    }

    public function testRegistryContainsTransitiveInstantiations(): void
    {
        $sourceDir = realpath(__DIR__ . '/../../fixture/compile/nested_typehint/source')
            ?: throw new RuntimeException('Fixture missing');

        $this->compile($sourceDir);

        $registry = json_decode(file_get_contents($this->cacheDir . '/registry.json'), true);
        $fqns = array_column($registry['instantiations'], 'generatedFqn');

        $plastic = new TypeRef('App\\NestedTypehint\\Models\\Plastic');
        self::assertContains(
            Registry::generatedFqn('App\\NestedTypehint\\Containers\\Wrapper', [$plastic]),
            $fqns,
        );
        self::assertContains(
            Registry::generatedFqn('App\\NestedTypehint\\Containers\\Box', [$plastic]),
            $fqns,
        );
        self::assertCount(2, $fqns);
    }

    #[RunInSeparateProcess]
    public function testRuntimeReflectionAndTypeErrorOnNestedSpecialization(): void
    {
        $sourceDir = realpath(__DIR__ . '/../../fixture/compile/nested_typehint/source')
            ?: throw new RuntimeException('Fixture missing');

        $fixture = CompiledFixture::compile($sourceDir, 'nested-runtime');
        $fixture->registerAutoload('App\\NestedTypehint\\');
        try {
            $runtime = require __DIR__ . '/../../fixture/compile/nested_typehint/verify/nested_specialization_runtime.php';
            $runtime($fixture);
        } finally {
            $fixture->cleanup();
        }
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
