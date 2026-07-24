<?php

declare(strict_types=1);

namespace XPHP\Transpiler\Monomorphize;

use PhpParser\ParserFactory;
use PhpParser\PrettyPrinter\Standard as StandardPrinter;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use XPHP\FileSystem\FileFinder\NativeFileFinder;
use XPHP\FileSystem\FileReader\NativeFileReader;
use XPHP\FileSystem\FileWriter\NativeFileWriter;
use XPHP\TestSupport\CompiledFixture;
use XPHP\TestSupport\SnapshotHash;

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

        // Structural invariant: two unique args -> two mangled methods.
        self::assertSame(2, preg_match_all('/function identity_T_[0-9a-f]+\\(/', $content));
        // Negative invariants: the original generic-method template must
        // be removed from the emitted class.
        self::assertStringNotContainsString('function identity(', $content);
        self::assertStringNotContainsString('function identity ', $content);
        SnapshotHash::assertMatches(
            __DIR__ . '/../../fixture/compile/generic_method/verify/testGenericMethodSpecializesPerUniqueCallSiteArgs/Util.expected.php',
            $content,
        );
    }

    public function testCallSitesAreRewrittenToMangledNames(): void
    {
        $this->compile();

        $usePath = $this->targetDir . '/Use.php';
        self::assertFileExists($usePath);
        $content = file_get_contents($usePath);

        // Structural invariants: three call sites total, the two int
        // call sites share a mangled name, int and string mangles differ.
        self::assertSame(3, preg_match_all('/\\\\App\\\\GenericMethod\\\\Util::identity_T_[0-9a-f]+\\(/', $content));
        \preg_match_all('/identity_T_([0-9a-f]+)/', $content, $matches);
        self::assertCount(3, $matches[1]);
        self::assertSame($matches[1][0], $matches[1][2]);
        self::assertNotSame($matches[1][0], $matches[1][1]);
        // Negative invariants: no raw `identity<` or bare `::identity(`
        // call site survives.
        self::assertStringNotContainsString('identity<', $content);
        self::assertStringNotContainsString('::identity(', $content);
        SnapshotHash::assertMatches(
            __DIR__ . '/../../fixture/compile/generic_method/verify/testCallSitesAreRewrittenToMangledNames/Use.expected.php',
            $content,
        );
    }

    #[RunInSeparateProcess]
    public function testRuntimeExecutionPreservesGenericMethodSemantics(): void
    {
        $fixture = CompiledFixture::compile(
            __DIR__ . '/../../fixture/compile/generic_method/source',
            'genmethod-runtime',
        );
        try {
            $runtime = require __DIR__ . '/../../fixture/compile/generic_method/verify/runtime.php';
            $runtime($fixture);
        } finally {
            $fixture->cleanup();
        }
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
            SnapshotHash::assertMatches(
                __DIR__ . '/GenericMethodIntegrationTest/testMultilineStaticCallSiteIsStillRewrittenToMangledName/Use.expected.php',
                $useContent,
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

    #[RunInSeparateProcess]
    public function testSelfWithTypeArgsCompilesEndToEnd(): void
    {
        // Regression: an earlier change shipped only the scanner half --
        // `self<T>` was stripped from the source but the resolver then attached
        // ATTR_GENERIC_ARGS to the bare `self` Name, making the Registry try to
        // specialize a non-existent `App\…\self` template ("Generic template …
        // was instantiated but never defined"). This test compiles a fixture
        // that uses `self<T>` in a return position and asserts the full
        // pipeline (compile + runtime exec).
        $fixture = CompiledFixture::compile(
            __DIR__ . '/../../fixture/compile/generic_method_self_with_type_args/source',
            'genmethod-self-type-args',
        );
        try {
            // The specialized Container class lives under cache/Generated/...
            $generated = self::globRecursive($fixture->cacheDir . '/Generated', '*.php');
            self::assertCount(1, $generated, 'one specialization (Container<int>)');
            $specialized = file_get_contents($generated[0]);
            self::assertIsString($specialized);

            // Negative invariant kept: self must NOT be misresolved to a
            // class FQN (the bug this regression was for).
            self::assertStringNotContainsString('\\App\\GenericMethodSelfReturnTypeArgs\\self', $specialized);
            SnapshotHash::assertMatches(
                __DIR__ . '/../../fixture/compile/generic_method_self_with_type_args/verify/testSelfWithTypeArgsCompilesEndToEnd/Container.expected.php',
                $specialized,
            );

            $fixture->registerAutoload('App\\GenericMethodSelfReturnTypeArgs');
            $runtime = require __DIR__ . '/../../fixture/compile/generic_method_self_with_type_args/verify/runtime.php';
            $runtime($fixture);
        } finally {
            $fixture->cleanup();
        }
    }

    #[RunInSeparateProcess]
    public function testInstanceMethodGenericThisReceiverSpecializes(): void
    {
        // Phase 2 Stage A1: `$this->method::<T>(...)` -- the most common shape.
        // Receiver type is the enclosing class, no flow analysis needed.
        $fixture = CompiledFixture::compile(
            __DIR__ . '/../../fixture/compile/generic_method_this_receiver/source',
            'genmethod-this',
        );
        try {
            $util = file_get_contents($fixture->targetDir . '/Util.php');
            self::assertIsString($util);

            // Structural invariant: two specialized methods appended.
            self::assertSame(
                2,
                preg_match_all('/public function identity_T_[0-9a-f]+\(/', $util),
            );
            // Negative invariant: original generic-method template removed.
            self::assertStringNotContainsString('function identity(', $util);
            SnapshotHash::assertMatches(
                __DIR__ . '/../../fixture/compile/generic_method_this_receiver/verify/testInstanceMethodGenericThisReceiverSpecializes/Util.expected.php',
                $util,
            );

            $runtime = require __DIR__ . '/../../fixture/compile/generic_method_this_receiver/verify/runtime.php';
            $runtime($fixture);
        } finally {
            $fixture->cleanup();
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
            $util = file_get_contents($dir . '/dist/Util.php');
            self::assertIsString($util);

            $snapshotDir = __DIR__ . '/GenericMethodIntegrationTest/testInstanceMethodGenericParamReceiverSpecializes';
            SnapshotHash::assertMatches($snapshotDir . '/Caller.expected.php', $caller);
            SnapshotHash::assertMatches($snapshotDir . '/Util.expected.php', $util);
        } finally {
            self::rrmdir($dir);
        }
    }

    #[RunInSeparateProcess]
    public function testInstanceMethodGenericLocalVariableReceiverSpecializes(): void
    {
        // Phase 2 Stage B: local flow typing. `$u = new Util(); $u->m::<T>(...)`
        // -- the visitor records `$u`'s type from the assignment so the later
        // method call can specialize. Lexical last-write wins; we don't model
        // branches or method-return-typed reassignments.
        $fixture = CompiledFixture::compile(
            __DIR__ . '/../../fixture/compile/generic_method_local_variable_receiver/source',
            'genmethod-local',
        );
        try {
            $use = file_get_contents($fixture->targetDir . '/Use.php');
            self::assertIsString($use);
            SnapshotHash::assertMatches(
                __DIR__ . '/../../fixture/compile/generic_method_local_variable_receiver/verify/testInstanceMethodGenericLocalVariableReceiverSpecializes/Use.expected.php',
                $use,
            );

            $runtime = require __DIR__ . '/../../fixture/compile/generic_method_local_variable_receiver/verify/runtime.php';
            $runtime($fixture);
        } finally {
            $fixture->cleanup();
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
            SnapshotHash::assertMatches(
                __DIR__ . '/GenericMethodIntegrationTest/testInstanceMethodGenericPropertyReceiverSpecializes/Owner.expected.php',
                $owner,
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

            $foo = file_get_contents($dir . '/dist/Foo.php');
            self::assertIsString($foo);
            $bar = file_get_contents($dir . '/dist/Bar.php');
            self::assertIsString($bar);
            $use = file_get_contents($dir . '/dist/Use.php');
            self::assertIsString($use);

            $snapshotDir = __DIR__ . '/GenericMethodIntegrationTest/testReceiverTypeAnalysisDoesNotLeakAcrossClosureScopes';
            SnapshotHash::assertMatches($snapshotDir . '/Foo.expected.php', $foo);
            SnapshotHash::assertMatches($snapshotDir . '/Bar.expected.php', $bar);
            SnapshotHash::assertMatches($snapshotDir . '/Use.expected.php', $use);
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
            $foo = file_get_contents($dir . '/dist/Foo.php');
            self::assertIsString($foo);

            $snapshotDir = __DIR__ . '/GenericMethodIntegrationTest/testReceiverTypeAnalysisDoesNotLeakAcrossArrowFunction';
            SnapshotHash::assertMatches($snapshotDir . '/Use.expected.php', $use);
            SnapshotHash::assertMatches($snapshotDir . '/Foo.expected.php', $foo);
        } finally {
            self::rrmdir($dir);
        }
    }

    public function testBranchingReassignmentMakesReceiverUndeterminedAndFailsToCompile(): void
    {
        // `$x = new Foo(); if (…) { $x = new Bar(); } $x->m::<T>()` must never
        // specialize against the last lexical write (Bar) — the branch may not
        // fire. The flow analyzer invalidates `$x` on the branch's exit, so the
        // receiver's type is undetermined. A turbofish call can't be specialized
        // without a known receiver type, and the generic method is stripped from
        // its class, so leaving the call would emit a runtime "undefined method".
        // Ground or fail: this is a compile error.
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
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('Cannot determine the receiver');
            $this->compileFrom($dir);
        } finally {
            self::rrmdir($dir);
        }
    }

    public function testReassignedParameterReceiverResolvesToTheNewType(): void
    {
        // A typed parameter reassigned to `new Other()` must resolve a later turbofish
        // call against Other — the reassignment invalidates the declared parameter type,
        // which otherwise masked the live local tracking and mis-resolved the call to the
        // param's declared type (which lacks the generic method).
        $dir = sys_get_temp_dir() . '/xphp-reassign-param-' . uniqid('', true);
        mkdir($dir, 0o755, true);
        file_put_contents($dir . '/Use.xphp', <<<'PHP'
        <?php
        declare(strict_types=1);
        namespace App\ReassignParam;
        class Plain { public function m(int $a): int { return $a; } }
        class HasGen { public function pick<R>(R $a): R { return $a; } }
        function run(Plain $x): int {
            $x = new HasGen();
            return $x->pick::<int>(5);
        }
        PHP);

        try {
            $this->compileFrom($dir);
            $use = file_get_contents($dir . '/dist/Use.php');
            self::assertIsString($use);
            self::assertStringContainsString('pick_', $use, 'the call specialized against HasGen');
            self::assertStringNotContainsString('pick::<', $use, 'the turbofish was rewritten');
        } finally {
            self::rrmdir($dir);
        }
    }

    public function testReceiverReassignedFromAnUntrackableCallIsUndetermined(): void
    {
        // A local reassigned from a plain function call (an untrackable RHS) must drop
        // its prior tracked type rather than keep the stale one. Before this was fixed
        // the receiver mis-resolved to the stale `Plain` type and silently specialized
        // against the WRONG class; now it is correctly undetermined (ground or fail).
        $dir = sys_get_temp_dir() . '/xphp-reassign-untrackable-' . uniqid('', true);
        mkdir($dir, 0o755, true);
        file_put_contents($dir . '/Use.xphp', <<<'PHP'
        <?php
        declare(strict_types=1);
        namespace App\ReassignUntrackable;
        class Plain { public function pick<R>(R $a): R { return $a; } }
        class HasGen { public function pick<R>(R $a): R { return $a; } }
        function make(): HasGen { return new HasGen(); }
        function run(): int {
            $x = new Plain();
            $x = make();
            return $x->pick::<int>(5);
        }
        PHP);

        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('Cannot determine the receiver');
            $this->compileFrom($dir);
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
            // Positive invariant: exactly one specialization survived
            // the merge -- defense against a snapshot refresh that
            // captures a regressed (e.g. de-specialized) output.
            self::assertSame(1, preg_match_all('/fooId_T_[0-9a-f]+\(/', $use));
            SnapshotHash::assertMatches(
                __DIR__ . '/GenericMethodIntegrationTest/testBranchingIntraBranchSpecializationStillWorks/Use.expected.php',
                $use,
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
            self::assertSame(1, preg_match_all('/fooId_T_[0-9a-f]+\(/', $use));
            SnapshotHash::assertMatches(
                __DIR__ . '/GenericMethodIntegrationTest/testBranchingElseBranchSeesPreBranchState/Use.expected.php',
                $use,
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
            self::assertSame(1, preg_match_all('/fooId_T_[0-9a-f]+\(/', $use));
            SnapshotHash::assertMatches(
                __DIR__ . '/GenericMethodIntegrationTest/testBranchingSameClassMergeKeepsSpecialization/Use.expected.php',
                $use,
            );
        } finally {
            self::rrmdir($dir);
        }
    }

    public function testBranchingIfWithoutElseUndeterminedReceiverFailsToCompile(): void
    {
        // If-without-else has an implicit empty arm. Even when both reachable
        // paths agree on Foo (the pre-branch assignment matches the if-body's),
        // the merge MUST NOT ground the receiver because the expectedArmCount
        // guard trips (the implicit arm doesn't appear in perBranchTypes, and
        // special-casing it would be fragile). The receiver's type is therefore
        // undetermined, so the turbofish call can't be specialized → compile error.
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
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('Cannot determine the receiver');
            $this->compileFrom($dir);
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
            self::assertSame(1, preg_match_all('/fooId_T_[0-9a-f]+\(/', $use));
            SnapshotHash::assertMatches(
                __DIR__ . '/GenericMethodIntegrationTest/testBranchingThreeArmsAgreeKeepsSpecialization/Use.expected.php',
                $use,
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
            self::assertSame(1, preg_match_all('/fooId_T_[0-9a-f]+\(/', $use));
            SnapshotHash::assertMatches(
                __DIR__ . '/GenericMethodIntegrationTest/testBranchingSwitchWithDefaultAllSameKeepsSpecialization/Use.expected.php',
                $use,
            );
        } finally {
            self::rrmdir($dir);
        }
    }

    public function testBranchingSwitchWithoutDefaultUndeterminedReceiverFailsToCompile(): void
    {
        // No `default` case = implicit fall-through = no merge, so the receiver's
        // type stays undetermined and the turbofish call can't be specialized.
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
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('Cannot determine the receiver');
            $this->compileFrom($dir);
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
            self::assertSame(1, preg_match_all('/fooId_T_[0-9a-f]+\(/', $use));
            SnapshotHash::assertMatches(
                __DIR__ . '/GenericMethodIntegrationTest/testBranchingMixedInnerAndOuterMerge/Use.expected.php',
                $use,
            );
        } finally {
            self::rrmdir($dir);
        }
    }

    public function testBranchingOneArmAssignsUntrackedRhsUndeterminedReceiverFailsToCompile(): void
    {
        // One arm assigns the same class via `new Foo()`, the other via an
        // untracked RHS (a function call whose return type the flow analyzer
        // doesn't read here). The untracked arm captures null, the merge fails,
        // and the receiver's type is undetermined → the turbofish call fails.
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
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('Cannot determine the receiver');
            $this->compileFrom($dir);
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
            self::assertSame(1, preg_match_all('/fooId_T_[0-9a-f]+\(/', $use));
            SnapshotHash::assertMatches(
                __DIR__ . '/GenericMethodIntegrationTest/testBranchingMatchAllArmsAgreeKeepsSpecialization/Use.expected.php',
                $use,
            );
        } finally {
            self::rrmdir($dir);
        }
    }

    public function testBranchingMatchWithoutDefaultUndeterminedReceiverFailsToCompile(): void
    {
        // Match without default = canMergeOnLeave returns false, so the receiver's
        // type stays undetermined and the turbofish call can't be specialized.
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
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('Cannot determine the receiver');
            $this->compileFrom($dir);
        } finally {
            self::rrmdir($dir);
        }
    }

    public function testBranchingElseifMiddleArmDiffersUndeterminedReceiverFailsToCompile(): void
    {
        // Three-arm if/elseif/else where the middle arm assigns Bar instead of
        // Foo. Locks the per-arm equality loop -- if it accidentally only checked
        // the first vs last arm it would wrongly merge against Foo and ground the
        // receiver. The arms disagree, so the receiver is undetermined → the
        // turbofish call can't be specialized and fails to compile.
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
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('Cannot determine the receiver');
            $this->compileFrom($dir);
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
            SnapshotHash::assertMatches(
                __DIR__ . '/GenericMethodIntegrationTest/testClosureUseImportPreservesReceiverType/Use.expected.php',
                $use,
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
            SnapshotHash::assertMatches(
                __DIR__ . '/GenericMethodIntegrationTest/testArrowFunctionImplicitCapturePreservesReceiverType/Use.expected.php',
                $use,
            );
        } finally {
            self::rrmdir($dir);
        }
    }

    #[RunInSeparateProcess]
    public function testNewSelfTurbofishCompilesEndToEnd(): void
    {
        // `new self::<T>(...)` -- the scanner strips `::<T>`, no marker fires,
        // monomorphization preserves the bare `new self(...)` in the
        // specialization. PHP's runtime resolves `self` against the
        // specialized class, which IS the right answer.
        $fixture = CompiledFixture::compile(
            __DIR__ . '/../../fixture/compile/generic_method_new_self_turbofish/source',
            'genmethod-pseudo-self',
        );
        try {
            $generated = self::globRecursive($fixture->cacheDir . '/Generated', '*.php');
            self::assertCount(1, $generated, 'one specialization (Container<int>)');
            $specialized = file_get_contents($generated[0]);
            self::assertIsString($specialized);

            // Negative invariants kept: self must NOT be misresolved to a
            // class FQN, and no leftover turbofish marker survives.
            self::assertStringNotContainsString('App\\GenericMethodNewSelfTurbofish\\self', $specialized);
            self::assertStringNotContainsString('::<', $specialized);
            SnapshotHash::assertMatches(
                __DIR__ . '/../../fixture/compile/generic_method_new_self_turbofish/verify/testNewSelfTurbofishCompilesEndToEnd/Container.expected.php',
                $specialized,
            );

            $fixture->registerAutoload('App\\GenericMethodNewSelfTurbofish');
            $runtime = require __DIR__ . '/../../fixture/compile/generic_method_new_self_turbofish/verify/runtime.php';
            $runtime($fixture);
        } finally {
            $fixture->cleanup();
        }
    }

    #[RunInSeparateProcess]
    public function testNewStaticTurbofishCompilesEndToEnd(): void
    {
        // `new static::<T>(...)` -- the late-static-bound pseudo-type. After
        // monomorphization, `static` resolves to the specialized class at
        // runtime (same class instance, no subclassing in this fixture).
        $fixture = CompiledFixture::compile(
            __DIR__ . '/../../fixture/compile/generic_method_new_static_turbofish/source',
            'genmethod-pseudo-static',
        );
        try {
            $generated = self::globRecursive($fixture->cacheDir . '/Generated', '*.php');
            self::assertCount(1, $generated);
            $specialized = file_get_contents($generated[0]);
            self::assertIsString($specialized);

            // Negative invariants kept: static must not be misresolved
            // to a class FQN; no leftover turbofish marker.
            self::assertStringNotContainsString('App\\GenericMethodNewStaticTurbofish\\static', $specialized);
            self::assertStringNotContainsString('::<', $specialized);
            SnapshotHash::assertMatches(
                __DIR__ . '/../../fixture/compile/generic_method_new_static_turbofish/verify/testNewStaticTurbofishCompilesEndToEnd/Builder.expected.php',
                $specialized,
            );

            $fixture->registerAutoload('App\\GenericMethodNewStaticTurbofish');
            $runtime = require __DIR__ . '/../../fixture/compile/generic_method_new_static_turbofish/verify/runtime.php';
            $runtime($fixture);
        } finally {
            $fixture->cleanup();
        }
    }

    public function testNewParentTurbofishCompilesEndToEnd(): void
    {
        // `new parent::<T>(...)` -- the parent-class pseudo-type. Different
        // structure: requires a Parent_<T> base class so `parent` resolves
        // to a real, distinct specialization.
        $dir = sys_get_temp_dir() . '/xphp-pseudo-parent-' . uniqid('', true);
        mkdir($dir, 0o755, true);
        file_put_contents($dir . '/Parent_.xphp', <<<'PHP'
        <?php
        declare(strict_types=1);
        namespace App\PseudoParent;
        class Parent_<T> {
            public function __construct(public T $value) {}
        }
        PHP);
        file_put_contents($dir . '/Child.xphp', <<<'PHP'
        <?php
        declare(strict_types=1);
        namespace App\PseudoParent;
        class Child<T> extends Parent_<T> {
            public function makeParent(T $v): Parent_<T> {
                return new parent::<T>($v);
            }
        }
        PHP);
        file_put_contents($dir . '/Use.xphp', <<<'PHP'
        <?php
        declare(strict_types=1);
        namespace App\PseudoParent;

        $c = new Child::<int>(1);
        $p = $c->makeParent(2);
        PHP);

        try {
            $this->compileFrom($dir);
            // Specializations: Parent_<int> and Child<int>.
            $generated = self::globRecursive($dir . '/.xphp-cache/Generated', '*.php');
            self::assertGreaterThanOrEqual(2, count($generated));

            $childSpecialization = null;
            foreach ($generated as $f) {
                if (str_contains($f, '/Child/T_')) {
                    $childSpecialization = file_get_contents($f);
                    break;
                }
            }
            self::assertIsString($childSpecialization, 'expected a Child<int> specialization at /Child/T_*.php');

            // Negative invariants kept: parent must not be misresolved
            // to a class FQN; no leftover turbofish marker.
            self::assertStringNotContainsString('App\\PseudoParent\\parent', $childSpecialization);
            self::assertStringNotContainsString('::<', $childSpecialization);
            SnapshotHash::assertMatches(
                __DIR__ . '/GenericMethodIntegrationTest/testNewParentTurbofishCompilesEndToEnd/Child.expected.php',
                $childSpecialization,
            );
        } finally {
            self::rrmdir($dir);
        }
    }

    #[RunInSeparateProcess]
    public function testGenericMethodResolvesThroughInheritance(): void
    {
        // A generic method declared on a base class resolves and
        // runs when called via turbofish on a subclass receiver. The
        // specialization is emitted onto the DECLARING base so every subclass
        // inherits the single copy through the class-level `extends` edge --
        // it is NOT duplicated onto the receiver's own specialization.
        $fixture = CompiledFixture::compile(
            __DIR__ . '/../../fixture/compile/generic_method_through_inheritance/source',
            'genmethod-inherit',
        );
        try {
            $generated = self::globRecursive($fixture->cacheDir . '/Generated', '*.php');

            $baseSpecialization = '';
            $derivedSpecialization = '';
            foreach ($generated as $f) {
                $content = file_get_contents($f);
                self::assertIsString($content);
                if (str_contains($f, '/Base/T_')) {
                    $baseSpecialization .= $content;
                }
                if (str_contains($f, '/Derived/T_')) {
                    $derivedSpecialization .= $content;
                }
            }

            // Both `identity` specializations (<string> and <int>) land on Base.
            self::assertSame(
                2,
                preg_match_all('/function identity_T_[0-9a-f]+\(/', $baseSpecialization),
                'both identity specializations emitted onto the declaring Base',
            );
            // Derived inherits them; nothing is duplicated onto the subclass.
            self::assertStringNotContainsString(
                'identity_T_',
                $derivedSpecialization,
                'subclass inherits the base specialization; no duplicate on Derived',
            );

            $fixture->registerAutoload('App\\GenericMethodThroughInheritance');
            $runtime = require __DIR__ . '/../../fixture/compile/generic_method_through_inheritance/verify/runtime.php';
            $runtime($fixture);
        } finally {
            $fixture->cleanup();
        }
    }

    #[RunInSeparateProcess]
    public function testStaticGenericMethodResolvesThroughInheritance(): void
    {
        // Static path: a static generic method declared on Base
        // resolves and runs when called as `Derived::make::<...>()`. The
        // specialization is emitted onto the declaring Base and reached via
        // PHP's static-method inheritance.
        $fixture = CompiledFixture::compile(
            __DIR__ . '/../../fixture/compile/generic_static_method_through_inheritance/source',
            'genmethod-static-inherit',
        );
        try {
            $base = file_get_contents($fixture->targetDir . '/Base.php');
            $derived = file_get_contents($fixture->targetDir . '/Derived.php');
            self::assertIsString($base);
            self::assertIsString($derived);
            // Both make specializations land on Base; Derived inherits them.
            self::assertSame(2, preg_match_all('/function make_T_[0-9a-f]+\(/', $base));
            self::assertStringNotContainsString('make_T_', $derived);

            $runtime = require __DIR__ . '/../../fixture/compile/generic_static_method_through_inheritance/verify/runtime.php';
            $runtime($fixture);
        } finally {
            $fixture->cleanup();
        }
    }

    public function testNullsafeInheritedGenericMethodResolves(): void
    {
        // Nullsafe instance turbofish resolves through inheritance too (it shares
        // the instance path). The specialization lands on the declaring base and
        // the call is rewritten while preserving the `?->` short-circuit operator.
        $dir = sys_get_temp_dir() . '/xphp-inh-nullsafe-' . uniqid('', true);
        mkdir($dir, 0o755, true);
        file_put_contents($dir . '/Base.xphp', <<<'PHP'
        <?php
        declare(strict_types=1);
        namespace App\InhNullsafe;
        class Base { public function id<U>(U $x): U { return $x; } }
        PHP);
        file_put_contents($dir . '/Derived.xphp', <<<'PHP'
        <?php
        declare(strict_types=1);
        namespace App\InhNullsafe;
        class Derived extends Base {}
        PHP);
        file_put_contents($dir . '/Caller.xphp', <<<'PHP'
        <?php
        declare(strict_types=1);
        namespace App\InhNullsafe;
        class Caller {
            public function go(?Derived $d): ?int {
                return $d?->id::<int>(5);
            }
        }
        PHP);

        try {
            $this->compileFrom($dir);
            $base = file_get_contents($dir . '/dist/Base.php');
            $caller = file_get_contents($dir . '/dist/Caller.php');
            self::assertIsString($base);
            self::assertIsString($caller);
            // Specialization on the declaring base; nullsafe operator preserved.
            self::assertSame(1, preg_match_all('/function id_T_[0-9a-f]+\(/', $base));
            self::assertMatchesRegularExpression('/\$d\?->id_T_[0-9a-f]+\(/', $caller);
        } finally {
            self::rrmdir($dir);
        }
    }

    public function testParentTurbofishResolvesInheritedStaticGenericMethod(): void
    {
        // A static generic method inherited from the parent now resolves via the
        // ancestor walk (before the static path walked ancestors this was an
        // "unresolved generic method" compile error).
        $dir = sys_get_temp_dir() . '/xphp-inh-parent-' . uniqid('', true);
        mkdir($dir, 0o755, true);
        file_put_contents($dir . '/Base.xphp', <<<'PHP'
        <?php
        declare(strict_types=1);
        namespace App\InhParent;
        class Base { public static function make<U>(U $x): U { return $x; } }
        PHP);
        file_put_contents($dir . '/Child.xphp', <<<'PHP'
        <?php
        declare(strict_types=1);
        namespace App\InhParent;
        class Child extends Base {
            public function run(): int { return parent::make::<int>(1); }
        }
        PHP);

        try {
            $this->compileFrom($dir); // must NOT throw
            $base = file_get_contents($dir . '/dist/Base.php');
            $child = file_get_contents($dir . '/dist/Child.php');
            self::assertIsString($base);
            self::assertIsString($child);
            self::assertSame(1, preg_match_all('/function make_T_[0-9a-f]+\(/', $base));
            // The inherited static call resolved to a mangled specialization (the
            // `parent::` receiver resolves to the current class, which inherits the
            // base method); no leftover turbofish marker survives.
            self::assertMatchesRegularExpression('/::make_T_[0-9a-f]+\(/', $child);
            self::assertStringNotContainsString('make::<', $child);
        } finally {
            self::rrmdir($dir);
        }
    }

    public function testSubclassGenericMethodOverrideBindsToSubclass(): void
    {
        // The direct hit on the receiver's own class wins over the ancestor
        // walk: a subclass that redeclares the generic method binds its own
        // body, so the specialization lands on the subclass, not the base.
        $dir = sys_get_temp_dir() . '/xphp-inh-override-' . uniqid('', true);
        mkdir($dir, 0o755, true);
        file_put_contents($dir . '/Base.xphp', <<<'PHP'
        <?php
        declare(strict_types=1);
        namespace App\InhOverride;
        class Base { public function id<U>(U $x): U { return $x; } }
        PHP);
        file_put_contents($dir . '/Derived.xphp', <<<'PHP'
        <?php
        declare(strict_types=1);
        namespace App\InhOverride;
        class Derived extends Base { public function id<U>(U $x): U { return $x; } }
        PHP);
        file_put_contents($dir . '/Use.xphp', <<<'PHP'
        <?php
        declare(strict_types=1);
        namespace App\InhOverride;
        $d = new Derived();
        $r = $d->id::<int>(5);
        PHP);

        try {
            $this->compileFrom($dir);
            $base = file_get_contents($dir . '/dist/Base.php');
            $derived = file_get_contents($dir . '/dist/Derived.php');
            self::assertIsString($base);
            self::assertIsString($derived);
            // Override wins: the specialization is on Derived (the direct hit).
            self::assertSame(1, preg_match_all('/function id_T_[0-9a-f]+\(/', $derived));
            self::assertStringNotContainsString('id_T_', $base);
        } finally {
            self::rrmdir($dir);
        }
    }

    public function testGenericMethodResolvesThroughMultiLevelInheritance(): void
    {
        // Base <- Mid <- Leaf: the method on Base resolves on a Leaf receiver,
        // and the specialization lands on the nearest *declaring* ancestor
        // (Base), reached via the breadth-first ancestor walk.
        $dir = sys_get_temp_dir() . '/xphp-inh-chain-' . uniqid('', true);
        mkdir($dir, 0o755, true);
        file_put_contents($dir . '/Base.xphp', <<<'PHP'
        <?php
        declare(strict_types=1);
        namespace App\InhChain;
        class Base { public function id<U>(U $x): U { return $x; } }
        PHP);
        file_put_contents($dir . '/Mid.xphp', <<<'PHP'
        <?php
        declare(strict_types=1);
        namespace App\InhChain;
        class Mid extends Base {}
        PHP);
        file_put_contents($dir . '/Leaf.xphp', <<<'PHP'
        <?php
        declare(strict_types=1);
        namespace App\InhChain;
        class Leaf extends Mid {}
        PHP);
        file_put_contents($dir . '/Use.xphp', <<<'PHP'
        <?php
        declare(strict_types=1);
        namespace App\InhChain;
        $leaf = new Leaf();
        $r = $leaf->id::<int>(9);
        PHP);

        try {
            $this->compileFrom($dir);
            $base = file_get_contents($dir . '/dist/Base.php');
            $mid = file_get_contents($dir . '/dist/Mid.php');
            $leaf = file_get_contents($dir . '/dist/Leaf.php');
            self::assertIsString($base);
            self::assertIsString($mid);
            self::assertIsString($leaf);
            self::assertSame(1, preg_match_all('/function id_T_[0-9a-f]+\(/', $base));
            self::assertStringNotContainsString('id_T_', $mid);
            self::assertStringNotContainsString('id_T_', $leaf);
        } finally {
            self::rrmdir($dir);
        }
    }

    public function testIntermediateOverrideBindsToNearestDeclaringAncestor(): void
    {
        // Base declares id<U>; Mid overrides it; Leaf inherits. A call on a Leaf
        // receiver binds the NEAREST declaring ancestor (Mid) -- the breadth-first
        // walk returns Mid's template first, so the specialization lands on Mid,
        // not on Base.
        $dir = sys_get_temp_dir() . '/xphp-inh-midoverride-' . uniqid('', true);
        mkdir($dir, 0o755, true);
        file_put_contents($dir . '/Base.xphp', <<<'PHP'
        <?php
        declare(strict_types=1);
        namespace App\InhMidOverride;
        class Base { public function id<U>(U $x): U { return $x; } }
        PHP);
        file_put_contents($dir . '/Mid.xphp', <<<'PHP'
        <?php
        declare(strict_types=1);
        namespace App\InhMidOverride;
        class Mid extends Base { public function id<U>(U $x): U { return $x; } }
        PHP);
        file_put_contents($dir . '/Leaf.xphp', <<<'PHP'
        <?php
        declare(strict_types=1);
        namespace App\InhMidOverride;
        class Leaf extends Mid {}
        PHP);
        file_put_contents($dir . '/Use.xphp', <<<'PHP'
        <?php
        declare(strict_types=1);
        namespace App\InhMidOverride;
        $leaf = new Leaf();
        $r = $leaf->id::<int>(3);
        PHP);

        try {
            $this->compileFrom($dir);
            $base = file_get_contents($dir . '/dist/Base.php');
            $mid = file_get_contents($dir . '/dist/Mid.php');
            $leaf = file_get_contents($dir . '/dist/Leaf.php');
            self::assertIsString($base);
            self::assertIsString($mid);
            self::assertIsString($leaf);
            // Nearest declaring ancestor wins: the specialization lands on Mid only.
            self::assertSame(1, preg_match_all('/function id_T_[0-9a-f]+\(/', $mid));
            self::assertStringNotContainsString('id_T_', $base);
            self::assertStringNotContainsString('id_T_', $leaf);
        } finally {
            self::rrmdir($dir);
        }
    }

    public function testInheritedGenericMethodDedupesOnSharedBase(): void
    {
        // Two subclasses calling the same inherited method with the same type
        // argument emit exactly ONE specialization, on the shared base (dedup
        // keyed by the declaring FQN, not the receiver).
        $dir = sys_get_temp_dir() . '/xphp-inh-dedup-' . uniqid('', true);
        mkdir($dir, 0o755, true);
        file_put_contents($dir . '/Base.xphp', <<<'PHP'
        <?php
        declare(strict_types=1);
        namespace App\InhDedup;
        abstract class Base { public function id<U>(U $x): U { return $x; } }
        PHP);
        file_put_contents($dir . '/A.xphp', <<<'PHP'
        <?php
        declare(strict_types=1);
        namespace App\InhDedup;
        class A extends Base {}
        PHP);
        file_put_contents($dir . '/B.xphp', <<<'PHP'
        <?php
        declare(strict_types=1);
        namespace App\InhDedup;
        class B extends Base {}
        PHP);
        file_put_contents($dir . '/Use.xphp', <<<'PHP'
        <?php
        declare(strict_types=1);
        namespace App\InhDedup;
        $a = new A();
        $b = new B();
        $ra = $a->id::<int>(1);
        $rb = $b->id::<int>(2);
        PHP);

        try {
            $this->compileFrom($dir);
            $base = file_get_contents($dir . '/dist/Base.php');
            self::assertIsString($base);
            self::assertSame(1, preg_match_all('/function id_T_[0-9a-f]+\(/', $base));
        } finally {
            self::rrmdir($dir);
        }
    }

    public function testPlainNonGenericCallIsNotFlaggedAsUnresolvedGeneric(): void
    {
        // The unresolved-generic error fires only for turbofish calls. A plain
        // (non-turbofish) call to a non-template method passes through untouched
        // -- it is ordinary PHP, not a generic-resolution failure -- while a
        // turbofish call to a method that DOES exist still specializes.
        $dir = sys_get_temp_dir() . '/xphp-plaincall-' . uniqid('', true);
        mkdir($dir, 0o755, true);
        file_put_contents($dir . '/Box.xphp', <<<'PHP'
        <?php
        declare(strict_types=1);
        namespace App\PlainCall;
        class Box { public function get<T>(T $x): T { return $x; } }
        PHP);
        file_put_contents($dir . '/Use.xphp', <<<'PHP'
        <?php
        declare(strict_types=1);
        namespace App\PlainCall;
        $b = new Box();
        $b->nope(1);
        $r = $b->get::<int>(2);
        PHP);

        try {
            $this->compileFrom($dir); // must NOT throw
            $use = file_get_contents($dir . '/dist/Use.php');
            self::assertIsString($use);
            // Plain call survives untouched; the turbofish call specializes.
            self::assertStringContainsString('$b->nope(1)', $use);
            self::assertSame(1, preg_match_all('/get_T_[0-9a-f]+\(/', $use));
        } finally {
            self::rrmdir($dir);
        }
    }

    public function testUnresolvedStaticGenericMethodTurbofishFailsCompilation(): void
    {
        // The unresolved-generic error also covers the static turbofish path:
        // `Box::nope::<int>()` where `nope` is not a generic method on Box.
        $dir = sys_get_temp_dir() . '/xphp-unresolved-static-' . uniqid('', true);
        mkdir($dir, 0o755, true);
        file_put_contents($dir . '/Box.xphp', <<<'PHP'
        <?php
        declare(strict_types=1);
        namespace App\UnresolvedStatic;
        class Box { public static function get<T>(T $x): T { return $x; } }
        PHP);
        file_put_contents($dir . '/Use.xphp', <<<'PHP'
        <?php
        declare(strict_types=1);
        namespace App\UnresolvedStatic;
        $r = Box::nope::<int>(1);
        PHP);

        try {
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('could not be resolved');
            $this->compileFrom($dir);
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
