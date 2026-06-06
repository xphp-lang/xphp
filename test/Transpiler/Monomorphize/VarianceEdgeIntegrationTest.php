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

final class VarianceEdgeIntegrationTest extends TestCase
{
    private string $workDir;
    private string $targetDir;
    private string $cacheDir;

    protected function setUp(): void
    {
        $this->workDir = sys_get_temp_dir() . '/xphp-variance-' . uniqid('', true);
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

    public function testCovariantSubtypeEdgeIsEmittedAsExtendsForClassSpecializations(): void
    {
        // Fixture: `variance_covariant_happy/`. Producer<+T>, Banana <: Fruit.
        // Two specializations; Producer_Banana extends Producer_Fruit because
        // +T is covariant.
        $sourceDir = realpath(__DIR__ . '/../../fixture/compile/variance_covariant_happy/source')
            ?: throw new RuntimeException('Fixture missing');
        $compiler = $this->buildCompiler();
        $sources = (new NativeFileFinder())->find($sourceDir)
            ->filter(static fn (string $f): bool => str_ends_with($f, '.xphp'));

        $result = $compiler->compile($sources, $sourceDir, $this->targetDir, $this->cacheDir);

        self::assertSame(2, $result->generatedCount);
        $fruitFqn = Registry::generatedFqn(
            'App\\VarianceCovariantHappy\\Containers\\Producer',
            [new TypeRef('App\\VarianceCovariantHappy\\Models\\Fruit')],
        );
        $bananaFqn = Registry::generatedFqn(
            'App\\VarianceCovariantHappy\\Containers\\Producer',
            [new TypeRef('App\\VarianceCovariantHappy\\Models\\Banana')],
        );
        $bananaFile = $this->fqnToPath($bananaFqn);
        self::assertFileExists($bananaFile);
        // The Banana specialization must extend the Fruit specialization.
        $bananaContent = file_get_contents($bananaFile);
        self::assertStringContainsString('extends \\' . $fruitFqn, $bananaContent);
    }

    public function testContravariantSubtypeEdgeFlipsDirection(): void
    {
        // Fixture: `variance_contravariant_happy/`. Consumer<-T>, Dog <: Animal.
        // With contravariance, the edge flips: Consumer_Animal extends
        // Consumer_Dog (not the other way around).
        $sourceDir = realpath(__DIR__ . '/../../fixture/compile/variance_contravariant_happy/source')
            ?: throw new RuntimeException('Fixture missing');
        $compiler = $this->buildCompiler();
        $sources = (new NativeFileFinder())->find($sourceDir)
            ->filter(static fn (string $f): bool => str_ends_with($f, '.xphp'));

        $result = $compiler->compile($sources, $sourceDir, $this->targetDir, $this->cacheDir);

        self::assertSame(2, $result->generatedCount);
        $animalFqn = Registry::generatedFqn(
            'App\\VarianceContravariantHappy\\Containers\\Consumer',
            [new TypeRef('App\\VarianceContravariantHappy\\Models\\Animal')],
        );
        $dogFqn = Registry::generatedFqn(
            'App\\VarianceContravariantHappy\\Containers\\Consumer',
            [new TypeRef('App\\VarianceContravariantHappy\\Models\\Dog')],
        );
        $animalContent = file_get_contents($this->fqnToPath($animalFqn));
        self::assertStringContainsString('extends \\' . $dogFqn, $animalContent);
    }

    public function testEmittedSubtypeChainAutoloadsWithoutPhpFatal(): void
    {
        // The critical safety test: PHP applies LSP signature compat at
        // autoload time. If the variance edge emission produces an incompatible
        // method signature, PHP fatals with "Declaration of X::m must be
        // compatible with Y::m". Spawn a subprocess that requires every emitted
        // file for the covariant fixture; non-zero exit means a fatal at load.
        $sourceDir = realpath(__DIR__ . '/../../fixture/compile/variance_covariant_happy/source')
            ?: throw new RuntimeException('Fixture missing');
        $compiler = $this->buildCompiler();
        $sources = (new NativeFileFinder())->find($sourceDir)
            ->filter(static fn (string $f): bool => str_ends_with($f, '.xphp'));
        $compiler->compile($sources, $sourceDir, $this->targetDir, $this->cacheDir);

        $loader = $this->writeAutoloadCheck(
            'App\\VarianceCovariantHappy',
            [
                'Containers/Producer.php' => 'Producer',
            ],
            [
                'Models/Fruit.php',
                'Models/Banana.php',
            ],
        );

        $output = [];
        $exitCode = 0;
        exec('php ' . escapeshellarg($loader) . ' 2>&1', $output, $exitCode);
        self::assertSame(
            0,
            $exitCode,
            "Autoload-time fatal:\n" . implode("\n", $output),
        );
        self::assertContains('OK', $output);
    }

    public function testNoEdgeBetweenUnrelatedSpecializations(): void
    {
        // Banana and Apple both extend Fruit but not each other. The edge
        // between Producer_Banana and Producer_Apple must NOT be emitted (no
        // PHP-level subtype relationship between Banana and Apple).
        $sourceDir = $this->workDir . '/src-unrelated';
        mkdir($sourceDir . '/Containers', 0o755, true);
        mkdir($sourceDir . '/Models', 0o755, true);
        file_put_contents($sourceDir . '/Containers/Producer.xphp', <<<'PHP'
        <?php
        namespace App\Containers;
        class Producer<+T>
        {
            public function get(): T { throw new \LogicException; }
        }
        PHP);
        file_put_contents($sourceDir . '/Models/Fruit.xphp', <<<'PHP'
        <?php
        namespace App\Models;
        class Fruit {}
        PHP);
        file_put_contents($sourceDir . '/Models/Banana.xphp', <<<'PHP'
        <?php
        namespace App\Models;
        class Banana extends Fruit {}
        PHP);
        file_put_contents($sourceDir . '/Models/Apple.xphp', <<<'PHP'
        <?php
        namespace App\Models;
        class Apple extends Fruit {}
        PHP);
        file_put_contents($sourceDir . '/Use.xphp', <<<'PHP'
        <?php
        namespace App;
        $b = new Containers\Producer::<Models\Banana>;
        $a = new Containers\Producer::<Models\Apple>;
        PHP);

        $compiler = $this->buildCompiler();
        $sources = (new NativeFileFinder())->find($sourceDir)
            ->filter(static fn (string $f): bool => str_ends_with($f, '.xphp'));
        $compiler->compile($sources, $sourceDir, $this->targetDir, $this->cacheDir);

        $bananaFqn = Registry::generatedFqn(
            'App\\Containers\\Producer',
            [new TypeRef('App\\Models\\Banana')],
        );
        $appleFqn = Registry::generatedFqn(
            'App\\Containers\\Producer',
            [new TypeRef('App\\Models\\Apple')],
        );
        $bananaContent = file_get_contents($this->fqnToPath($bananaFqn));
        $appleContent = file_get_contents($this->fqnToPath($appleFqn));

        self::assertStringNotContainsString($appleFqn, $bananaContent);
        self::assertStringNotContainsString($bananaFqn, $appleContent);
    }

    public function testScalarArgsSkipVarianceEdgeEmission(): void
    {
        // `Producer<+T>` instantiated with int and string -- no PHP-level
        // subtype relationship between scalars, so no edge is emitted in
        // either direction.
        $sourceDir = $this->workDir . '/src-scalar';
        mkdir($sourceDir, 0o755, true);
        file_put_contents($sourceDir . '/Producer.xphp', <<<'PHP'
        <?php
        namespace App;
        class Producer<+T>
        {
            public function get(): T { throw new \LogicException; }
        }
        PHP);
        file_put_contents($sourceDir . '/Use.xphp', <<<'PHP'
        <?php
        namespace App;
        $i = new Producer::<int>;
        $s = new Producer::<string>;
        PHP);

        $compiler = $this->buildCompiler();
        $sources = (new NativeFileFinder())->find($sourceDir)
            ->filter(static fn (string $f): bool => str_ends_with($f, '.xphp'));
        $compiler->compile($sources, $sourceDir, $this->targetDir, $this->cacheDir);

        $intFqn = Registry::generatedFqn(
            'App\\Producer',
            [new TypeRef('int', isScalar: true)],
        );
        $stringFqn = Registry::generatedFqn(
            'App\\Producer',
            [new TypeRef('string', isScalar: true)],
        );
        $intContent = file_get_contents($this->fqnToPath($intFqn));
        $stringContent = file_get_contents($this->fqnToPath($stringFqn));

        self::assertStringNotContainsString($stringFqn, $intContent);
        self::assertStringNotContainsString($intFqn, $stringContent);
    }

    public function testTransitiveEdgesCollapseToDirectParent(): void
    {
        // Banana <: Apple <: Fruit. Three specializations of Producer<+T>.
        // The variance edges form a chain: Producer_Banana extends Producer_Apple,
        // Producer_Apple extends Producer_Fruit. Producer_Banana does NOT need a
        // direct edge to Producer_Fruit (PHP resolves it transitively).
        $sourceDir = $this->workDir . '/src-transitive';
        mkdir($sourceDir, 0o755, true);
        file_put_contents($sourceDir . '/P.xphp', <<<'PHP'
        <?php
        namespace App;
        class P<+T> { public function get(): T { throw new \LogicException; } }
        PHP);
        file_put_contents($sourceDir . '/Fruit.xphp', <<<'PHP'
        <?php
        namespace App;
        class Fruit {}
        PHP);
        file_put_contents($sourceDir . '/Apple.xphp', <<<'PHP'
        <?php
        namespace App;
        class Apple extends Fruit {}
        PHP);
        file_put_contents($sourceDir . '/Banana.xphp', <<<'PHP'
        <?php
        namespace App;
        class Banana extends Apple {}
        PHP);
        file_put_contents($sourceDir . '/Use.xphp', <<<'PHP'
        <?php
        namespace App;
        $f = new P::<Fruit>;
        $a = new P::<Apple>;
        $b = new P::<Banana>;
        PHP);

        $compiler = $this->buildCompiler();
        $sources = (new NativeFileFinder())->find($sourceDir)
            ->filter(static fn (string $f): bool => str_ends_with($f, '.xphp'));
        $compiler->compile($sources, $sourceDir, $this->targetDir, $this->cacheDir);

        $fruitFqn = Registry::generatedFqn('App\\P', [new TypeRef('App\\Fruit')]);
        $appleFqn = Registry::generatedFqn('App\\P', [new TypeRef('App\\Apple')]);
        $bananaFqn = Registry::generatedFqn('App\\P', [new TypeRef('App\\Banana')]);

        $bananaContent = file_get_contents($this->fqnToPath($bananaFqn));
        $appleContent = file_get_contents($this->fqnToPath($appleFqn));

        // Direct parent: Banana extends Apple.
        self::assertStringContainsString('extends \\' . $appleFqn, $bananaContent);
        // Transitive: Banana does NOT need a direct extends to Fruit.
        self::assertStringNotContainsString('extends \\' . $fruitFqn, $bananaContent);
        // Apple's direct parent IS Fruit.
        self::assertStringContainsString('extends \\' . $fruitFqn, $appleContent);
    }

    public function testAllThreeFeaturesCompose(): void
    {
        // Fixture: `variance_with_defaults_and_bounds/`. `Cache<+K : Stringable
        // & Countable, V = mixed>` composes covariance + intersection bound
        // + default. Verifies the integration: parse succeeds, bound is
        // checked, default pads, variance edges emit (single specialization
        // here -- no edge yet, but the pipeline runs cleanly).
        $sourceDir = realpath(__DIR__ . '/../../fixture/compile/variance_with_defaults_and_bounds/source')
            ?: throw new RuntimeException('Fixture missing');
        $compiler = $this->buildCompiler();
        $sources = (new NativeFileFinder())->find($sourceDir)
            ->filter(static fn (string $f): bool => str_ends_with($f, '.xphp'));

        $result = $compiler->compile($sources, $sourceDir, $this->targetDir, $this->cacheDir);

        self::assertSame(1, $result->generatedCount);
        // Defaulted V pads to mixed in the specialization.
        $cacheFqn = Registry::generatedFqn(
            'App\\VarianceWithDefaultsAndBounds\\Containers\\Cache',
            [
                new TypeRef('App\\VarianceWithDefaultsAndBounds\\Models\\Tag'),
                new TypeRef('mixed', isScalar: true),
            ],
        );
        self::assertFileExists($this->fqnToPath($cacheFqn));
    }

    public function testInterfaceSpecializationsGetMultiExtendsButFilterTransitives(): void
    {
        // Interface_ template with covariant +T. Banana <: Apple <: Fruit.
        // Each specialization's `extends` list must contain ONLY direct
        // supers, not transitively-implied ones -- so Banana's interface
        // extends [Apple] only, NOT [Apple, Fruit]. The filter-direct-supers
        // pass is exercised here on a multi-extends path that single-extends
        // Class_ tests don't cover.
        $sourceDir = $this->workDir . '/src-iface-transitive';
        mkdir($sourceDir, 0o755, true);
        file_put_contents($sourceDir . '/IProducer.xphp', <<<'PHP'
        <?php
        namespace App;
        interface IProducer<+T> { public function get(): T; }
        PHP);
        file_put_contents($sourceDir . '/Fruit.xphp', <<<'PHP'
        <?php
        namespace App;
        class Fruit {}
        PHP);
        file_put_contents($sourceDir . '/Apple.xphp', <<<'PHP'
        <?php
        namespace App;
        class Apple extends Fruit {}
        PHP);
        file_put_contents($sourceDir . '/Banana.xphp', <<<'PHP'
        <?php
        namespace App;
        class Banana extends Apple {}
        PHP);
        file_put_contents($sourceDir . '/Use.xphp', <<<'PHP'
        <?php
        namespace App;
        // Just reference the IProducer specializations to register them.
        function consume(IProducer::<Fruit> $f, IProducer::<Apple> $a, IProducer::<Banana> $b): void {}
        PHP);

        $compiler = $this->buildCompiler();
        $sources = (new NativeFileFinder())->find($sourceDir)
            ->filter(static fn (string $f): bool => str_ends_with($f, '.xphp'));
        $compiler->compile($sources, $sourceDir, $this->targetDir, $this->cacheDir);

        $fruitFqn = Registry::generatedFqn('App\\IProducer', [new TypeRef('App\\Fruit')]);
        $appleFqn = Registry::generatedFqn('App\\IProducer', [new TypeRef('App\\Apple')]);
        $bananaFqn = Registry::generatedFqn('App\\IProducer', [new TypeRef('App\\Banana')]);

        $bananaContent = file_get_contents($this->fqnToPath($bananaFqn));
        $appleContent = file_get_contents($this->fqnToPath($appleFqn));

        // Banana's interface extends list contains Apple (direct).
        // Banana does NOT extend Fruit (transitive via Apple).
        self::assertStringContainsString($appleFqn, $bananaContent);
        self::assertStringNotContainsString($fruitFqn, $bananaContent);
        // Apple's interface extends list contains Fruit (direct).
        self::assertStringContainsString($fruitFqn, $appleContent);
    }

    public function testInvariantTemplateProducesNoVarianceEdges(): void
    {
        // Pure invariant template -- no `+` / `-` markers, so the edge emitter
        // short-circuits and no specialization gets an extra extends.
        $sourceDir = $this->workDir . '/src-invariant';
        mkdir($sourceDir, 0o755, true);
        file_put_contents($sourceDir . '/Box.xphp', <<<'PHP'
        <?php
        namespace App;
        class Box<T>
        {
            public T $item;
        }
        PHP);
        file_put_contents($sourceDir . '/Fruit.xphp', <<<'PHP'
        <?php
        namespace App;
        class Fruit {}
        PHP);
        file_put_contents($sourceDir . '/Banana.xphp', <<<'PHP'
        <?php
        namespace App;
        class Banana extends Fruit {}
        PHP);
        file_put_contents($sourceDir . '/Use.xphp', <<<'PHP'
        <?php
        namespace App;
        $f = new Box::<Fruit>;
        $b = new Box::<Banana>;
        PHP);

        $compiler = $this->buildCompiler();
        $sources = (new NativeFileFinder())->find($sourceDir)
            ->filter(static fn (string $f): bool => str_ends_with($f, '.xphp'));
        $compiler->compile($sources, $sourceDir, $this->targetDir, $this->cacheDir);

        $fruitFqn = Registry::generatedFqn('App\\Box', [new TypeRef('App\\Fruit')]);
        $bananaFqn = Registry::generatedFqn('App\\Box', [new TypeRef('App\\Banana')]);
        $bananaContent = file_get_contents($this->fqnToPath($bananaFqn));

        // No extends to the Fruit specialization (invariant -- no variance edges).
        self::assertStringNotContainsString($fruitFqn, $bananaContent);
    }

    /**
     * Build a PHP script that registers an autoloader mapping FQNs to file
     * paths, then references one specialization of each kind. The autoloader
     * resolves on-demand, so the parent-before-child file order doesn't have
     * to be hardcoded.
     *
     * @param array<string, string> $markerInterfaces map of "relative/path.php" => interfaceShortName
     * @param list<string> $modelFiles relative paths
     */
    private function writeAutoloadCheck(
        string $appNamespace,
        array $markerInterfaces,
        array $modelFiles,
    ): string {
        $loader = $this->workDir . '/load.php';
        $body = "<?php\n\n";

        // Build the FQN-to-path map for the autoloader.
        $map = [];
        // Marker interfaces.
        foreach ($markerInterfaces as $relPath => $shortName) {
            $fqn = $appNamespace . '\\Containers\\' . $shortName;
            $map[$fqn] = $this->targetDir . '/' . $relPath;
        }
        // Model classes -- derive FQN from the file path.
        foreach ($modelFiles as $relPath) {
            $base = basename($relPath, '.php');
            $fqn = $appNamespace . '\\Models\\' . $base;
            $map[$fqn] = $this->targetDir . '/' . $relPath;
        }
        // Specialized classes under .xphp-cache/Generated.
        $generatedDir = $this->cacheDir . '/Generated';
        $specializedFqns = [];
        if (is_dir($generatedDir)) {
            $iter = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($generatedDir));
            foreach ($iter as $file) {
                if ($file->isFile() && str_ends_with($file->getFilename(), '.php')) {
                    // FQN: XPHP\Generated + relative dir + class name (basename without .php).
                    $relPath = substr($file->getPathname(), strlen($generatedDir) + 1);
                    $fqn = 'XPHP\\Generated\\' . str_replace('/', '\\', substr($relPath, 0, -4));
                    $map[$fqn] = $file->getPathname();
                    $specializedFqns[] = $fqn;
                }
            }
        }

        $body .= "spl_autoload_register(function (string \$class): void {\n";
        $body .= "    \$map = " . var_export($map, true) . ";\n";
        $body .= "    if (isset(\$map[\$class])) { require_once \$map[\$class]; }\n";
        $body .= "});\n\n";

        // Reference each specialized class to force autoload.
        foreach ($specializedFqns as $fqn) {
            $body .= "class_exists(" . var_export($fqn, true) . ");\n";
        }
        $body .= "echo \"OK\\n\";\n";
        file_put_contents($loader, $body);
        return $loader;
    }

    private function fqnToPath(string $fqn): string
    {
        $prefix = Registry::GENERATED_NAMESPACE_PREFIX . '\\';
        $rel = str_starts_with($fqn, $prefix) ? substr($fqn, strlen($prefix)) : $fqn;
        return $this->cacheDir . '/Generated/' . str_replace('\\', '/', $rel) . '.php';
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
