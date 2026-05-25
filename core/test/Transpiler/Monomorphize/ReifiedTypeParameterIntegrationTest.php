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

/**
 * Locks the reified-T contract: inside a `class Reified<T>` body, every bare
 * single-segment `T` reference -- `new T()`, `T::class`, `T::method()`,
 * `instanceof T`, `is_a($x, T::class)` -- is substituted at codegen with the
 * concrete class. Specializer's substituting visitor matches any bare Name node
 * whose text equals a type-param key, so all five patterns trip the same code
 * path and produce a uniform substitution.
 */
final class ReifiedTypeParameterIntegrationTest extends TestCase
{
    private string $sourceDir;
    private string $workDir;
    private string $targetDir;
    private string $cacheDir;

    protected function setUp(): void
    {
        $this->sourceDir = realpath(__DIR__ . '/../../fixture/compile/reified_t/source')
            ?: throw new RuntimeException('Fixture missing');
        $this->workDir = sys_get_temp_dir() . '/xphp-reified-t-' . uniqid('', true);
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

    public function testNewTSubstitutesToConcreteClass(): void
    {
        $this->compile();

        $alphaContent = file_get_contents($this->specializedFile('App\\Models\\Alpha'));
        $betaContent = file_get_contents($this->specializedFile('App\\Models\\Beta'));

        self::assertStringContainsString('new \\App\\Models\\Alpha()', $alphaContent);
        self::assertStringContainsString('new \\App\\Models\\Beta()', $betaContent);

        self::assertStringNotContainsString('new T(', $alphaContent);
        self::assertStringNotContainsString('new T(', $betaContent);
    }

    public function testTClassSubstitutesToConcreteClass(): void
    {
        $this->compile();

        $alphaContent = file_get_contents($this->specializedFile('App\\Models\\Alpha'));
        $betaContent = file_get_contents($this->specializedFile('App\\Models\\Beta'));

        self::assertStringContainsString('\\App\\Models\\Alpha::class', $alphaContent);
        self::assertStringContainsString('\\App\\Models\\Beta::class', $betaContent);

        self::assertStringNotContainsString('T::class', $alphaContent);
        self::assertStringNotContainsString('T::class', $betaContent);
    }

    public function testStaticMethodCallOnTSubstitutes(): void
    {
        $this->compile();

        $alphaContent = file_get_contents($this->specializedFile('App\\Models\\Alpha'));
        $betaContent = file_get_contents($this->specializedFile('App\\Models\\Beta'));

        self::assertStringContainsString('\\App\\Models\\Alpha::describe()', $alphaContent);
        self::assertStringContainsString('\\App\\Models\\Beta::describe()', $betaContent);

        self::assertStringNotContainsString('T::describe', $alphaContent);
        self::assertStringNotContainsString('T::describe', $betaContent);
    }

    public function testInstanceofTSubstitutes(): void
    {
        $this->compile();

        $alphaContent = file_get_contents($this->specializedFile('App\\Models\\Alpha'));
        $betaContent = file_get_contents($this->specializedFile('App\\Models\\Beta'));

        self::assertStringContainsString('instanceof \\App\\Models\\Alpha', $alphaContent);
        self::assertStringContainsString('instanceof \\App\\Models\\Beta', $betaContent);

        self::assertStringNotContainsString('instanceof T', $alphaContent);
        self::assertStringNotContainsString('instanceof T', $betaContent);
    }

    public function testIsAComposesWithSubstitutedTClass(): void
    {
        $this->compile();

        $alphaContent = file_get_contents($this->specializedFile('App\\Models\\Alpha'));
        $betaContent = file_get_contents($this->specializedFile('App\\Models\\Beta'));

        self::assertMatchesRegularExpression(
            '/is_a\(\$x,\s*\\\\App\\\\Models\\\\Alpha::class\)/',
            $alphaContent,
        );
        self::assertMatchesRegularExpression(
            '/is_a\(\$x,\s*\\\\App\\\\Models\\\\Beta::class\)/',
            $betaContent,
        );
    }

    public function testSpecializationsAreIndependent(): void
    {
        $this->compile();

        $alphaContent = file_get_contents($this->specializedFile('App\\Models\\Alpha'));
        $betaContent = file_get_contents($this->specializedFile('App\\Models\\Beta'));

        // Each specialization references only its own concrete type, never the other.
        self::assertStringNotContainsString('App\\Models\\Beta', $alphaContent);
        self::assertStringNotContainsString('App\\Models\\Alpha', $betaContent);
    }

    public function testReifiedSubstitutionRoundTripsAtRuntime(): void
    {
        $this->compile();

        $alphaFqn = Registry::generatedFqn('App\\Containers\\Reified', [new TypeRef('App\\Models\\Alpha')]);
        $betaFqn = Registry::generatedFqn('App\\Containers\\Reified', [new TypeRef('App\\Models\\Beta')]);

        $alphaFile = $this->specializedFile('App\\Models\\Alpha');
        $betaFile = $this->specializedFile('App\\Models\\Beta');
        $alphaModel = $this->targetDir . '/Models/Alpha.php';
        $betaModel = $this->targetDir . '/Models/Beta.php';
        $markerInterface = $this->targetDir . '/Containers/Reified.php';

        $runScript = $this->workDir . '/run.php';
        $script = <<<PHP
        <?php
        declare(strict_types=1);
        require {$this->phpExport($alphaModel)};
        require {$this->phpExport($betaModel)};
        require {$this->phpExport($markerInterface)};
        require {$this->phpExport($alphaFile)};
        require {$this->phpExport($betaFile)};

        \$alphaFqn = {$this->phpExport($alphaFqn)};
        \$betaFqn = {$this->phpExport($betaFqn)};
        \$forAlpha = new \$alphaFqn();
        \$forBeta = new \$betaFqn();

        \$alphaInstance = \$forAlpha->make();
        echo get_class(\$alphaInstance) === 'App\\\\Models\\\\Alpha' ? 'MAKE_ALPHA_OK' : 'MAKE_ALPHA_BAD', "\\n";
        echo \$forAlpha->className() === 'App\\\\Models\\\\Alpha' ? 'CLASS_ALPHA_OK' : 'CLASS_ALPHA_BAD', "\\n";
        echo \$forAlpha->describeStatic() === 'alpha' ? 'DESCRIBE_ALPHA_OK' : 'DESCRIBE_ALPHA_BAD', "\\n";
        echo \$forAlpha->isInstance(\$alphaInstance) ? 'INSTANCEOF_ALPHA_HIT_OK' : 'INSTANCEOF_ALPHA_HIT_BAD', "\\n";
        echo \$forAlpha->isInstance(\$forBeta->make()) ? 'INSTANCEOF_ALPHA_MISS_BAD' : 'INSTANCEOF_ALPHA_MISS_OK', "\\n";
        echo \$forAlpha->isInstanceViaIsA(\$alphaInstance) ? 'ISA_ALPHA_OK' : 'ISA_ALPHA_BAD', "\\n";

        echo \$forBeta->className() === 'App\\\\Models\\\\Beta' ? 'CLASS_BETA_OK' : 'CLASS_BETA_BAD', "\\n";
        echo \$forBeta->describeStatic() === 'beta' ? 'DESCRIBE_BETA_OK' : 'DESCRIBE_BETA_BAD', "\\n";
        PHP;
        file_put_contents($runScript, $script);

        $output = [];
        $exit = 0;
        exec('php ' . escapeshellarg($runScript) . ' 2>&1', $output, $exit);
        self::assertSame(0, $exit, "runtime failed:\n" . implode("\n", $output));
        foreach ([
            'MAKE_ALPHA_OK',
            'CLASS_ALPHA_OK',
            'DESCRIBE_ALPHA_OK',
            'INSTANCEOF_ALPHA_HIT_OK',
            'INSTANCEOF_ALPHA_MISS_OK',
            'ISA_ALPHA_OK',
            'CLASS_BETA_OK',
            'DESCRIBE_BETA_OK',
        ] as $marker) {
            self::assertContains($marker, $output, "missing runtime marker {$marker}");
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

    private function specializedFile(string $concreteFqn): string
    {
        $fqn = Registry::generatedFqn('App\\Containers\\Reified', [new TypeRef($concreteFqn)]);
        $prefix = Registry::GENERATED_NAMESPACE_PREFIX . '\\';
        $rel = str_starts_with($fqn, $prefix) ? substr($fqn, strlen($prefix)) : $fqn;
        $path = $this->cacheDir . '/Generated/' . str_replace('\\', '/', $rel) . '.php';
        self::assertFileExists($path, "specialized class for {$concreteFqn} must exist");
        return $path;
    }

    private function phpExport(string $value): string
    {
        return var_export($value, true);
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
