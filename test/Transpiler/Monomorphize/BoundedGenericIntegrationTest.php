<?php

declare(strict_types=1);

namespace XPHP\Transpiler\Monomorphize;

use PhpParser\ParserFactory;
use PhpParser\PrettyPrinter\Standard as StandardPrinter;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use XPHP\FileSystem\FileFinder\NativeFileFinder;
use XPHP\FileSystem\FilepathArray;
use XPHP\FileSystem\FileReader\NativeFileReader;
use XPHP\FileSystem\FileWriter\NativeFileWriter;

final class BoundedGenericIntegrationTest extends TestCase
{
    private string $workDir;
    private string $targetDir;
    private string $cacheDir;

    protected function setUp(): void
    {
        $this->workDir = sys_get_temp_dir() . '/xphp-bounds-' . uniqid('', true);
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

    public function testBoundIsSatisfiedByImplementingClass(): void
    {
        $sourceDir = realpath(__DIR__ . '/../../fixture/compile/bounds_happy/source')
            ?: throw new RuntimeException('Fixture missing');
        $compiler = $this->buildCompiler();
        $sources = (new NativeFileFinder())->find($sourceDir)
            ->filter(static fn (string $f): bool => str_ends_with($f, '.xphp'));

        $result = $compiler->compile($sources, $sourceDir, $this->targetDir, $this->cacheDir);

        $boxFqn = Registry::generatedFqn('App\\Containers\\Box', [new TypeRef('App\\Models\\Tag')]);
        $boxFile = $this->fqnToPath($boxFqn);
        self::assertFileExists($boxFile, 'Box<Tag> must specialize when Tag implements \\Stringable (bound satisfied via hierarchy)');

        $content = file_get_contents($boxFile);
        self::assertStringContainsString('public \\App\\Models\\Tag $item', $content);

        self::assertGreaterThan(0, $result->generatedCount);
    }

    public function testBoundViolationOnScalarConcreteFailsCompilationWithClearMessage(): void
    {
        $sourceDir = $this->workDir . '/src';
        mkdir($sourceDir, 0o755, true);
        $boxFile = $sourceDir . '/Box.xphp';
        file_put_contents($boxFile, <<<'PHP'
        <?php
        namespace App;
        class Box<T: \Stringable>
        {
            public T $item;
        }
        PHP);
        $useFile = $sourceDir . '/Use.xphp';
        file_put_contents($useFile, <<<'PHP'
        <?php
        namespace App;
        $x = new Box<int>();
        PHP);

        $compiler = $this->buildCompiler();
        $sources = new FilepathArray($boxFile, $useFile);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Generic bound violated');
        $this->expectExceptionMessage('int');
        $this->expectExceptionMessage('Stringable');
        $compiler->compile($sources, $sourceDir, $this->targetDir, $this->cacheDir);
    }

    public function testBoundViolationOnUnknownClassFailsCompilation(): void
    {
        $sourceDir = $this->workDir . '/src';
        mkdir($sourceDir, 0o755, true);
        $boxFile = $sourceDir . '/Box.xphp';
        file_put_contents($boxFile, <<<'PHP'
        <?php
        namespace App;
        class Box<T: \Stringable>
        {
            public T $item;
        }
        PHP);
        $useFile = $sourceDir . '/Use.xphp';
        file_put_contents($useFile, <<<'PHP'
        <?php
        namespace App;
        // Unknown\Thing isn't in any source file and isn't a built-in PHP type,
        // so the hierarchy can't prove it satisfies \Stringable.
        $x = new Box<Unknown\Thing>();
        PHP);

        $compiler = $this->buildCompiler();
        $sources = new FilepathArray($boxFile, $useFile);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('not in the source set');
        $compiler->compile($sources, $sourceDir, $this->targetDir, $this->cacheDir);
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
