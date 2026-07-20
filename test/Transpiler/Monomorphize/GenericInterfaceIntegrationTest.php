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

final class GenericInterfaceIntegrationTest extends TestCase
{
    private string $sourceDir;
    private string $workDir;
    private string $targetDir;
    private string $cacheDir;

    protected function setUp(): void
    {
        $this->sourceDir = realpath(__DIR__ . '/../../fixture/compile/generic_interface/source')
            ?: throw new RuntimeException('Fixture missing');
        $this->workDir = sys_get_temp_dir() . '/xphp-generic-interface-' . uniqid('', true);
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

    public function testGenericInterfaceSpecializesAndIsImplementedBySpecializedClass(): void
    {
        $this->compile();

        $interfaceFqn = Registry::generatedFqn(
            'App\\GenericInterface\\Containers\\Container',
            [new TypeRef('App\\GenericInterface\\Models\\Plastic')],
        );
        $boxFqn = Registry::generatedFqn(
            'App\\GenericInterface\\Containers\\Box',
            [new TypeRef('App\\GenericInterface\\Models\\Plastic')],
        );

        $interfaceFile = $this->fqnToPath($interfaceFqn);
        $boxFile = $this->fqnToPath($boxFqn);
        self::assertFileExists($interfaceFile, 'specialized interface must be emitted');
        self::assertFileExists($boxFile, 'specialized class must be emitted');

        $boxContent = file_get_contents($boxFile);
        // Pin the implements target identity (snapshot's first-seen-order
        // normalization can't distinguish which specialized FQN gets used).
        self::assertStringContainsString('implements \\' . $interfaceFqn, $boxContent);

        $snapshotDir = __DIR__ . '/../../fixture/compile/generic_interface/verify/testGenericInterfaceSpecializesAndIsImplementedBySpecializedClass';
        SnapshotHash::assertMatches($snapshotDir . '/Container_Plastic.expected.php', file_get_contents($interfaceFile));
        SnapshotHash::assertMatches($snapshotDir . '/Box_Plastic.expected.php', $boxContent);
    }

    public function testGenericInterfaceTemplateIsReplacedByEmptyMarkerInOutput(): void
    {
        // Instead of stripping the generic interface template outright (which would
        // break `instanceof App\Containers\Container`), it gets replaced with an empty
        // marker interface at the same FQN. The original `function get(): T` method
        // signature is gone — only the empty marker remains.
        $this->compile();

        $rewrittenInterfacePath = $this->targetDir . '/Containers/Container.php';
        self::assertFileExists($rewrittenInterfacePath);
        $content = file_get_contents($rewrittenInterfacePath);
        // Negative invariant kept: the generic method signature must not
        // survive on the marker interface.
        self::assertStringNotContainsString('function get()', $content);
        SnapshotHash::assertMatches(
            __DIR__ . '/../../fixture/compile/generic_interface/verify/testGenericInterfaceTemplateIsReplacedByEmptyMarkerInOutput/Container.expected.php',
            $content,
        );
    }

    public function testSpecializedClassIsInstanceOfOriginalInterfaceMarker(): void
    {
        // F3 from the review: `$x instanceof App\GenericInterface\Containers\Container`
        // must hold for the specialized Box, traveling the chain
        //   Box_<Polymer> implements Container_<Polymer> extends App\GenericInterface\Containers\Container.
        // This is the marker-interface contract (item 5) applied to the interface case.
        $this->compile();

        $loader = new \Composer\Autoload\ClassLoader();
        $loader->addPsr4('App\\GenericInterface\\', $this->targetDir);
        $loader->addPsr4(Registry::GENERATED_NAMESPACE_PREFIX . '\\', $this->cacheDir . '/Generated');
        $loader->register();

        try {
            $boxFqn = Registry::generatedFqn('App\\GenericInterface\\Containers\\Box', [new TypeRef('App\\GenericInterface\\Models\\Polymer')]);
            $box = new $boxFqn(new \App\GenericInterface\Models\Polymer('PET'));

            self::assertInstanceOf('App\\GenericInterface\\Containers\\Container', $box, 'specialized Box must transitively satisfy the original Container interface marker');

            $r = new \ReflectionClass('App\\GenericInterface\\Containers\\Container');
            self::assertTrue($r->isInterface(), 'original generic interface FQN must now be the marker interface');
        } finally {
            $loader->unregister();
        }
    }

    #[RunInSeparateProcess]
    public function testSpecializedClassIsInstanceOfSpecializedInterfaceAtRuntime(): void
    {
        $fixture = CompiledFixture::compile($this->sourceDir, 'generic-interface-runtime');
        $fixture->registerAutoload('App\\GenericInterface\\');
        try {
            require __DIR__ . '/../../fixture/compile/generic_interface/verify/specialized_interface_runtime.php';
        } finally {
            $fixture->cleanup();
        }
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
