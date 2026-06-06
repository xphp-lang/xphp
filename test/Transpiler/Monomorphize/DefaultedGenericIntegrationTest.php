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

final class DefaultedGenericIntegrationTest extends TestCase
{
    private string $workDir;
    private string $targetDir;
    private string $cacheDir;

    protected function setUp(): void
    {
        $this->workDir = sys_get_temp_dir() . '/xphp-defaults-' . uniqid('', true);
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

    public function testFullDefaultsFixtureGeneratesExpectedSpecializations(): void
    {
        // Fixture: `defaults_full/`. Exercises all four call-site shapes:
        // bare `new Cache;`, empty turbofish, partial args, fully-explicit.
        // The first two collapse to the same `Cache<string, mixed>` FQN.
        $sourceDir = realpath(__DIR__ . '/../../fixture/compile/defaults_full/source')
            ?: throw new RuntimeException('Fixture missing');
        $compiler = $this->buildCompiler();
        $sources = (new NativeFileFinder())->find($sourceDir)
            ->filter(static fn (string $f): bool => str_ends_with($f, '.xphp'));

        $result = $compiler->compile($sources, $sourceDir, $this->targetDir, $this->cacheDir);

        // Three unique specializations: <string, mixed>, <int, mixed>, <int, Tag>.
        self::assertSame(3, $result->generatedCount);

        $fqnAllDefaults = Registry::generatedFqn(
            'App\\DefaultsFull\\Containers\\Cache',
            [
                new TypeRef('string', isScalar: true),
                new TypeRef('mixed', isScalar: true),
            ],
        );
        $fqnPartial = Registry::generatedFqn(
            'App\\DefaultsFull\\Containers\\Cache',
            [
                new TypeRef('int', isScalar: true),
                new TypeRef('mixed', isScalar: true),
            ],
        );
        $fqnExplicit = Registry::generatedFqn(
            'App\\DefaultsFull\\Containers\\Cache',
            [
                new TypeRef('int', isScalar: true),
                new TypeRef('App\\DefaultsFull\\Models\\Tag'),
            ],
        );
        self::assertFileExists($this->fqnToPath($fqnAllDefaults));
        self::assertFileExists($this->fqnToPath($fqnPartial));
        self::assertFileExists($this->fqnToPath($fqnExplicit));
    }

    public function testEmptyTurbofishAndBareNewProduceSameSpecialization(): void
    {
        // After compile, both `new Cache;` and `new Cache::<>` in the same
        // file resolve to the same generated FQN -- the registry-side padding
        // collapses them. The fixture's source has both shapes; we confirm by
        // counting unique specializations covering the bare/empty/partial/explicit
        // call sites in the previous test.
        $sourceDir = realpath(__DIR__ . '/../../fixture/compile/defaults_full/source')
            ?: throw new RuntimeException('Fixture missing');
        $compiler = $this->buildCompiler();
        $sources = (new NativeFileFinder())->find($sourceDir)
            ->filter(static fn (string $f): bool => str_ends_with($f, '.xphp'));
        $result = $compiler->compile($sources, $sourceDir, $this->targetDir, $this->cacheDir);

        $cacheAllDefaultsCount = 0;
        foreach ($result->registry->instantiations() as $inst) {
            if ($inst->templateFqn === 'App\\DefaultsFull\\Containers\\Cache'
                && $inst->concreteTypes[0]->name === 'string'
                && $inst->concreteTypes[1]->name === 'mixed'
            ) {
                $cacheAllDefaultsCount++;
            }
        }
        // Exactly one entry for the all-defaults shape -- proves bare + empty
        // turbofish collapsed to a single instantiation in the registry.
        self::assertSame(1, $cacheAllDefaultsCount);
    }

    public function testForwardRefDefaultsSubstituteEarlierArg(): void
    {
        // Fixture: `defaults_forward_ref/`. `Pair<A, B = A>` with `new Pair::<int>`
        // pads to `Pair<int, int>`. Explicit `Pair::<int, string>` is independent.
        $sourceDir = realpath(__DIR__ . '/../../fixture/compile/defaults_forward_ref/source')
            ?: throw new RuntimeException('Fixture missing');
        $compiler = $this->buildCompiler();
        $sources = (new NativeFileFinder())->find($sourceDir)
            ->filter(static fn (string $f): bool => str_ends_with($f, '.xphp'));

        $result = $compiler->compile($sources, $sourceDir, $this->targetDir, $this->cacheDir);

        self::assertSame(2, $result->generatedCount);
        $padded = Registry::generatedFqn(
            'App\\DefaultsForwardRef\\Containers\\Pair',
            [
                new TypeRef('int', isScalar: true),
                new TypeRef('int', isScalar: true),
            ],
        );
        $explicit = Registry::generatedFqn(
            'App\\DefaultsForwardRef\\Containers\\Pair',
            [
                new TypeRef('int', isScalar: true),
                new TypeRef('string', isScalar: true),
            ],
        );
        self::assertFileExists($this->fqnToPath($padded));
        self::assertFileExists($this->fqnToPath($explicit));
    }

    public function testCrossFileBareNewResolvesAgainstDefinitionInAnotherFile(): void
    {
        // Fixture: `defaults_cross_file_bare_new/`. `new Cache;` is in Use.xphp;
        // the template lives in Containers/Cache.xphp. The two-pass collector
        // (definitions before instantiations) must resolve Cache regardless of
        // the source file's walk order.
        $sourceDir = realpath(__DIR__ . '/../../fixture/compile/defaults_cross_file_bare_new/source')
            ?: throw new RuntimeException('Fixture missing');
        $compiler = $this->buildCompiler();
        $sources = (new NativeFileFinder())->find($sourceDir)
            ->filter(static fn (string $f): bool => str_ends_with($f, '.xphp'));

        $result = $compiler->compile($sources, $sourceDir, $this->targetDir, $this->cacheDir);

        self::assertSame(1, $result->generatedCount);
        $allDefaults = Registry::generatedFqn(
            'App\\DefaultsCrossFileBareNew\\Containers\\Cache',
            [
                new TypeRef('string', isScalar: true),
                new TypeRef('mixed', isScalar: true),
            ],
        );
        self::assertFileExists($this->fqnToPath($allDefaults));
    }

    public function testDefaultBoundViolationAtDeclarationFailsCompile(): void
    {
        // `class Box<T : Stringable = int>` -- int doesn't satisfy Stringable.
        // Surfaces at the source-level after defs-only collect, BEFORE any
        // instantiation is even considered.
        $sourceDir = $this->workDir . '/src-bound-violation';
        mkdir($sourceDir, 0o755, true);
        $boxFile = $sourceDir . '/Box.xphp';
        file_put_contents($boxFile, <<<'PHP'
        <?php
        namespace App;
        class Box<T : \Stringable = int>
        {
            public T $item;
        }
        PHP);
        $useFile = $sourceDir . '/Use.xphp';
        file_put_contents($useFile, <<<'PHP'
        <?php
        namespace App;
        // No instantiation -- but defs-only validation still runs and rejects.
        PHP);

        $compiler = $this->buildCompiler();
        $sources = new FilepathArray($boxFile, $useFile);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Default for generic parameter `T`');
        $this->expectExceptionMessage('Stringable');
        $compiler->compile($sources, $sourceDir, $this->targetDir, $this->cacheDir);
    }

    public function testMethodLevelBareCallPadsFromDefaults(): void
    {
        // `$m->id(42)` (no turbofish) on a method declared `id<T = string>`.
        // GMC's bare-call padding fires: empty args -> pad to [string] ->
        // mangle to id_T_<string-hash>. The rewritten user file must
        // reference the mangled name.
        $sourceDir = $this->workDir . '/src-bare-method';
        mkdir($sourceDir, 0o755, true);
        $file = $sourceDir . '/M.xphp';
        file_put_contents($file, <<<'PHP'
        <?php
        namespace App;
        class M
        {
            public function id<T = string>(T $x): T { return $x; }
        }
        $m = new M();
        $m->id('hello');
        PHP);

        $compiler = $this->buildCompiler();
        $sources = new FilepathArray($file);
        $compiler->compile($sources, $sourceDir, $this->targetDir, $this->cacheDir);

        $rewritten = file_get_contents($this->targetDir . '/M.php');
        // The bare call gets rewritten to the mangled name.
        self::assertStringContainsString('id_T_', $rewritten);
        // And the original `id` call site no longer appears verbatim
        // (the mangled name replaces it).
        self::assertStringNotContainsString("->id('hello')", $rewritten);
    }

    public function testMethodLevelDefaultDeclarationIsAcceptedAndCompiles(): void
    {
        // Method-level defaults now parse + compile end-to-end. With a
        // turbofish call site we hit the existing specialization path; with
        // a bare call (no `::<...>`), the registry pads from defaults via
        // GenericMethodCompiler's bare-call padding wiring.
        $sourceDir = $this->workDir . '/src-method-default';
        mkdir($sourceDir, 0o755, true);
        $file = $sourceDir . '/M.xphp';
        file_put_contents($file, <<<'PHP'
        <?php
        namespace App;
        class M
        {
            public function id<T = string>(T $x): T { return $x; }
        }
        $m = new M();
        $m->id::<int>(42);
        PHP);

        $compiler = $this->buildCompiler();
        $sources = new FilepathArray($file);

        // Must not throw -- compile cleanly. Method-level specializations
        // get appended to the owning class rather than producing a new class
        // file (so generatedCount stays 0 here); the proof of success is
        // that compile finishes and the rewritten user file references the
        // mangled method name.
        $compiler->compile($sources, $sourceDir, $this->targetDir, $this->cacheDir);
        $rewritten = file_get_contents($this->targetDir . '/M.php');
        self::assertStringContainsString('id_T_', $rewritten);
    }

    public function testTooFewArgsWithoutDefaultsFailsCompile(): void
    {
        // `class Pair<A, B>` and `new Pair::<int>` -- B has no default, so
        // padding throws "parameter has no default".
        $sourceDir = $this->workDir . '/src-too-few';
        mkdir($sourceDir, 0o755, true);
        $pairFile = $sourceDir . '/Pair.xphp';
        file_put_contents($pairFile, <<<'PHP'
        <?php
        namespace App;
        class Pair<A, B>
        {
            public function __construct(public A $a, public B $b) {}
        }
        PHP);
        $useFile = $sourceDir . '/Use.xphp';
        file_put_contents($useFile, <<<'PHP'
        <?php
        namespace App;
        $p = new Pair::<int>(1, 'x');
        PHP);

        $compiler = $this->buildCompiler();
        $sources = new FilepathArray($pairFile, $useFile);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('has no default');
        $compiler->compile($sources, $sourceDir, $this->targetDir, $this->cacheDir);
    }

    public function testPositionalFirstBoundFailureSurfacesOnEarliestViolator(): void
    {
        // `class Box<A : Stringable, B : Countable = A>; new Box::<int>` --
        // padding fills B = A = int. Then validateBounds iterates positionally:
        // A's bound (Stringable) fails on int FIRST, so the error message names
        // A and Stringable -- not B and Countable.
        $sourceDir = $this->workDir . '/src-positional';
        mkdir($sourceDir, 0o755, true);
        $boxFile = $sourceDir . '/Box.xphp';
        file_put_contents($boxFile, <<<'PHP'
        <?php
        namespace App;
        class Box<A : \Stringable, B : \Countable = A>
        {
            public function __construct(public A $a, public B $b) {}
        }
        PHP);
        $useFile = $sourceDir . '/Use.xphp';
        file_put_contents($useFile, <<<'PHP'
        <?php
        namespace App;
        $b = new Box::<int>(7, 7);
        PHP);

        $compiler = $this->buildCompiler();
        $sources = new FilepathArray($boxFile, $useFile);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('type parameter A');
        $this->expectExceptionMessage('Stringable');
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
