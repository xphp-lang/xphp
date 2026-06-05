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

        self::assertSame(
            2,
            preg_match_all('/function identity_T_[0-9a-f]+\(/', $content),
            'expected two specialized identity_T_<hash> functions (one per unique call-site arg)',
        );
        self::assertMatchesRegularExpression('/function identity_T_[0-9a-f]+\(int \$x\): int/', $content);
        self::assertMatchesRegularExpression('/function identity_T_[0-9a-f]+\(string \$x\): string/', $content);
        self::assertStringNotContainsString('function identity(', $content, 'original template must be stripped');
    }

    public function testFunctionCallSitesAreRewrittenToMangledFqnNames(): void
    {
        $this->compile();

        $usePath = $this->targetDir . '/Use.php';
        self::assertFileExists($usePath);
        $content = file_get_contents($usePath);

        self::assertSame(2, preg_match_all('/\\\\App\\\\GenericFunction\\\\identity_T_[0-9a-f]+\(/', $content));
        self::assertStringNotContainsString('identity<', $content);
    }

    public function testRuntimeExecutionOfSpecializedFunctions(): void
    {
        $this->compile();

        $funcsPath = $this->targetDir . '/funcs.php';
        $usePath = $this->targetDir . '/Use.php';

        $runScript = $this->workDir . '/run.php';
        file_put_contents($runScript, <<<PHP
        <?php
        declare(strict_types=1);
        require '{$funcsPath}';

        // Pull mangled names out of funcs.php and call directly.
        \$content = file_get_contents('{$funcsPath}');
        preg_match_all('/function (identity_T_[0-9a-f]+)\\((\\w+) \\\$x\\): \\\\2/', \$content, \$m);
        foreach (\$m[1] as \$i => \$mangled) {
            \$type = \$m[2][\$i];
            \$sample = \$type === 'int' ? 42 : 'hi';
            \$fqn = '\\\\App\\\\GenericFunction\\\\' . \$mangled;
            \$out = \$fqn(\$sample);
            \$expected = \$type === 'int' ? 'integer' : 'string';
            echo gettype(\$out) === \$expected ? "OK_{\$type}" : "BAD_{\$type}", "\\n";
        }
        PHP);

        $output = [];
        $exit = 0;
        exec('php ' . escapeshellarg($runScript) . ' 2>&1', $output, $exit);
        self::assertSame(0, $exit, "Run failed:\n" . implode("\n", $output));
        self::assertContains('OK_int', $output);
        self::assertContains('OK_string', $output);
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
            self::assertStringNotContainsString(
                'function identity(',
                $funcsOut,
                'original template must be stripped from the top-level AST too',
            );
            self::assertStringNotContainsString(
                ' T ',
                $funcsOut,
                'no leftover `T` type-param literal in the rewritten output',
            );

            $useOut = file_get_contents($bareTarget . '/Use.php');
            self::assertIsString($useOut);
            self::assertMatchesRegularExpression(
                '/identity_T_[0-9a-f]+\(42\)/',
                $useOut,
                'int call site rewritten to mangled name',
            );
            self::assertMatchesRegularExpression(
                "/identity_T_[0-9a-f]+\\('world'\\)/",
                $useOut,
                'string call site rewritten to mangled name',
            );
            self::assertSame(
                2,
                preg_match_all('/function identity_T_[0-9a-f]+\(/', $useOut),
                'specialized functions appended to top-level AST -- one per unique arg',
            );
            self::assertMatchesRegularExpression(
                '/function identity_T_[0-9a-f]+\(int \$x\): int/',
                $useOut,
            );
            self::assertMatchesRegularExpression(
                '/function identity_T_[0-9a-f]+\(string \$x\): string/',
                $useOut,
            );

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
        $bareDir = sys_get_temp_dir() . '/xphp-bare-multi-' . uniqid('', true);
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

        function nonGenericDouble(int $x): int
        {
            return $x * 2;
        }
        PHP);
        file_put_contents($usePath, <<<'PHP'
        <?php
        declare(strict_types=1);

        $asInt = identity::<int>(42);
        $doubled = nonGenericDouble(21);
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

            // Generic template stripped.
            self::assertStringNotContainsString('function identity(', $funcsOut);
            // Non-generic neighbour preserved — kills the Continue_/Break_ + ArrayOneItem
            // mutations that would silently drop trailing statements.
            self::assertStringContainsString('function nonGenericDouble(int $x): int', $funcsOut);

            // Both call sites resolve at runtime.
            $runScript = $bareDir . '/run.php';
            file_put_contents($runScript, "<?php
declare(strict_types=1);
require '" . $bareTarget . "/funcs.php';
require '" . $bareTarget . "/Use.php';
echo \"doubled={\$doubled};asInt={\$asInt}\";
");
            $output = [];
            $exit = 0;
            exec('php ' . escapeshellarg($runScript) . ' 2>&1', $output, $exit);
            self::assertSame(0, $exit, "Run failed:\n" . implode("\n", $output));
            self::assertContains('doubled=42;asInt=42', $output);
        } finally {
            self::rrmdir($bareDir);
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

            // Namespaced template stripped from its namespace.
            $nsOut = file_get_contents($target . '/namespaced.php');
            self::assertIsString($nsOut);
            self::assertStringNotContainsString('function namespacedId(', $nsOut);

            // Bare top-level template stripped -- this assertion is what kills
            // the Continue_ -> Break_ mutant on the namespaced strip branch.
            $bareOut = file_get_contents($target . '/bare.php');
            self::assertIsString($bareOut);
            self::assertStringNotContainsString('function bareId(', $bareOut);

            // Both call sites rewritten to mangled names.
            $useOut = file_get_contents($target . '/Use.php');
            self::assertIsString($useOut);
            self::assertMatchesRegularExpression('/bareId_T_[0-9a-f]+\(7\)/', $useOut);
            self::assertMatchesRegularExpression('/namespacedId_T_[0-9a-f]+\(13\)/', $useOut);
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
