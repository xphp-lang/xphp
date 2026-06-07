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
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use XPHP\TestSupport\CompiledFixture;
use XPHP\TestSupport\SnapshotHash;

final class GenericFunctionIntegrationTest extends TestCase
{
    private string $sourceDir;
    private string $workDir;
    private string $targetDir;
    private string $cacheDir;

    protected function setUp(): void
    {
        $this->sourceDir = realpath(__DIR__ . '/../../fixture/compile/generic_function/source')
            ?: throw new RuntimeException('Fixture missing');
        $this->workDir = sys_get_temp_dir() . '/xphp-genfn-' . uniqid('', true);
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

    public function testGenericFunctionSpecializesPerUniqueCallSiteArgs(): void
    {
        $this->compile();

        $funcsPath = $this->targetDir . '/funcs.php';
        self::assertFileExists($funcsPath);
        $content = file_get_contents($funcsPath);

        // Structural invariant: exactly two specializations.
        self::assertSame(
            2,
            preg_match_all('/function identity_T_[0-9a-f]+\(/', $content),
        );
        // Negative invariant kept: original template stripped.
        self::assertStringNotContainsString('function identity(', $content);
        SnapshotHash::assertMatches(
            __DIR__ . '/../../fixture/compile/generic_function/verify/testGenericFunctionSpecializesPerUniqueCallSiteArgs/funcs.expected.php',
            $content,
        );
    }

    public function testFunctionCallSitesAreRewrittenToMangledFqnNames(): void
    {
        $this->compile();

        $usePath = $this->targetDir . '/Use.php';
        self::assertFileExists($usePath);
        $content = file_get_contents($usePath);

        // Structural invariant: two distinct call sites get rewritten.
        self::assertSame(2, preg_match_all('/\\\\App\\\\GenericFunction\\\\identity_T_[0-9a-f]+\(/', $content));
        // Negative invariant: turbofish call site must not survive.
        self::assertStringNotContainsString('identity<', $content);
        SnapshotHash::assertMatches(
            __DIR__ . '/../../fixture/compile/generic_function/verify/testFunctionCallSitesAreRewrittenToMangledFqnNames/Use.expected.php',
            $content,
        );
    }

    #[RunInSeparateProcess]
    public function testRuntimeExecutionOfSpecializedFunctions(): void
    {
        $fixture = CompiledFixture::compile($this->sourceDir, 'genfn-runtime');
        try {
            require __DIR__ . '/../../fixture/compile/generic_function/verify/runtime_execution.php';
        } finally {
            $fixture->cleanup();
        }
    }

    public function testFunctionLevelBoundViolationFailsCompilation(): void
    {
        // Locks D2 from the review: `function NAME<T: \Stringable>` bound is now
        // validated at compile time, not silently deferred to a runtime TypeError.
        $sourceDir = sys_get_temp_dir() . '/xphp-genfn-bound-' . uniqid('', true);
        mkdir($sourceDir, 0o755, true);
        $funcsPath = $sourceDir . '/funcs.xphp';
        $usePath = $sourceDir . '/Use.xphp';
        file_put_contents($funcsPath, <<<'PHP'
        <?php
        namespace App;
        function describe<T: \Stringable>(T $x): string { return (string) $x; }
        PHP);
        file_put_contents($usePath, <<<'PHP'
        <?php
        namespace App;
        $out = describe::<int>(42);
        PHP);

        $compiler = $this->buildCompiler();
        $sources = (new NativeFileFinder())->find($sourceDir)
            ->filter(static fn (string $f): bool => str_ends_with($f, '.xphp'));

        try {
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('Generic bound violated');
            $this->expectExceptionMessage('Stringable');
            $compiler->compile($sources, $sourceDir, $this->targetDir, $this->cacheDir);
        } finally {
            unlink($funcsPath);
            unlink($usePath);
            @rmdir($sourceDir);
        }
    }

    public function testDuplicateGenericFunctionDeclarationFailsCompilationWithBothPaths(): void
    {
        // Mirrors Registry::recordDefinition's duplicate-class behavior: silently
        // overwriting the first body would lose work and ship the second declaration
        // unannounced — that's a real refactor footgun.
        $sourceDir = sys_get_temp_dir() . '/xphp-genfn-dup-' . uniqid('', true);
        mkdir($sourceDir, 0o755, true);
        $aPath = $sourceDir . '/a.xphp';
        $bPath = $sourceDir . '/b.xphp';
        file_put_contents($aPath, <<<'PHP'
        <?php
        namespace App;
        function identity<T>(T $x): T { return $x; }
        PHP);
        file_put_contents($bPath, <<<'PHP'
        <?php
        namespace App;
        function identity<T>(T $x): T { return $x; }
        PHP);

        $compiler = $this->buildCompiler();
        $sources = (new NativeFileFinder())->find($sourceDir)
            ->filter(static fn (string $f): bool => str_ends_with($f, '.xphp'));

        try {
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('Generic function template "App\\identity" already declared');
            $compiler->compile($sources, $sourceDir, $this->targetDir, $this->cacheDir);
        } finally {
            unlink($aPath);
            unlink($bPath);
            @rmdir($sourceDir);
        }
    }

    public function testEmittedFilesAreSyntacticallyValid(): void
    {
        $this->compile();

        $files = self::globRecursive($this->targetDir, '*.php');
        self::assertNotEmpty($files);
        foreach ($files as $file) {
            $output = [];
            $exit = 0;
            exec('php -l ' . escapeshellarg($file) . ' 2>&1', $output, $exit);
            self::assertSame(0, $exit, "Syntax error in {$file}:\n" . implode("\n", $output));
        }
    }

    public function testBareTopLevelFreeFunctionSpecializesEndToEnd(): void
    {
        // Free generic functions declared at the bare top level (no enclosing
        // `namespace { }` block) must specialize. A prior silently-drop bug left
        // users with broken output (literal `T` in the rewritten signature).
        $bareDir = sys_get_temp_dir() . '/xphp-bare-' . uniqid('', true);
        mkdir($bareDir, 0o755, true);
        $funcsPath = $bareDir . '/funcs.xphp';
        $usePath = $bareDir . '/Use.xphp';
        file_put_contents($funcsPath, <<<'PHP'
        <?php
        declare(strict_types=1);

        function identity<T>(T $x): T
        {
            return $x;
        }
        PHP);
        file_put_contents($usePath, <<<'PHP'
        <?php
        declare(strict_types=1);

        $asInt = identity::<int>(42);
        $asString = identity::<string>('world');
        PHP);

        $compiler = $this->buildCompiler();
        $sources = (new NativeFileFinder())->find($bareDir)
            ->filter(static fn (string $f): bool => str_ends_with($f, '.xphp'));
        $bareTarget = $bareDir . '/dist';
        $bareCache = $bareDir . '/.xphp-cache';

        try {
            $compiler->compile($sources, $bareDir, $bareTarget, $bareCache);

            $funcsOut = file_get_contents($bareTarget . '/funcs.php');
            self::assertIsString($funcsOut);
            // Negative invariants kept: original template stripped from
            // top-level AST and no leftover `T` type-param literal.
            self::assertStringNotContainsString('function identity(', $funcsOut);
            self::assertStringNotContainsString(' T ', $funcsOut);

            $useOut = file_get_contents($bareTarget . '/Use.php');
            self::assertIsString($useOut);
            // Structural invariant: two specializations appended to top-level AST.
            self::assertSame(
                2,
                preg_match_all('/function identity_T_[0-9a-f]+\(/', $useOut),
            );

            $snapshotDir = __DIR__ . '/GenericFunctionIntegrationTest/testBareTopLevelFreeFunctionSpecializesEndToEnd';
            SnapshotHash::assertMatches($snapshotDir . '/funcs.expected.php', $funcsOut);
            SnapshotHash::assertMatches($snapshotDir . '/Use.expected.php', $useOut);

            // Both rewritten files must be syntactically valid PHP -- the strongest
            // proof that the specialization landed in the right place.
            $output = [];
            $exit = 0;
            exec('php -l ' . escapeshellarg($bareTarget . '/funcs.php') . ' 2>&1', $output, $exit);
            self::assertSame(0, $exit, "funcs.php fails PHP syntax check:\n" . implode("\n", $output));
            $output = [];
            $exit = 0;
            exec('php -l ' . escapeshellarg($bareTarget . '/Use.php') . ' 2>&1', $output, $exit);
            self::assertSame(0, $exit, "Use.php fails PHP syntax check:\n" . implode("\n", $output));
        } finally {
            self::rrmdir($bareDir);
        }
    }

    #[RunInSeparateProcess]
    public function testBareTopLevelStripPreservesAllNonTemplateStatements(): void
    {
        // Locks the contract that `stripTopLevelFunction` only removes the
        // matching generic-template Function_ node and leaves every other
        // statement in the file intact -- including (a) a non-generic function
        // that happens to follow the template and (b) the leading `declare`.
        //
        // Without this test, a Continue_ -> Break_ mutation on the strip loop
        // (or an ArrayOneItem mutation on the return value) would silently
        // drop the trailing statements; this fixture catches both.
        $fixture = CompiledFixture::compile(
            __DIR__ . '/../../fixture/compile/generic_function_bare_top_level/source',
            'genfn-bare-top',
        );
        try {
            $funcsOut = file_get_contents($fixture->targetDir . '/funcs.php');
            self::assertIsString($funcsOut);

            // Negative invariant: generic template stripped.
            self::assertStringNotContainsString('function identity(', $funcsOut);
            SnapshotHash::assertMatches(
                __DIR__ . '/../../fixture/compile/generic_function_bare_top_level/verify/testBareTopLevelStripPreservesAllNonTemplateStatements/funcs.expected.php',
                $funcsOut,
            );

            require __DIR__ . '/../../fixture/compile/generic_function_bare_top_level/verify/runtime.php';
        } finally {
            $fixture->cleanup();
        }
    }

    public function testMixedTopLevelAndNamespacedTemplatesBothGetStripped(): void
    {
        // The strip loop in `process()` has two branches -- one for namespaced
        // Function_ templates (via `stripFunction`) and one for bare top-level
        // ones (via `stripTopLevelFunction`). A Continue_ -> Break_ mutation
        // on the branch separator would skip every template after the first
        // namespaced one.
        //
        // To make the mutation observable, the namespaced template must NOT be
        // the last entry in the strip loop -- otherwise `continue` and `break`
        // both fall through identically. Using FilepathArray directly with
        // explicit order pins the iteration sequence (namespaced first, bare
        // second), so a `break` after the namespaced strip leaves the bare
        // template intact and the test catches it.
        $mixedDir = sys_get_temp_dir() . '/xphp-mixed-' . uniqid('', true);
        mkdir($mixedDir, 0o755, true);
        $namespacedPath = $mixedDir . '/namespaced.xphp';
        $barePath = $mixedDir . '/bare.xphp';
        $usePath = $mixedDir . '/Use.xphp';
        file_put_contents($namespacedPath, <<<'PHP'
        <?php
        declare(strict_types=1);

        namespace App\Mixed;

        function namespacedId<T>(T $x): T
        {
            return $x;
        }
        PHP);
        file_put_contents($barePath, <<<'PHP'
        <?php
        declare(strict_types=1);

        function bareId<T>(T $x): T
        {
            return $x;
        }
        PHP);
        file_put_contents($usePath, <<<'PHP'
        <?php
        declare(strict_types=1);

        $ns = \App\Mixed\namespacedId::<int>(13);
        $bare = bareId::<int>(7);
        PHP);

        $compiler = $this->buildCompiler();
        // Explicit order: namespaced FIRST (so the `continue` after its strip
        // actually has somewhere to continue to), bare SECOND (so a `break`
        // would skip its strip and leave the template behind).
        $sources = new FilepathArray($namespacedPath, $barePath, $usePath);
        $target = $mixedDir . '/dist';
        $cache = $mixedDir . '/.xphp-cache';

        try {
            $compiler->compile($sources, $mixedDir, $target, $cache);

            $nsOut = file_get_contents($target . '/namespaced.php');
            self::assertIsString($nsOut);
            $bareOut = file_get_contents($target . '/bare.php');
            self::assertIsString($bareOut);
            $useOut = file_get_contents($target . '/Use.php');
            self::assertIsString($useOut);

            // Negative invariants kept: both templates stripped (kills the
            // Continue_/Break_ mutant on the strip-loop branch separator).
            self::assertStringNotContainsString('function namespacedId(', $nsOut);
            self::assertStringNotContainsString('function bareId(', $bareOut);

            $snapshotDir = __DIR__ . '/GenericFunctionIntegrationTest/testMixedTopLevelAndNamespacedTemplatesBothGetStripped';
            SnapshotHash::assertMatches($snapshotDir . '/namespaced.expected.php', $nsOut);
            SnapshotHash::assertMatches($snapshotDir . '/bare.expected.php', $bareOut);
            SnapshotHash::assertMatches($snapshotDir . '/Use.expected.php', $useOut);
        } finally {
            self::rrmdir($mixedDir);
        }
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
