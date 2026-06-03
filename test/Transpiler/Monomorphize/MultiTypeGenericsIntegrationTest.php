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

final class MultiTypeGenericsIntegrationTest extends TestCase
{
    private string $sourceDir;
    private string $workDir;
    private string $targetDir;
    private string $cacheDir;

    protected function setUp(): void
    {
        $this->sourceDir = realpath(__DIR__ . '/../../fixture/compile/multi_type/source')
            ?: throw new RuntimeException('Fixture missing');
        $this->workDir = sys_get_temp_dir() . '/xphp-multi-' . uniqid('', true);
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

    public function testDistinctTwoClassParamsGenerateSpecialization(): void
    {
        $this->compile();

        $pairUserPlasticFqn = Registry::generatedFqn(
            'App\\MultiType\\Containers\\Pair',
            [new TypeRef('App\\MultiType\\Models\\User'), new TypeRef('App\\MultiType\\Models\\Plastic')],
        );
        $file = $this->fqnToPath($pairUserPlasticFqn);
        self::assertFileExists($file);

        $content = file_get_contents($file);
        self::assertStringContainsString('public \\App\\MultiType\\Models\\User $first', $content);
        self::assertStringContainsString('public \\App\\MultiType\\Models\\Plastic $second', $content);
    }

    public function testSameClassUsedForBothParamsStillGeneratesOneSpecialization(): void
    {
        $this->compile();

        $pairPlasticPlasticFqn = Registry::generatedFqn(
            'App\\MultiType\\Containers\\Pair',
            [new TypeRef('App\\MultiType\\Models\\Plastic'), new TypeRef('App\\MultiType\\Models\\Plastic')],
        );
        $file = $this->fqnToPath($pairPlasticPlasticFqn);
        self::assertFileExists($file);

        $content = file_get_contents($file);
        self::assertStringContainsString('public \\App\\MultiType\\Models\\Plastic $first', $content);
        self::assertStringContainsString('public \\App\\MultiType\\Models\\Plastic $second', $content);
    }

    public function testMixedScalarAndScalarParams(): void
    {
        $this->compile();

        $mapFqn = Registry::generatedFqn(
            'App\\MultiType\\Containers\\Map',
            [
                new TypeRef('string', isScalar: true),
                new TypeRef('int', isScalar: true),
            ],
        );
        $file = $this->fqnToPath($mapFqn);
        self::assertFileExists($file);

        $content = file_get_contents($file);
        self::assertStringContainsString('public function set(string $key, int $value)', $content);
        self::assertStringContainsString('public function firstKey(): string', $content);
        self::assertStringContainsString('public function firstValue(): int', $content);
    }

    public function testParamOrderMattersForHash(): void
    {
        // Pair<User, Plastic> and Pair<Plastic, User> must be different specializations.
        $a = Registry::generatedFqn('App\\MultiType\\Containers\\Pair', [new TypeRef('App\\MultiType\\Models\\User'), new TypeRef('App\\MultiType\\Models\\Plastic')]);
        $b = Registry::generatedFqn('App\\MultiType\\Containers\\Pair', [new TypeRef('App\\MultiType\\Models\\Plastic'), new TypeRef('App\\MultiType\\Models\\User')]);
        self::assertNotSame($a, $b);

        $this->compile();
        self::assertFileExists($this->fqnToPath($a));
        self::assertFileExists($this->fqnToPath($b));
    }

    public function testSwapReturnTypeIsTransitivelyDiscovered(): void
    {
        // `Pair<A, B>::swap(): Pair<B, A>` — instantiating Pair<User, Plastic> should
        // transitively register Pair<Plastic, User> via the swap() return type.
        $this->compile();

        $registry = json_decode(file_get_contents($this->cacheDir . '/registry.json'), true);
        $fqns = array_column($registry['instantiations'], 'generatedFqn');

        $userPlastic = Registry::generatedFqn('App\\MultiType\\Containers\\Pair', [new TypeRef('App\\MultiType\\Models\\User'), new TypeRef('App\\MultiType\\Models\\Plastic')]);
        $plasticUser = Registry::generatedFqn('App\\MultiType\\Containers\\Pair', [new TypeRef('App\\MultiType\\Models\\Plastic'), new TypeRef('App\\MultiType\\Models\\User')]);

        self::assertContains($userPlastic, $fqns, 'expected the explicit Pair<User, Plastic>');
        self::assertContains($plasticUser, $fqns, 'expected transitive Pair<Plastic, User> from swap() return type');
    }

    public function testDeeplyNestedMultiTypeArgs(): void
    {
        // Pair<Map<string,int>, Pair<Plastic,User>> — both args are themselves multi-type generics.
        $this->compile();

        $mapStringInt = new TypeRef('App\\MultiType\\Containers\\Map', [
            new TypeRef('string', isScalar: true),
            new TypeRef('int', isScalar: true),
        ]);
        $pairPlasticUser = new TypeRef('App\\MultiType\\Containers\\Pair', [
            new TypeRef('App\\MultiType\\Models\\Plastic'),
            new TypeRef('App\\MultiType\\Models\\User'),
        ]);
        $outerFqn = Registry::generatedFqn('App\\MultiType\\Containers\\Pair', [$mapStringInt, $pairPlasticUser]);

        $file = $this->fqnToPath($outerFqn);
        self::assertFileExists($file, "expected deeply-nested specialization at {$file}");

        $content = file_get_contents($file);
        $innerMapFqn = Registry::generatedFqn('App\\MultiType\\Containers\\Map', [
            new TypeRef('string', isScalar: true),
            new TypeRef('int', isScalar: true),
        ]);
        $innerPairFqn = Registry::generatedFqn('App\\MultiType\\Containers\\Pair', [
            new TypeRef('App\\MultiType\\Models\\Plastic'),
            new TypeRef('App\\MultiType\\Models\\User'),
        ]);
        self::assertStringContainsString('public \\' . $innerMapFqn . ' $first', $content);
        self::assertStringContainsString('public \\' . $innerPairFqn . ' $second', $content);
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

    public function testRuntimeTypeErrorOnWrongSlotType(): void
    {
        $this->compile();

        $pairUserPlasticFqn = Registry::generatedFqn(
            'App\\MultiType\\Containers\\Pair',
            [new TypeRef('App\\MultiType\\Models\\User'), new TypeRef('App\\MultiType\\Models\\Plastic')],
        );
        $pairFile = $this->fqnToPath($pairUserPlasticFqn);

        $runScript = $this->workDir . '/run.php';
        file_put_contents($runScript, <<<PHP
        <?php
        declare(strict_types=1);
        require '{$this->targetDir}/Models/User.php';
        require '{$this->targetDir}/Models/Plastic.php';
        require '{$this->targetDir}/Containers/Pair.php';
        require '{$pairFile}';
        \$pair = '{$pairUserPlasticFqn}';

        // Correct order
        try {
            \$ok = new \$pair(new \\App\\MultiType\\Models\\User('alice'), new \\App\\MultiType\\Models\\Plastic('red'));
            echo "OK\\n";
        } catch (\\Throwable \$e) {
            echo "UNEXPECTED:" . \$e->getMessage() . "\\n";
        }

        // Swap arg order — should fail on first slot (User vs Plastic) AND second slot (Plastic vs User)
        try {
            \$bad = new \$pair(new \\App\\MultiType\\Models\\Plastic('red'), new \\App\\MultiType\\Models\\User('alice'));
            echo "MISSED_TYPE_ERROR\\n";
        } catch (\\TypeError \$e) {
            echo "TYPE_ERROR_OK\\n";
        }
        PHP);

        $output = [];
        $exit = 0;
        exec('php ' . escapeshellarg($runScript) . ' 2>&1', $output, $exit);
        self::assertSame(0, $exit, "run.php failed:\n" . implode("\n", $output));
        self::assertSame('OK', $output[0]);
        self::assertSame('TYPE_ERROR_OK', $output[1]);
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
