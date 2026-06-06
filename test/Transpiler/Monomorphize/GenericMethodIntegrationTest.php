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

final class GenericMethodIntegrationTest extends TestCase
{
    private string $sourceDir;
    private string $workDir;
    private string $targetDir;
    private string $cacheDir;

    protected function setUp(): void
    {
        $this->sourceDir = realpath(__DIR__ . '/../../fixture/compile/generic_method/source')
            ?: throw new RuntimeException('Fixture missing');
        $this->workDir = sys_get_temp_dir() . '/xphp-genmethod-' . uniqid('', true);
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

    public function testGenericMethodSpecializesPerUniqueCallSiteArgs(): void
    {
        $this->compile();

        $utilPath = $this->targetDir . '/Util.php';
        self::assertFileExists($utilPath);
        $content = file_get_contents($utilPath);

        // Two unique args (int, string) -> two mangled methods.
        self::assertSame(2, preg_match_all('/function identity_T_[0-9a-f]+\\(/', $content), 'expected two mangled identity_T_<hash> methods (one per unique call-site arg)');
        // Each specialized method takes its concrete type.
        self::assertStringContainsString('public static function identity_T_', $content);
        self::assertMatchesRegularExpression('/public static function identity_T_[0-9a-f]+\\(int \\$x\\): int/', $content);
        self::assertMatchesRegularExpression('/public static function identity_T_[0-9a-f]+\\(string \\$x\\): string/', $content);

        // Original generic-method template must be removed from the emitted class.
        self::assertStringNotContainsString('function identity(', $content);
        self::assertStringNotContainsString('function identity ', $content);
    }

    public function testCallSitesAreRewrittenToMangledNames(): void
    {
        $this->compile();

        $usePath = $this->targetDir . '/Use.php';
        self::assertFileExists($usePath);
        $content = file_get_contents($usePath);

        // Three call sites (2 int + 1 string), all rewritten to fully-qualified mangled refs.
        self::assertSame(3, preg_match_all('/\\\\App\\\\GenericMethod\\\\Util::identity_T_[0-9a-f]+\\(/', $content));
        // The two int call sites must share the same mangled name (single specialization
        // per unique arg list, not per call site — locks the alreadyGenerated dedupe).
        \preg_match_all('/identity_T_([0-9a-f]+)/', $content, $matches);
        self::assertCount(3, $matches[1]);
        self::assertSame($matches[1][0], $matches[1][2], 'both `identity<int>` call sites must share a mangled name');
        self::assertNotSame($matches[1][0], $matches[1][1], 'int and string mangles must differ');
        // No raw `identity<int>` left over (the generic-args clause must be stripped or rewritten).
        self::assertStringNotContainsString('identity<', $content);
        // No bare `identity(` either — every call should be mangled.
        self::assertStringNotContainsString('::identity(', $content);
    }

    public function testRuntimeExecutionPreservesGenericMethodSemantics(): void
    {
        $this->compile();

        $utilPath = $this->targetDir . '/Util.php';
        $usePath = $this->targetDir . '/Use.php';

        $runScript = $this->workDir . '/run.php';
        file_put_contents($runScript, <<<PHP
        <?php
        declare(strict_types=1);
        require '{$utilPath}';

        \$asInt = \\App\\GenericMethod\\Util::identity_T_FILLED_AT_RUNTIME(42);
        PHP);

        // Pull the mangled FQNs out of Util.php and call them directly. We assert each
        // one round-trips its arg through the matching native type.
        \preg_match_all('/function (identity_T_[0-9a-f]+)\((\w+) \$x\): \2/', file_get_contents($utilPath), $matches);
        self::assertCount(2, $matches[1], 'expected to find two mangled methods');
        $gettypeName = ['int' => 'integer', 'string' => 'string'];
        $script = "<?php\ndeclare(strict_types=1);\nrequire " . var_export($utilPath, true) . ";\n";
        foreach ($matches[1] as $i => $mangled) {
            $type = $matches[2][$i];
            $sample = $type === 'int' ? '42' : "'hello'";
            $expected = $gettypeName[$type];
            $script .= "\$out{$i} = \\App\\GenericMethod\\Util::{$mangled}({$sample});\n";
            $script .= "echo gettype(\$out{$i}) === '{$expected}' ? 'OK_{$type}' : 'BAD_{$type}', \"\\n\";\n";
        }
        file_put_contents($runScript, $script);

        $output = [];
        $exit = 0;
        exec('php ' . escapeshellarg($runScript) . ' 2>&1', $output, $exit);
        self::assertSame(0, $exit, "Run failed:\n" . implode("\n", $output));
        self::assertContains('OK_int', $output);
        self::assertContains('OK_string', $output);
    }

    public function testMethodLevelBoundViolationFailsCompilation(): void
    {
        // D2 from the review: `Util::identity<T: \Stringable>` called with `<int>` is
        // now caught at compile time, not silently passed through to a runtime TypeError.
        $sourceDir = sys_get_temp_dir() . '/xphp-genmethod-bound-' . uniqid('', true);
        mkdir($sourceDir, 0o755, true);
        $utilPath = $sourceDir . '/Util.xphp';
        $usePath = $sourceDir . '/Use.xphp';
        file_put_contents($utilPath, <<<'PHP'
        <?php
        declare(strict_types=1);
        namespace App;
        class Util {
            public static function describe<T: \Stringable>(T $x): string { return (string) $x; }
        }
        PHP);
        file_put_contents($usePath, <<<'PHP'
        <?php
        declare(strict_types=1);
        namespace App;
        $out = Util::describe::<int>(42);
        PHP);

        $compiler = $this->buildCompiler();
        $sources = (new NativeFileFinder())->find($sourceDir)
            ->filter(static fn (string $f): bool => str_ends_with($f, '.xphp'));
        $targetDir = sys_get_temp_dir() . '/xphp-mb-out-' . uniqid('', true) . '/dist';
        $cacheDir = dirname($targetDir) . '/.xphp-cache';
        mkdir(dirname($targetDir), 0o755, true);

        try {
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('Generic bound violated');
            $this->expectExceptionMessage('Stringable');
            $compiler->compile($sources, $sourceDir, $targetDir, $cacheDir);
        } finally {
            unlink($utilPath);
            unlink($usePath);
            @rmdir($sourceDir);
            if (is_dir(dirname($targetDir))) {
                self::rrmdir(dirname($targetDir));
            }
        }
    }

    public function testMultilineStaticCallSiteIsStillRewrittenToMangledName(): void
    {
        // Regression for F2: nikic's StaticCall::getStartLine() returns the receiver's
        // line (`Foo`), but the scanner used to record the marker against the identifier
        // line (`identity`). Splitting them across lines desyncs the two and the
        // marker never attaches. The line-range fix anchors the marker to the receiver.
        $sourceDir = sys_get_temp_dir() . '/xphp-multiline-' . uniqid('', true);
        mkdir($sourceDir, 0o755, true);
        $utilPath = $sourceDir . '/Util.xphp';
        $usePath = $sourceDir . '/Use.xphp';
        file_put_contents($utilPath, <<<'PHP'
        <?php
        declare(strict_types=1);
        namespace App;
        class Util {
            public static function identity<T>(T $x): T { return $x; }
        }
        PHP);
        file_put_contents($usePath, <<<'PHP'
        <?php
        declare(strict_types=1);
        namespace App;
        $asInt = Util::
            identity::<int>(42);
        PHP);

        $compiler = $this->buildCompiler();
        $sources = (new NativeFileFinder())->find($sourceDir)
            ->filter(static fn (string $f): bool => str_ends_with($f, '.xphp'));
        $targetDir = sys_get_temp_dir() . '/xphp-multiline-out-' . uniqid('', true) . '/dist';
        $cacheDir = dirname($targetDir) . '/.xphp-cache';
        mkdir(dirname($targetDir), 0o755, true);

        try {
            $compiler->compile($sources, $sourceDir, $targetDir, $cacheDir);
            $useContent = file_get_contents($targetDir . '/Use.php');
            self::assertMatchesRegularExpression(
                '/Util::identity_T_[0-9a-f]+\(42\)/',
                $useContent,
                'multi-line `Util::\n    identity<int>` must still rewrite to the mangled name',
            );
        } finally {
            unlink($utilPath);
            unlink($usePath);
            @rmdir($sourceDir);
            if (is_dir(dirname($targetDir))) {
                self::rrmdir(dirname($targetDir));
            }
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

    public function testSelfWithTypeArgsCompilesEndToEnd(): void
    {
        // Regression: an earlier change shipped only the scanner half --
        // `self<T>` was stripped from the source but the resolver then attached
        // ATTR_GENERIC_ARGS to the bare `self` Name, making the Registry try to
        // specialize a non-existent `App\…\self` template ("Generic template …
        // was instantiated but never defined"). This test compiles a fixture
        // that uses `self<T>` in a return position and asserts the full
        // pipeline (compile + runtime exec).
        $dir = sys_get_temp_dir() . '/xphp-self-' . uniqid('', true);
        mkdir($dir, 0o755, true);
        file_put_contents($dir . '/Container.xphp', <<<'PHP'
        <?php
        declare(strict_types=1);
        namespace App\SelfReturn;
        class Container<T> {
            public function __construct(public T $item) {}
            public function withItem(T $n): self<T>
            {
                $this->item = $n;
                return $this;
            }
        }
        PHP);
        file_put_contents($dir . '/Use.xphp', <<<'PHP'
        <?php
        declare(strict_types=1);
        namespace App\SelfReturn;

        $a = new Container::<int>(1);
        $b = $a->withItem(2);
        PHP);

        $compiler = $this->buildCompiler();
        $sources = (new NativeFileFinder())->find($dir)
            ->filter(static fn (string $f): bool => str_ends_with($f, '.xphp'));
        $target = $dir . '/dist';
        $cache = $dir . '/.xphp-cache';

        try {
            $compiler->compile($sources, $dir, $target, $cache);

            // The specialized Container class lives under cache/Generated/...
            $generated = self::globRecursive($cache . '/Generated', '*.php');
            self::assertCount(1, $generated, 'one specialization (Container<int>)');
            $specialized = file_get_contents($generated[0]);
            self::assertIsString($specialized);

            // self<T> in the source must become bare `self` in the
            // specialized class -- `self` here resolves at runtime to the
            // specialized class itself, which IS the correct semantics.
            self::assertMatchesRegularExpression(
                '/public function withItem\(int \$n\): self\b/',
                $specialized,
                'self<T> must lower to bare `self` in the specialization',
            );
            self::assertStringNotContainsString(
                '\\App\\SelfReturn\\self',
                $specialized,
                'self must NOT be misresolved to a class FQN',
            );

            // Runtime sanity: instantiate, call withItem, read item back.
            $runScript = $dir . '/run.php';
            file_put_contents($runScript, <<<PHP
            <?php
            declare(strict_types=1);
            spl_autoload_register(function (\$class) {
                if (str_starts_with(\$class, 'XPHP\\\\Generated\\\\')) {
                    \$rel = substr(\$class, strlen('XPHP\\\\Generated\\\\'));
                    \$file = '{$cache}/Generated/' . str_replace('\\\\', '/', \$rel) . '.php';
                    if (file_exists(\$file)) require \$file;
                }
            });
            require '{$target}/Container.php';
            require '{$target}/Use.php';
            echo "item={\$b->item}";
            PHP);
            $output = [];
            $exit = 0;
            exec('php ' . escapeshellarg($runScript) . ' 2>&1', $output, $exit);
            self::assertSame(0, $exit, "Run failed:\n" . implode("\n", $output));
            self::assertContains('item=2', $output);
        } finally {
            self::rrmdir($dir);
        }
    }

    public function testInstanceMethodGenericThisReceiverSpecializes(): void
    {
        // Phase 2 Stage A1: `$this->method::<T>(...)` -- the most common shape.
        // Receiver type is the enclosing class, no flow analysis needed.
        $dir = sys_get_temp_dir() . '/xphp-inst-this-' . uniqid('', true);
        mkdir($dir, 0o755, true);
        file_put_contents($dir . '/Util.xphp', <<<'PHP'
        <?php
        declare(strict_types=1);
        namespace App\InstThis;

        class Util {
            public function identity<T>(T $x): T { return $x; }
            public function callIntIdentity(): int
            {
                return $this->identity::<int>(42);
            }
            public function callStringIdentity(): string
            {
                return $this->identity::<string>('hi');
            }
        }
        PHP);
        file_put_contents($dir . '/Use.xphp', <<<'PHP'
        <?php
        declare(strict_types=1);
        namespace App\InstThis;

        $u = new Util();
        $i = $u->callIntIdentity();
        $s = $u->callStringIdentity();
        PHP);

        try {
            $this->compileFrom($dir);
            $util = file_get_contents($dir . '/dist/Util.php');
            self::assertIsString($util);
            // Both turbofish call sites rewritten to mangled identifiers.
            self::assertMatchesRegularExpression(
                '/\$this->identity_T_[0-9a-f]+\(42\)/',
                $util,
                '$this->identity::<int> rewritten to mangled name',
            );
            self::assertMatchesRegularExpression(
                "/\\\$this->identity_T_[0-9a-f]+\\('hi'\\)/",
                $util,
            );
            // Two specialized methods appended to Util.
            self::assertSame(
                2,
                preg_match_all('/public function identity_T_[0-9a-f]+\(/', $util),
            );
            self::assertStringNotContainsString('function identity(', $util);

            // Runtime sanity: the rewritten class actually executes.
            $runScript = $dir . '/run.php';
            file_put_contents($runScript, <<<PHP
            <?php
            declare(strict_types=1);
            require '{$dir}/dist/Util.php';
            require '{$dir}/dist/Use.php';
            echo "i={\$i};s={\$s}";
            PHP);
            $output = [];
            $exit = 0;
            exec('php ' . escapeshellarg($runScript) . ' 2>&1', $output, $exit);
            self::assertSame(0, $exit, "Run failed:\n" . implode("\n", $output));
            self::assertContains('i=42;s=hi', $output);
        } finally {
            self::rrmdir($dir);
        }
    }

    public function testInstanceMethodGenericParamReceiverSpecializes(): void
    {
        // Phase 2 Stage A2: receiver is a parameter with a typed declaration.
        // `function go(Util $u) { $u->identity::<int>(7); }` resolves $u to Util
        // via the parameter type, no flow analysis needed.
        $dir = sys_get_temp_dir() . '/xphp-inst-param-' . uniqid('', true);
        mkdir($dir, 0o755, true);
        file_put_contents($dir . '/Util.xphp', <<<'PHP'
        <?php
        declare(strict_types=1);
        namespace App\InstParam;
        class Util {
            public function identity<T>(T $x): T { return $x; }
        }
        PHP);
        file_put_contents($dir . '/Caller.xphp', <<<'PHP'
        <?php
        declare(strict_types=1);
        namespace App\InstParam;
        class Caller {
            public function viaParam(Util $u): int
            {
                return $u->identity::<int>(7);
            }
            public function viaNullableParam(?Util $u): ?int
            {
                return $u?->identity::<int>(11);
            }
        }
        PHP);

        try {
            $this->compileFrom($dir);
            $caller = file_get_contents($dir . '/dist/Caller.php');
            self::assertIsString($caller);
            self::assertMatchesRegularExpression(
                '/\$u->identity_T_[0-9a-f]+\(7\)/',
                $caller,
                'parameter receiver: $u resolved via param type',
            );
            self::assertMatchesRegularExpression(
                '/\$u\?->identity_T_[0-9a-f]+\(11\)/',
                $caller,
                'nullable parameter receiver: nullable wrapper stripped before type lookup',
            );

            $util = file_get_contents($dir . '/dist/Util.php');
            self::assertIsString($util);
            self::assertMatchesRegularExpression('/public function identity_T_[0-9a-f]+\(int \$x\): int/', $util);
        } finally {
            self::rrmdir($dir);
        }
    }

    public function testInstanceMethodGenericLocalVariableReceiverSpecializes(): void
    {
        // Phase 2 Stage B: local flow typing. `$u = new Util(); $u->m::<T>(...)`
        // -- the visitor records `$u`'s type from the assignment so the later
        // method call can specialize. Lexical last-write wins; we don't model
        // branches or method-return-typed reassignments.
        $dir = sys_get_temp_dir() . '/xphp-inst-local-' . uniqid('', true);
        mkdir($dir, 0o755, true);
        file_put_contents($dir . '/Util.xphp', <<<'PHP'
        <?php
        declare(strict_types=1);
        namespace App\InstLocal;
        class Util {
            public function identity<T>(T $x): T { return $x; }
        }
        PHP);
        file_put_contents($dir . '/Use.xphp', <<<'PHP'
        <?php
        declare(strict_types=1);
        namespace App\InstLocal;

        $u = new Util();
        $i = $u->identity::<int>(99);
        $s = $u->identity::<string>('world');
        PHP);

        try {
            $this->compileFrom($dir);
            $use = file_get_contents($dir . '/dist/Use.php');
            self::assertIsString($use);
            self::assertMatchesRegularExpression(
                '/\$u->identity_T_[0-9a-f]+\(99\)/',
                $use,
                'local var: $u flow-typed from `new Util()`',
            );
            self::assertMatchesRegularExpression(
                "/\\\$u->identity_T_[0-9a-f]+\\('world'\\)/",
                $use,
            );

            // Runtime sanity check.
            $runScript = $dir . '/run.php';
            file_put_contents($runScript, <<<PHP
            <?php
            declare(strict_types=1);
            require '{$dir}/dist/Util.php';
            require '{$dir}/dist/Use.php';
            echo "i={\$i};s={\$s}";
            PHP);
            $output = [];
            $exit = 0;
            exec('php ' . escapeshellarg($runScript) . ' 2>&1', $output, $exit);
            self::assertSame(0, $exit, "Run failed:\n" . implode("\n", $output));
            self::assertContains('i=99;s=world', $output);
        } finally {
            self::rrmdir($dir);
        }
    }

    public function testInstanceMethodGenericPropertyReceiverSpecializes(): void
    {
        // Bonus: `$this->prop->method::<T>(...)` where prop is a typed property.
        $dir = sys_get_temp_dir() . '/xphp-inst-prop-' . uniqid('', true);
        mkdir($dir, 0o755, true);
        file_put_contents($dir . '/Util.xphp', <<<'PHP'
        <?php
        declare(strict_types=1);
        namespace App\InstProp;
        class Util {
            public function identity<T>(T $x): T { return $x; }
        }
        PHP);
        file_put_contents($dir . '/Owner.xphp', <<<'PHP'
        <?php
        declare(strict_types=1);
        namespace App\InstProp;
        class Owner {
            public Util $util;
            public function __construct()
            {
                $this->util = new Util();
            }
            public function go(): int
            {
                return $this->util->identity::<int>(123);
            }
        }
        PHP);

        try {
            $this->compileFrom($dir);
            $owner = file_get_contents($dir . '/dist/Owner.php');
            self::assertIsString($owner);
            self::assertMatchesRegularExpression(
                '/\$this->util->identity_T_[0-9a-f]+\(123\)/',
                $owner,
                'property receiver: $this->util resolved via property type declaration',
            );
        } finally {
            self::rrmdir($dir);
        }
    }

    public function testReceiverTypeAnalysisDoesNotLeakAcrossClosureScopes(): void
    {
        // Regression for the review of b88539c (Issue B): receiver-type analysis
        // shared `$currentScopeLocalTypes` across closure boundaries, so an inner
        // `$x = new Bar()` overwrote the outer scope's `$x = new Foo()` slot.
        // The outer call after the closure returned then picked Bar's mangled
        // method (often a method that didn't exist on Foo) and Foo never got
        // its specialization generated.
        //
        // Fix: snapshot/restore $currentScopeParamTypes + $currentScopeLocalTypes
        // on Closure (and ArrowFunction) enter/leave the same way Function_ and
        // ClassMethod already did. Closure body gets a fresh scope; outer scope
        // is restored on leave.
        $dir = sys_get_temp_dir() . '/xphp-leak-' . uniqid('', true);
        mkdir($dir, 0o755, true);
        file_put_contents($dir . '/Foo.xphp', <<<'PHP'
        <?php
        declare(strict_types=1);
        namespace App\ClosureLeak;
        class Foo {
            public function fooId<T>(T $x): T { return $x; }
        }
        PHP);
        file_put_contents($dir . '/Bar.xphp', <<<'PHP'
        <?php
        declare(strict_types=1);
        namespace App\ClosureLeak;
        class Bar {
            public function barId<T>(T $x): T { return $x; }
        }
        PHP);
        file_put_contents($dir . '/Use.xphp', <<<'PHP'
        <?php
        declare(strict_types=1);
        namespace App\ClosureLeak;

        $x = new Foo();
        $cb = function (): void {
            $x = new Bar();
            $inner = $x->barId::<int>(11);
        };
        $cb();
        $outer = $x->fooId::<int>(22);
        PHP);

        try {
            $this->compileFrom($dir);

            // Foo must receive its `fooId_T_<hash>` specialization, NOT silently
            // get nothing (which was the original failure mode).
            $foo = file_get_contents($dir . '/dist/Foo.php');
            self::assertIsString($foo);
            self::assertMatchesRegularExpression(
                '/public function fooId_T_[0-9a-f]+\(int \$x\): int/',
                $foo,
                'Foo must receive its specialized method -- the outer-scope receiver',
            );

            // Bar still gets its inner-scope specialization.
            $bar = file_get_contents($dir . '/dist/Bar.php');
            self::assertIsString($bar);
            self::assertMatchesRegularExpression(
                '/public function barId_T_[0-9a-f]+\(int \$x\): int/',
                $bar,
            );

            // Call sites: inner uses Bar's mangled name, outer uses Foo's.
            $use = file_get_contents($dir . '/dist/Use.php');
            self::assertIsString($use);
            self::assertMatchesRegularExpression(
                '/\$x->barId_T_[0-9a-f]+\(11\)/',
                $use,
                'inner closure call uses Bar barId mangled name',
            );
            self::assertMatchesRegularExpression(
                '/\$x->fooId_T_[0-9a-f]+\(22\)/',
                $use,
                'outer call uses Foo fooId mangled name -- proves the scope was restored',
            );
        } finally {
            self::rrmdir($dir);
        }
    }

    public function testReceiverTypeAnalysisDoesNotLeakAcrossArrowFunction(): void
    {
        // Arrow functions can't reassign outer variables in PHP semantics (a
        // single-expression body has nowhere to assign), but the snapshot /
        // restore on `ArrowFunction` enter/leave is symmetric with Closure
        // for invariant safety. This test pins the arrow-function shape so a
        // future refactor that loses the symmetry can't quietly regress.
        $dir = sys_get_temp_dir() . '/xphp-arrow-leak-' . uniqid('', true);
        mkdir($dir, 0o755, true);
        file_put_contents($dir . '/Foo.xphp', <<<'PHP'
        <?php
        declare(strict_types=1);
        namespace App\ArrowLeak;
        class Foo {
            public function fooId<T>(T $x): T { return $x; }
        }
        PHP);
        file_put_contents($dir . '/Use.xphp', <<<'PHP'
        <?php
        declare(strict_types=1);
        namespace App\ArrowLeak;

        $x = new Foo();
        // Arrow function with its own typed parameter `$x`. After the arrow
        // body finishes evaluating, the outer `$x` must still be Foo.
        $double = fn(int $x): int => $x * 2;
        $r = $double(21);
        $outer = $x->fooId::<int>(7);
        PHP);

        try {
            $this->compileFrom($dir);

            $use = file_get_contents($dir . '/dist/Use.php');
            self::assertIsString($use);
            self::assertMatchesRegularExpression(
                '/\$x->fooId_T_[0-9a-f]+\(7\)/',
                $use,
                'outer Foo call survives the arrow function body',
            );

            $foo = file_get_contents($dir . '/dist/Foo.php');
            self::assertIsString($foo);
            self::assertMatchesRegularExpression(
                '/public function fooId_T_[0-9a-f]+\(int \$x\): int/',
                $foo,
            );
        } finally {
            self::rrmdir($dir);
        }
    }

    public function testBranchingReassignmentInvalidatesPostBranchSpecialization(): void
    {
        // Bug fix: `$x = new Foo(); if (…) { $x = new Bar(); } $x->m::<T>()`
        // used to specialize against Bar (the last lexical write) regardless
        // of whether the branch fired. The conservative fix invalidates `$x`
        // on the branch's exit -- the post-branch call site no longer
        // specializes, and PHP throws "undefined method" at runtime instead
        // of silently calling the wrong specialization.
        $dir = sys_get_temp_dir() . '/xphp-br-post-' . uniqid('', true);
        mkdir($dir, 0o755, true);
        file_put_contents($dir . '/Foo.xphp', <<<'PHP'
        <?php
        declare(strict_types=1);
        namespace App\BrPost;
        class Foo { public function fooId<T>(T $x): T { return $x; } }
        PHP);
        file_put_contents($dir . '/Bar.xphp', <<<'PHP'
        <?php
        declare(strict_types=1);
        namespace App\BrPost;
        class Bar { public function barId<T>(T $x): T { return $x; } }
        PHP);
        file_put_contents($dir . '/Use.xphp', <<<'PHP'
        <?php
        declare(strict_types=1);
        namespace App\BrPost;

        $x = new Foo();
        if (mt_rand(0, 1)) {
            $x = new Bar();
        }
        $r = $x->fooId::<int>(7);
        PHP);

        try {
            $this->compileFrom($dir);
            $use = file_get_contents($dir . '/dist/Use.php');
            self::assertIsString($use);
            // Post-branch call must NOT be specialized -- the receiver type is
            // ambiguous after the conditional reassignment.
            self::assertStringNotContainsString(
                'fooId_T_',
                $use,
                'post-branch call must not specialize when receiver was conditionally reassigned',
            );
            // The bare unmangled name should survive into the cleaned output.
            self::assertStringContainsString('$x->fooId(7)', $use);
        } finally {
            self::rrmdir($dir);
        }
    }

    public function testBranchingIntraBranchSpecializationStillWorks(): void
    {
        // Conservative branching analysis must NOT lose the intra-branch
        // specialization -- within the if-body we know exactly what `$x` is,
        // so calls there are still resolvable.
        $dir = sys_get_temp_dir() . '/xphp-br-intra-' . uniqid('', true);
        mkdir($dir, 0o755, true);
        file_put_contents($dir . '/Foo.xphp', <<<'PHP'
        <?php
        declare(strict_types=1);
        namespace App\BrIntra;
        class Foo { public function fooId<T>(T $x): T { return $x; } }
        PHP);
        file_put_contents($dir . '/Use.xphp', <<<'PHP'
        <?php
        declare(strict_types=1);
        namespace App\BrIntra;

        if (mt_rand(0, 1)) {
            $x = new Foo();
            $r = $x->fooId::<int>(1);
        }
        PHP);

        try {
            $this->compileFrom($dir);
            $use = file_get_contents($dir . '/dist/Use.php');
            self::assertIsString($use);
            self::assertMatchesRegularExpression(
                '/\$x->fooId_T_[0-9a-f]+\(1\)/',
                $use,
                'intra-branch call site must specialize -- the branch knows the type',
            );
        } finally {
            self::rrmdir($dir);
        }
    }

    public function testBranchingElseBranchSeesPreBranchState(): void
    {
        // Bug fix: with the sibling-branch reset, the else-body now sees the
        // pre-if state of every variable, NOT the if-body's mutations. So
        // `$y = Foo; if (…) { $y = Bar; } else { $y->fooId::<T>(); }` specializes
        // the else call against Foo, not against Bar.
        $dir = sys_get_temp_dir() . '/xphp-br-else-' . uniqid('', true);
        mkdir($dir, 0o755, true);
        file_put_contents($dir . '/Foo.xphp', <<<'PHP'
        <?php
        declare(strict_types=1);
        namespace App\BrElse;
        class Foo { public function fooId<T>(T $x): T { return $x; } }
        PHP);
        file_put_contents($dir . '/Bar.xphp', <<<'PHP'
        <?php
        declare(strict_types=1);
        namespace App\BrElse;
        class Bar { }
        PHP);
        file_put_contents($dir . '/Use.xphp', <<<'PHP'
        <?php
        declare(strict_types=1);
        namespace App\BrElse;

        $y = new Foo();
        if (mt_rand(0, 1)) {
            $y = new Bar();
        } else {
            $r = $y->fooId::<int>(2);
        }
        PHP);

        try {
            $this->compileFrom($dir);
            $use = file_get_contents($dir . '/dist/Use.php');
            self::assertIsString($use);
            self::assertMatchesRegularExpression(
                '/\$y->fooId_T_[0-9a-f]+\(2\)/',
                $use,
                'else branch must see the pre-if state of $y (Foo, not Bar)',
            );
        } finally {
            self::rrmdir($dir);
        }
    }

    public function testBranchingSameClassMergeKeepsSpecialization(): void
    {
        // P5.1: if every reachable arm assigns $x to the same class, post-
        // branch $x keeps that class and the call site specializes.
        $dir = sys_get_temp_dir() . '/xphp-br-merge-' . uniqid('', true);
        mkdir($dir, 0o755, true);
        file_put_contents($dir . '/Foo.xphp', <<<'PHP'
        <?php
        declare(strict_types=1);
        namespace App\BrMerge;
        class Foo { public function fooId<T>(T $x): T { return $x; } }
        PHP);
        file_put_contents($dir . '/Use.xphp', <<<'PHP'
        <?php
        declare(strict_types=1);
        namespace App\BrMerge;

        if (mt_rand(0, 1)) {
            $x = new Foo();
        } else {
            $x = new Foo();
        }
        $r = $x->fooId::<int>(11);
        PHP);

        try {
            $this->compileFrom($dir);
            $use = file_get_contents($dir . '/dist/Use.php');
            self::assertIsString($use);
            self::assertMatchesRegularExpression(
                '/\$x->fooId_T_[0-9a-f]+\(11\)/',
                $use,
                'all-siblings-agree merge must keep $x = Foo post-branch',
            );
        } finally {
            self::rrmdir($dir);
        }
    }

    public function testBranchingIfWithoutElseStillDeSpecializes(): void
    {
        // P5.1: if-without-else has an implicit empty arm. Even when both
        // reachable paths agree on Foo (the pre-branch assignment matches
        // the if-body's), the merge MUST de-specialize because the
        // expectedArmCount guard trips. This is deliberate -- the implicit
        // arm doesn't appear in perBranchTypes, and special-casing it
        // would be fragile against refactors.
        $dir = sys_get_temp_dir() . '/xphp-br-noelse-' . uniqid('', true);
        mkdir($dir, 0o755, true);
        file_put_contents($dir . '/Foo.xphp', <<<'PHP'
        <?php
        declare(strict_types=1);
        namespace App\BrNoElse;
        class Foo { public function fooId<T>(T $x): T { return $x; } }
        PHP);
        file_put_contents($dir . '/Use.xphp', <<<'PHP'
        <?php
        declare(strict_types=1);
        namespace App\BrNoElse;

        $x = new Foo();
        if (mt_rand(0, 1)) {
            $x = new Foo();
        }
        $r = $x->fooId::<int>(12);
        PHP);

        try {
            $this->compileFrom($dir);
            $use = file_get_contents($dir . '/dist/Use.php');
            self::assertIsString($use);
            self::assertStringNotContainsString(
                'fooId_T_',
                $use,
                'if-without-else must de-specialize even when both paths agree',
            );
        } finally {
            self::rrmdir($dir);
        }
    }

    public function testBranchingThreeArmsAgreeKeepsSpecialization(): void
    {
        // P5.1: if/elseif/else with all three arms assigning the same class
        // exercises the per-arm equality loop's iteration count.
        $dir = sys_get_temp_dir() . '/xphp-br-3arm-' . uniqid('', true);
        mkdir($dir, 0o755, true);
        file_put_contents($dir . '/Foo.xphp', <<<'PHP'
        <?php
        declare(strict_types=1);
        namespace App\Br3Arm;
        class Foo { public function fooId<T>(T $x): T { return $x; } }
        PHP);
        file_put_contents($dir . '/Use.xphp', <<<'PHP'
        <?php
        declare(strict_types=1);
        namespace App\Br3Arm;

        $n = mt_rand(0, 2);
        if ($n === 0) {
            $x = new Foo();
        } elseif ($n === 1) {
            $x = new Foo();
        } else {
            $x = new Foo();
        }
        $r = $x->fooId::<int>(13);
        PHP);

        try {
            $this->compileFrom($dir);
            $use = file_get_contents($dir . '/dist/Use.php');
            self::assertIsString($use);
            self::assertMatchesRegularExpression(
                '/\$x->fooId_T_[0-9a-f]+\(13\)/',
                $use,
                'three-arm all-agree merge must keep $x = Foo',
            );
        } finally {
            self::rrmdir($dir);
        }
    }

    public function testBranchingSwitchWithDefaultAllSameKeepsSpecialization(): void
    {
        // P5.1: switch with default + all cases assign same class merges.
        $dir = sys_get_temp_dir() . '/xphp-br-sw-' . uniqid('', true);
        mkdir($dir, 0o755, true);
        file_put_contents($dir . '/Foo.xphp', <<<'PHP'
        <?php
        declare(strict_types=1);
        namespace App\BrSw;
        class Foo { public function fooId<T>(T $x): T { return $x; } }
        PHP);
        file_put_contents($dir . '/Use.xphp', <<<'PHP'
        <?php
        declare(strict_types=1);
        namespace App\BrSw;

        $n = mt_rand(0, 5);
        switch ($n) {
            case 1: $x = new Foo(); break;
            case 2: $x = new Foo(); break;
            default: $x = new Foo();
        }
        $r = $x->fooId::<int>(14);
        PHP);

        try {
            $this->compileFrom($dir);
            $use = file_get_contents($dir . '/dist/Use.php');
            self::assertIsString($use);
            self::assertMatchesRegularExpression(
                '/\$x->fooId_T_[0-9a-f]+\(14\)/',
                $use,
                'switch with default + all-arms-agree must merge',
            );
        } finally {
            self::rrmdir($dir);
        }
    }

    public function testBranchingSwitchWithoutDefaultStillDeSpecializes(): void
    {
        // P5.1: no `default` case = implicit fall-through = no merge.
        $dir = sys_get_temp_dir() . '/xphp-br-swnod-' . uniqid('', true);
        mkdir($dir, 0o755, true);
        file_put_contents($dir . '/Foo.xphp', <<<'PHP'
        <?php
        declare(strict_types=1);
        namespace App\BrSwNoD;
        class Foo { public function fooId<T>(T $x): T { return $x; } }
        PHP);
        file_put_contents($dir . '/Use.xphp', <<<'PHP'
        <?php
        declare(strict_types=1);
        namespace App\BrSwNoD;

        $x = new Foo();
        $n = mt_rand(0, 5);
        switch ($n) {
            case 1: $x = new Foo(); break;
            case 2: $x = new Foo(); break;
        }
        $r = $x->fooId::<int>(15);
        PHP);

        try {
            $this->compileFrom($dir);
            $use = file_get_contents($dir . '/dist/Use.php');
            self::assertIsString($use);
            self::assertStringNotContainsString(
                'fooId_T_',
                $use,
                'switch without default must de-specialize',
            );
        } finally {
            self::rrmdir($dir);
        }
    }

    public function testBranchingMixedInnerAndOuterMerge(): void
    {
        // P5.1: nested branching where the inner if (both arms = Foo) merges
        // its result into $x, then the outer if (else also = Foo) merges
        // across the outer-inner boundary.
        $dir = sys_get_temp_dir() . '/xphp-br-nested-' . uniqid('', true);
        mkdir($dir, 0o755, true);
        file_put_contents($dir . '/Foo.xphp', <<<'PHP'
        <?php
        declare(strict_types=1);
        namespace App\BrNested;
        class Foo { public function fooId<T>(T $x): T { return $x; } }
        PHP);
        file_put_contents($dir . '/Use.xphp', <<<'PHP'
        <?php
        declare(strict_types=1);
        namespace App\BrNested;

        if (mt_rand(0, 1)) {
            if (mt_rand(0, 1)) {
                $x = new Foo();
            } else {
                $x = new Foo();
            }
        } else {
            $x = new Foo();
        }
        $r = $x->fooId::<int>(16);
        PHP);

        try {
            $this->compileFrom($dir);
            $use = file_get_contents($dir . '/dist/Use.php');
            self::assertIsString($use);
            self::assertMatchesRegularExpression(
                '/\$x->fooId_T_[0-9a-f]+\(16\)/',
                $use,
                'nested merges chain: inner merge -> outer merge',
            );
        } finally {
            self::rrmdir($dir);
        }
    }

    public function testBranchingOneArmAssignsUntrackedRhsStillDeSpecializes(): void
    {
        // P5.1: one arm assigns the same class via `new Foo()`, the other
        // via an untracked RHS (a function call). The untracked arm captures
        // null, the merge fails, $x de-specializes.
        $dir = sys_get_temp_dir() . '/xphp-br-untracked-' . uniqid('', true);
        mkdir($dir, 0o755, true);
        file_put_contents($dir . '/Foo.xphp', <<<'PHP'
        <?php
        declare(strict_types=1);
        namespace App\BrUnt;
        class Foo { public function fooId<T>(T $x): T { return $x; } }
        function computeFoo(): Foo { return new Foo(); }
        PHP);
        file_put_contents($dir . '/Use.xphp', <<<'PHP'
        <?php
        declare(strict_types=1);
        namespace App\BrUnt;

        if (mt_rand(0, 1)) {
            $x = new Foo();
        } else {
            $x = computeFoo();
        }
        $r = $x->fooId::<int>(17);
        PHP);

        try {
            $this->compileFrom($dir);
            $use = file_get_contents($dir . '/dist/Use.php');
            self::assertIsString($use);
            self::assertStringNotContainsString(
                'fooId_T_',
                $use,
                'untracked RHS in one arm must de-specialize',
            );
        } finally {
            self::rrmdir($dir);
        }
    }

    public function testBranchingMatchAllArmsAgreeKeepsSpecialization(): void
    {
        // P5.1: match with default arm + all arms assign same class merges.
        $dir = sys_get_temp_dir() . '/xphp-br-mtch-' . uniqid('', true);
        mkdir($dir, 0o755, true);
        file_put_contents($dir . '/Foo.xphp', <<<'PHP'
        <?php
        declare(strict_types=1);
        namespace App\BrMtch;
        class Foo { public function fooId<T>(T $x): T { return $x; } }
        PHP);
        file_put_contents($dir . '/Use.xphp', <<<'PHP'
        <?php
        declare(strict_types=1);
        namespace App\BrMtch;

        $n = mt_rand(0, 5);
        match (true) {
            $n === 1 => $x = new Foo(),
            $n === 2 => $x = new Foo(),
            default  => $x = new Foo(),
        };
        $r = $x->fooId::<int>(18);
        PHP);

        try {
            $this->compileFrom($dir);
            $use = file_get_contents($dir . '/dist/Use.php');
            self::assertIsString($use);
            self::assertMatchesRegularExpression(
                '/\$x->fooId_T_[0-9a-f]+\(18\)/',
                $use,
                'match with default + all-arms-agree must merge',
            );
        } finally {
            self::rrmdir($dir);
        }
    }

    public function testBranchingMatchWithoutDefaultStillDeSpecializes(): void
    {
        // P5.1: match without default = canMergeOnLeave returns false.
        // (Match would runtime-throw on unmatched value, but we're
        // conservative.)
        $dir = sys_get_temp_dir() . '/xphp-br-mtchnod-' . uniqid('', true);
        mkdir($dir, 0o755, true);
        file_put_contents($dir . '/Foo.xphp', <<<'PHP'
        <?php
        declare(strict_types=1);
        namespace App\BrMtchNoD;
        class Foo { public function fooId<T>(T $x): T { return $x; } }
        PHP);
        file_put_contents($dir . '/Use.xphp', <<<'PHP'
        <?php
        declare(strict_types=1);
        namespace App\BrMtchNoD;

        $x = new Foo();
        $n = mt_rand(0, 5);
        match (true) {
            $n === 1 => $x = new Foo(),
            $n === 2 => $x = new Foo(),
        };
        $r = $x->fooId::<int>(19);
        PHP);

        try {
            $this->compileFrom($dir);
            $use = file_get_contents($dir . '/dist/Use.php');
            self::assertIsString($use);
            self::assertStringNotContainsString(
                'fooId_T_',
                $use,
                'match without default must de-specialize',
            );
        } finally {
            self::rrmdir($dir);
        }
    }

    public function testBranchingElseifMiddleArmDiffersStillDeSpecializes(): void
    {
        // P5.1: three-arm if/elseif/else where the middle arm assigns Bar
        // instead of Foo. Locks the per-arm equality loop -- if the loop
        // accidentally only checks the first vs last arm, this test would
        // wrongly merge against Foo.
        $dir = sys_get_temp_dir() . '/xphp-br-elsmid-' . uniqid('', true);
        mkdir($dir, 0o755, true);
        file_put_contents($dir . '/Foo.xphp', <<<'PHP'
        <?php
        declare(strict_types=1);
        namespace App\BrElsMid;
        class Foo { public function fooId<T>(T $x): T { return $x; } }
        class Bar { }
        PHP);
        file_put_contents($dir . '/Use.xphp', <<<'PHP'
        <?php
        declare(strict_types=1);
        namespace App\BrElsMid;

        $n = mt_rand(0, 2);
        if ($n === 0) {
            $x = new Foo();
        } elseif ($n === 1) {
            $x = new Bar();
        } else {
            $x = new Foo();
        }
        $r = $x->fooId::<int>(20);
        PHP);

        try {
            $this->compileFrom($dir);
            $use = file_get_contents($dir . '/dist/Use.php');
            self::assertIsString($use);
            self::assertStringNotContainsString(
                'fooId_T_',
                $use,
                'middle elseif disagreeing must de-specialize the merge',
            );
        } finally {
            self::rrmdir($dir);
        }
    }

    public function testClosureUseImportPreservesReceiverType(): void
    {
        // Bug fix: closures with explicit `use ($x)` now import the type of
        // `$x` from the parent scope so `$x->m::<T>(...)` inside the closure
        // body can specialize. Without this, the body's specialized call
        // site was silently dropped (the visitor's fresh-scope-per-closure
        // had no knowledge of $x).
        $dir = sys_get_temp_dir() . '/xphp-imp-' . uniqid('', true);
        mkdir($dir, 0o755, true);
        file_put_contents($dir . '/Foo.xphp', <<<'PHP'
        <?php
        declare(strict_types=1);
        namespace App\UseImport;
        class Foo { public function id<T>(T $x): T { return $x; } }
        PHP);
        file_put_contents($dir . '/Use.xphp', <<<'PHP'
        <?php
        declare(strict_types=1);
        namespace App\UseImport;

        $x = new Foo();
        $cb = function () use ($x): void {
            $r = $x->id::<int>(11);
        };
        PHP);

        try {
            $this->compileFrom($dir);
            $use = file_get_contents($dir . '/dist/Use.php');
            self::assertIsString($use);
            self::assertMatchesRegularExpression(
                '/\$x->id_T_[0-9a-f]+\(11\)/',
                $use,
                'closure with `use ($x)` must specialize $x->id::<int> via the imported type',
            );
        } finally {
            self::rrmdir($dir);
        }
    }

    public function testArrowFunctionImplicitCapturePreservesReceiverType(): void
    {
        // Bug fix: arrow functions automatically capture every outer
        // variable. The receiver-type analysis must now copy parent-scope
        // params + locals into the arrow function's scope so the body's
        // call sites can specialize.
        $dir = sys_get_temp_dir() . '/xphp-arrow-imp-' . uniqid('', true);
        mkdir($dir, 0o755, true);
        file_put_contents($dir . '/Foo.xphp', <<<'PHP'
        <?php
        declare(strict_types=1);
        namespace App\ArrowImp;
        class Foo { public function id<T>(T $x): T { return $x; } }
        PHP);
        file_put_contents($dir . '/Use.xphp', <<<'PHP'
        <?php
        declare(strict_types=1);
        namespace App\ArrowImp;

        $x = new Foo();
        $cb = fn() => $x->id::<int>(22);
        PHP);

        try {
            $this->compileFrom($dir);
            $use = file_get_contents($dir . '/dist/Use.php');
            self::assertIsString($use);
            self::assertMatchesRegularExpression(
                '/\$x->id_T_[0-9a-f]+\(22\)/',
                $use,
                'arrow function must inherit outer $x type via implicit capture',
            );
        } finally {
            self::rrmdir($dir);
        }
    }

    private function compileFrom(string $dir): void
    {
        $compiler = $this->buildCompiler();
        $sources = (new NativeFileFinder())->find($dir)
            ->filter(static fn (string $f): bool => str_ends_with($f, '.xphp'));
        $compiler->compile($sources, $dir, $dir . '/dist', $dir . '/.xphp-cache');
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
