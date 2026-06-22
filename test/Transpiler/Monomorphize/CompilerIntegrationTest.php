<?php

declare(strict_types=1);

namespace XPHP\Transpiler\Monomorphize;

use PhpParser\ParserFactory;
use PhpParser\PrettyPrinter\Standard as StandardPrinter;
use PHPUnit\Framework\TestCase;
use XPHP\FileSystem\FileFinder\NativeFileFinder;
use XPHP\FileSystem\FilepathArray;
use XPHP\FileSystem\FileReader\NativeFileReader;
use XPHP\FileSystem\FileWriter\NativeFileWriter;
use XPHP\TestSupport\SnapshotHash;

final class CompilerIntegrationTest extends TestCase
{
    private string $sourceDir;
    private string $workDir;
    private string $targetDir;
    private string $cacheDir;

    protected function setUp(): void
    {
        $this->sourceDir = realpath(__DIR__ . '/../../fixture/compile/box_generic/source')
            ?: throw new \RuntimeException('Fixture not found');
        $this->workDir = sys_get_temp_dir() . '/xphp-test-' . uniqid('', true);
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

    public function testCompilesGenericFixtureEndToEnd(): void
    {
        $compiler = $this->buildCompiler();

        $sources = (new NativeFileFinder())
            ->find($this->sourceDir)
            ->filter(static fn (string $f): bool => str_ends_with($f, '.xphp'));

        $result = $compiler->compile($sources, $this->sourceDir, $this->targetDir, $this->cacheDir);

        self::assertSame(5, $result->sourceCount, 'expected 5 source .xphp files (4 top-level + 1 in sub/)');
        self::assertSame(2, $result->generatedCount, 'expected 2 specializations (Box<Plastic>, Box<Metal>)');

        $boxPlasticFqn = Registry::generatedFqn('App\\BoxGeneric\\Containers\\Box', [new TypeRef('App\\BoxGeneric\\Models\\Plastic')]);
        $boxMetalFqn = Registry::generatedFqn('App\\BoxGeneric\\Containers\\Box', [new TypeRef('App\\BoxGeneric\\Models\\Metal')]);

        $boxPlasticFile = $this->fqnToPath($boxPlasticFqn);
        $boxMetalFile = $this->fqnToPath($boxMetalFqn);
        self::assertFileExists($boxPlasticFile, "expected {$boxPlasticFile}");
        self::assertFileExists($boxMetalFile);

        $boxPlasticContent = file_get_contents($boxPlasticFile);
        $useFile = $this->targetDir . '/Use.php';
        self::assertFileExists($useFile);
        $useContent = file_get_contents($useFile);
        $boxFile = $this->targetDir . '/Containers/Box.php';
        self::assertFileExists($boxFile);
        $boxContent = file_get_contents($boxFile);

        // Pin parent identities: the rewritten Use.php must reference
        // both specialized FQNs by their exact paths (snapshot's
        // first-seen-order normalization can't distinguish them).
        self::assertStringContainsString('new \\' . $boxPlasticFqn . '()', $useContent);
        self::assertStringContainsString('new \\' . $boxMetalFqn . '()', $useContent);
        // Negative invariant kept: the generic template definition must
        // not survive at the original FQN.
        self::assertStringNotContainsString('class Box', $boxContent);

        $snapshotDir = __DIR__ . '/../../fixture/compile/box_generic/verify/testCompilesGenericFixtureEndToEnd';
        SnapshotHash::assertMatches($snapshotDir . '/Box_Plastic.expected.php', $boxPlasticContent);
        SnapshotHash::assertMatches($snapshotDir . '/Use.expected.php', $useContent);
        SnapshotHash::assertMatches($snapshotDir . '/Box_marker.expected.php', $boxContent);

        $registryPath = $this->cacheDir . '/registry.json';
        self::assertFileExists($registryPath);
        $registry = json_decode(file_get_contents($registryPath), true);
        self::assertIsArray($registry);
        self::assertCount(1, $registry['definitions']);
        self::assertCount(2, $registry['instantiations']);
        $generatedFqns = array_column($registry['instantiations'], 'generatedFqn');
        self::assertContains($boxPlasticFqn, $generatedFqns);
        self::assertContains($boxMetalFqn, $generatedFqns);
    }

    public function testPsr4SourceLayoutMirrorsToTarget(): void
    {
        $compiler = $this->buildCompiler();
        $sources = (new NativeFileFinder())
            ->find($this->sourceDir)
            ->filter(static fn (string $f): bool => str_ends_with($f, '.xphp'));
        $compiler->compile($sources, $this->sourceDir, $this->targetDir, $this->cacheDir);

        // src/Helpers/Helper.xphp -> dist/Helpers/Helper.php (PSR-4 layout preserved).
        // This kills the relativePath() IfNegation / ReturnRemoval mutants which would
        // otherwise collapse all paths into basename() and lose the directory prefix.
        $expected = $this->targetDir . '/Helpers/Helper.php';
        self::assertFileExists($expected, "expected source-relative target at {$expected}");
        self::assertFileDoesNotExist(
            $this->targetDir . '/Helper.php',
            'PSR-4 source must not flatten to top-level target',
        );
    }

    public function testMultiRootEmitComputesEachFileRelativeToItsOwnRoot(): void
    {
        // Two source roots: each file must emit relative to ITS root (no flatten). Without the
        // root map, the second root's file would collapse to dist/Thing.php via basename().
        $rootA = $this->workDir . '/rootA';
        $rootB = $this->workDir . '/rootB';
        mkdir($rootA . '/Containers', 0o755, true);
        mkdir($rootB . '/Models', 0o755, true);
        $boxFile = $rootA . '/Containers/Box.xphp';
        $thingFile = $rootB . '/Models/Thing.xphp';
        file_put_contents($boxFile, "<?php\nnamespace App\\Multi\\Containers;\nclass Box {}\n");
        file_put_contents($thingFile, "<?php\nnamespace App\\Multi\\Models;\nclass Thing {}\n");

        $sources = new FilepathArray($boxFile, $thingFile);
        $rootByFile = [$boxFile => $rootA, $thingFile => $rootB];

        $this->buildCompiler()->compile($sources, $rootA, $this->targetDir, $this->cacheDir, $rootByFile);

        self::assertFileExists($this->targetDir . '/Containers/Box.php', 'rootA file relative to rootA');
        self::assertFileExists($this->targetDir . '/Models/Thing.php', 'rootB file relative to rootB');
        self::assertFileDoesNotExist(
            $this->targetDir . '/Thing.php',
            'a second root must not flatten to the target top level',
        );
    }

    public function testFileAbsentFromRootMapFallsBackToSourceDir(): void
    {
        // A file present in $sources but absent from $rootByFile uses $sourceDir as its base
        // (the defensive `?? $sourceDir` fallback), while a mapped file uses its own root.
        $rootA = $this->workDir . '/fbA';
        $rootB = $this->workDir . '/fbB';
        mkdir($rootA . '/Sub', 0o755, true);
        mkdir($rootB . '/Models', 0o755, true);
        $mapped = $rootB . '/Models/Mapped.xphp';
        $unmapped = $rootA . '/Sub/Unmapped.xphp';
        file_put_contents($mapped, "<?php\nnamespace App\\Fb\\Models;\nclass Mapped {}\n");
        file_put_contents($unmapped, "<?php\nnamespace App\\Fb\\Sub;\nclass Unmapped {}\n");

        $sources = new FilepathArray($mapped, $unmapped);
        // Only $mapped is in the map; $unmapped falls back to $sourceDir (= $rootA).
        $rootByFile = [$mapped => $rootB];

        $this->buildCompiler()->compile($sources, $rootA, $this->targetDir, $this->cacheDir, $rootByFile);

        self::assertFileExists($this->targetDir . '/Models/Mapped.php', 'mapped file relative to its root');
        self::assertFileExists($this->targetDir . '/Sub/Unmapped.php', 'unmapped file relative to $sourceDir fallback');
    }

    public function testEmitPathCollisionAcrossRootsIsAnError(): void
    {
        // Two roots each holding a file at the same relative path → same target path. That must be
        // a hard error, not a silent overwrite.
        $rootA = $this->workDir . '/cA';
        $rootB = $this->workDir . '/cB';
        mkdir($rootA, 0o755, true);
        mkdir($rootB, 0o755, true);
        $a = $rootA . '/Dup.xphp';
        $b = $rootB . '/Dup.xphp';
        file_put_contents($a, "<?php\nnamespace App\\CollA;\nclass Dup {}\n");
        file_put_contents($b, "<?php\nnamespace App\\CollB;\nclass Dup {}\n");

        $sources = new FilepathArray($a, $b);
        $rootByFile = [$a => $rootA, $b => $rootB];

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Emit path collision');
        $this->buildCompiler()->compile($sources, $rootA, $this->targetDir, $this->cacheDir, $rootByFile);
    }

    public function testGeneratedCodeIsLoadableViaPsr4Autoloader(): void
    {
        $compiler = $this->buildCompiler();
        $sources = (new NativeFileFinder())
            ->find($this->sourceDir)
            ->filter(static fn (string $f): bool => str_ends_with($f, '.xphp'));
        $compiler->compile($sources, $this->sourceDir, $this->targetDir, $this->cacheDir);

        // End-to-end PSR-4 roundtrip:
        //   .xphp source (PSR-4 layout)
        //   -> compile to .php (target dir preserves PSR-4)
        //   -> register composer's PSR-4 autoloader
        //   -> class_exists() resolves both user code and generated specializations
        //      without explicit require statements
        //   -> reflection on the specialized class reports the real concrete type
        $loader = new \Composer\Autoload\ClassLoader();
        $loader->addPsr4('App\\BoxGeneric\\', $this->targetDir);
        $loader->addPsr4(Registry::GENERATED_NAMESPACE_PREFIX . '\\', $this->cacheDir . '/Generated');
        $loader->register();

        try {
            self::assertTrue(class_exists('App\\BoxGeneric\\Models\\Plastic'), 'user class App\\BoxGeneric\\Models\\Plastic must autoload from the PSR-4 target dir');

            $boxPlasticFqn = Registry::generatedFqn('App\\BoxGeneric\\Containers\\Box', [new TypeRef('App\\BoxGeneric\\Models\\Plastic')]);
            self::assertTrue(class_exists($boxPlasticFqn), "specialized class {$boxPlasticFqn} must autoload from the PSR-4 cache dir");

            // Confirm the autoloaded specialized class carries the real concrete type on its property.
            $type = (new \ReflectionProperty($boxPlasticFqn, 'item'))->getType();
            self::assertInstanceOf(\ReflectionNamedType::class, $type);
            self::assertSame('App\\BoxGeneric\\Models\\Plastic', $type->getName());
        } finally {
            $loader->unregister();
        }
    }

    public function testInstanceofAgainstOriginalTemplateMatchesAllSpecializations(): void
    {
        $compiler = $this->buildCompiler();
        $sources = (new NativeFileFinder())
            ->find($this->sourceDir)
            ->filter(static fn (string $f): bool => str_ends_with($f, '.xphp'));
        $compiler->compile($sources, $this->sourceDir, $this->targetDir, $this->cacheDir);

        $loader = new \Composer\Autoload\ClassLoader();
        $loader->addPsr4('App\\BoxGeneric\\', $this->targetDir);
        $loader->addPsr4(Registry::GENERATED_NAMESPACE_PREFIX . '\\', $this->cacheDir . '/Generated');
        $loader->register();

        try {
            $boxPlasticFqn = Registry::generatedFqn('App\\BoxGeneric\\Containers\\Box', [new TypeRef('App\\BoxGeneric\\Models\\Plastic')]);
            $boxMetalFqn = Registry::generatedFqn('App\\BoxGeneric\\Containers\\Box', [new TypeRef('App\\BoxGeneric\\Models\\Metal')]);

            // Use reflection rather than `new $fqn()` to avoid coupling this test to whether
            // the box_generic Box<T> template happens to declare a constructor at any given
            // moment — the marker-interface contract is what we're locking, not the
            // specialized class's signature.
            $plasticRefl = new \ReflectionClass($boxPlasticFqn);
            $metalRefl = new \ReflectionClass($boxMetalFqn);

            // Both specializations satisfy `instanceof OriginalTemplate` via the
            // marker interface emitted at the original FQN.
            self::assertTrue($plasticRefl->implementsInterface('App\\BoxGeneric\\Containers\\Box'));
            self::assertTrue($metalRefl->implementsInterface('App\\BoxGeneric\\Containers\\Box'));

            // And reflection sees the marker as an interface, not the old class.
            $r = new \ReflectionClass('App\\BoxGeneric\\Containers\\Box');
            self::assertTrue($r->isInterface(), 'original generic class FQN must now be the marker interface');
        } finally {
            $loader->unregister();
        }
    }

    public function testGeneratedAndRewrittenFilesAreSyntacticallyValid(): void
    {
        $compiler = $this->buildCompiler();
        $sources = (new NativeFileFinder())
            ->find($this->sourceDir)
            ->filter(static fn (string $f): bool => str_ends_with($f, '.xphp'));
        $compiler->compile($sources, $this->sourceDir, $this->targetDir, $this->cacheDir);

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

    private function fqnToPath(string $fqn): string
    {
        $prefix = Registry::GENERATED_NAMESPACE_PREFIX . '\\';
        $rel = str_starts_with($fqn, $prefix) ? substr($fqn, strlen($prefix)) : $fqn;
        return $this->cacheDir . '/Generated/' . str_replace('\\', '/', $rel) . '.php';
    }

    private static function shortName(string $fqn): string
    {
        $pos = strrpos($fqn, '\\');
        return $pos === false ? $fqn : substr($fqn, $pos + 1);
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
