<?php

declare(strict_types=1);

namespace XPHP\Transpiler\Monomorphize;

use PhpParser\Node\ClosureUse;
use PhpParser\Node\Expr\ArrowFunction;
use PhpParser\Node\Expr\Variable;
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

/**
 * Tests for P5.5 arrow specialization: the implicit-capture analyzer
 * (`ClosureDispatcher::implicitCapturesOf`) plus the end-to-end shape
 * that emerges from `Compiler::compile`.
 */
final class ArrowSpecializationTest extends TestCase
{
    // ----- analyzer unit tests --------------------------------------------

    public function testImplicitCapturesEmptyForArrowWithoutFreeVars(): void
    {
        $arrow = $this->parseArrow('fn(int $x) => $x * 2');
        self::assertSame([], ClosureDispatcher::implicitCapturesOf($arrow));
    }

    public function testImplicitCapturesSingleFreeVar(): void
    {
        $arrow = $this->parseArrow('fn(int $x) => $x + $y');
        $captures = ClosureDispatcher::implicitCapturesOf($arrow);
        self::assertCount(1, $captures);
        self::assertSame('y', $captures[0]->var->name);
        self::assertFalse($captures[0]->byRef);
    }

    public function testImplicitCapturesExcludesArrowParams(): void
    {
        $arrow = $this->parseArrow('fn(int $x, int $y) => $x + $y');
        self::assertSame([], ClosureDispatcher::implicitCapturesOf($arrow));
    }

    public function testImplicitCapturesOrderedByFirstOccurrence(): void
    {
        // First-occurrence order: $z appears before $y in source.
        $arrow = $this->parseArrow('fn(int $x) => $z + $y + $z');
        $names = array_map(fn (ClosureUse $u) => $u->var->name, ClosureDispatcher::implicitCapturesOf($arrow));
        self::assertSame(['z', 'y'], $names);
    }

    public function testImplicitCapturesDoesNotDescendIntoNestedClosureBody(): void
    {
        // The inner Closure's body references `$w`, but its own `use ($w)`
        // names `$w` explicitly. The analyzer SHOULD harvest `$w` from the
        // inner closure's `use` clause (per the Round 10 reviewer fix), so
        // the outer dispatcher's `use ($w)` brings it in.
        $arrow = $this->parseArrow('fn(int $x) => $x + (function () use ($w) { return $w; })()');
        $names = array_map(fn (ClosureUse $u) => $u->var->name, ClosureDispatcher::implicitCapturesOf($arrow));
        self::assertSame(['w'], $names);
    }

    public function testImplicitCapturesHarvestsFromNestedArrow(): void
    {
        // Inner arrow re-captures `$y` from our scope.
        $arrow = $this->parseArrow('fn(int $x) => $x + (fn() => $y)()');
        $names = array_map(fn (ClosureUse $u) => $u->var->name, ClosureDispatcher::implicitCapturesOf($arrow));
        self::assertSame(['y'], $names);
    }

    public function testImplicitCapturesSkipsThis(): void
    {
        $arrow = $this->parseArrow('fn(int $x) => $x + $this->v');
        // `$this` is intentionally excluded from the capture set; the
        // GMC rejects arrows that need `$this` at the call-site path.
        $names = array_map(fn (ClosureUse $u) => $u->var->name, ClosureDispatcher::implicitCapturesOf($arrow));
        self::assertNotContains('this', $names);
    }

    // ----- end-to-end integration tests -----------------------------------

    #[RunInSeparateProcess]
    public function testArrowSpecializationEndToEndSingleCapture(): void
    {
        $fixture = CompiledFixture::compile(
            __DIR__ . '/../../fixture/compile/arrow_capture_at_declaration/source',
            'arrow-capture',
        );
        try {
            require __DIR__ . '/../../fixture/compile/arrow_capture_at_declaration/verify/runtime.php';
        } finally {
            $fixture->cleanup();
        }
    }

    #[RunInSeparateProcess]
    public function testArrowSpecializationEndToEndMultipleCaptures(): void
    {
        $fixture = CompiledFixture::compile(
            __DIR__ . '/../../fixture/compile/arrow_multiple_captures/source',
            'arrow-multi',
        );
        try {
            require __DIR__ . '/../../fixture/compile/arrow_multiple_captures/verify/runtime.php';
        } finally {
            $fixture->cleanup();
        }
    }

    #[RunInSeparateProcess]
    public function testArrowSpecializationEndToEndNoCaptures(): void
    {
        $fixture = CompiledFixture::compile(
            __DIR__ . '/../../fixture/compile/arrow_no_captures/source',
            'arrow-empty',
        );
        try {
            require __DIR__ . '/../../fixture/compile/arrow_no_captures/verify/runtime.php';
        } finally {
            $fixture->cleanup();
        }
    }

    #[RunInSeparateProcess]
    public function testArrowSpecializationCaptureShadowingParamName(): void
    {
        // Param wins -- the outer $x = 99 is NOT captured because `x`
        // is in the arrow's param-set.
        $fixture = CompiledFixture::compile(
            __DIR__ . '/../../fixture/compile/arrow_capture_shadowing/source',
            'arrow-shadow',
        );
        try {
            require __DIR__ . '/../../fixture/compile/arrow_capture_shadowing/verify/runtime.php';
        } finally {
            $fixture->cleanup();
        }
    }

    #[RunInSeparateProcess]
    public function testArrowSpecializationMultipleArgTuples(): void
    {
        $fixture = CompiledFixture::compile(
            __DIR__ . '/../../fixture/compile/arrow_multiple_arg_tuples/source',
            'arrow-multitup',
        );
        try {
            $out = file_get_contents($fixture->targetDir . '/Use.php');
            self::assertIsString($out);

            // Structural invariant kept: two distinct specializations
            // (T=int and T=string).
            preg_match_all('/function closure_f_T_[0-9a-f]+\(/', $out, $matches);
            self::assertCount(2, $matches[0]);
            SnapshotHash::assertMatches(
                __DIR__ . '/../../fixture/compile/arrow_multiple_arg_tuples/verify/testArrowSpecializationMultipleArgTuples/Use.expected.php',
                $out,
            );

            require __DIR__ . '/../../fixture/compile/arrow_multiple_arg_tuples/verify/runtime.php';
        } finally {
            $fixture->cleanup();
        }
    }

    public function testArrowSpecializationThisCaptureRejected(): void
    {
        // P5.5 rejects `$this`-capturing generic arrows. The error
        // points users at lifting to a method or extracting the
        // property value.
        $dir = $this->mkdir('arrow-this');
        file_put_contents($dir . '/Use.xphp', <<<'PHP'
        <?php
        namespace App\ArrowThis;
        class Holder {
            public int $v = 5;
            public function go(): int {
                $f = fn<T>(T $x): T => $x + $this->v;
                return $f::<int>(2);
            }
        }
        PHP);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('captures `$this`');
        $this->compile($dir);
        $this->rrmdir(dirname($dir));
    }

    #[RunInSeparateProcess]
    public function testArrowSpecializationReservedArgsCaptureAlsoTriggersRename(): void
    {
        // Symmetric to the `__xphp_tag` test -- a capture named
        // `__xphp_args` collides with the dispatcher's variadic param.
        // Both the tag AND args params get renamed together.
        $fixture = CompiledFixture::compile(
            __DIR__ . '/../../fixture/compile/arrow_reserved_args_capture/source',
            'arrow-reserved-args',
        );
        try {
            $out = file_get_contents($fixture->targetDir . '/Use.php');
            self::assertIsString($out);

            // Structural invariant: the dispatcher's args param was renamed.
            self::assertMatchesRegularExpression(
                '/mixed \.\.\.\$__xphp_args_[0-9a-f]{8}/',
                $out,
            );
            SnapshotHash::assertMatches(
                __DIR__ . '/../../fixture/compile/arrow_reserved_args_capture/verify/testArrowSpecializationReservedArgsCaptureAlsoTriggersRename/Use.expected.php',
                $out,
            );

            require __DIR__ . '/../../fixture/compile/arrow_reserved_args_capture/verify/runtime.php';
        } finally {
            $fixture->cleanup();
        }
    }

    #[RunInSeparateProcess]
    public function testArrowSpecializationReservedCaptureAutoRenamesDispatcherParam(): void
    {
        // Capture name `__xphp_tag` collides with the dispatcher's
        // tag param. The dispatcher should auto-rename its own tag
        // param to a collision-free alternative; the user's
        // `$__xphp_tag` keeps its name.
        $fixture = CompiledFixture::compile(
            __DIR__ . '/../../fixture/compile/arrow_reserved_tag_capture/source',
            'arrow-reserved',
        );
        try {
            $out = file_get_contents($fixture->targetDir . '/Use.php');
            self::assertIsString($out);

            // Structural invariant: the dispatcher's tag param was renamed
            // (not the default), since the user's capture collides.
            self::assertMatchesRegularExpression(
                '/string \$__xphp_tag_[0-9a-f]{8}/',
                $out,
            );
            SnapshotHash::assertMatches(
                __DIR__ . '/../../fixture/compile/arrow_reserved_tag_capture/verify/testArrowSpecializationReservedCaptureAutoRenamesDispatcherParam/Use.expected.php',
                $out,
            );

            require __DIR__ . '/../../fixture/compile/arrow_reserved_tag_capture/verify/runtime.php';
        } finally {
            $fixture->cleanup();
        }
    }

    // ----- helpers --------------------------------------------------------

    private function parseArrow(string $source): ArrowFunction
    {
        $parser = (new ParserFactory())->createForHostVersion();
        $stmts = $parser->parse('<?php ' . $source . ';');
        // The arrow lives inside an Expression statement at index 0.
        $arrow = $stmts[0]->expr;
        self::assertInstanceOf(ArrowFunction::class, $arrow);
        return $arrow;
    }

    private function mkdir(string $tag): string
    {
        $root = sys_get_temp_dir() . '/xphp-' . $tag . '-' . uniqid('', true);
        $src = $root . '/src';
        mkdir($src, 0o755, true);
        return $src;
    }

    private function compile(string $sourceDir): void
    {
        $compiler = $this->buildCompiler();
        $sources = (new NativeFileFinder())->find($sourceDir)
            ->filter(static fn (string $f): bool => str_ends_with($f, '.xphp'));
        $compiler->compile(
            $sources,
            $sourceDir,
            dirname($sourceDir) . '/dist',
            dirname($sourceDir) . '/.xphp-cache',
        );
        $dist = dirname($sourceDir) . '/dist';
        if (is_dir($dist) && !is_dir($sourceDir . '/dist')) {
            symlink($dist, $sourceDir . '/dist');
        }
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

    private function rrmdir(string $dir): void
    {
        if (!is_dir($dir) && !is_link($dir)) {
            return;
        }
        if (is_link($dir)) {
            unlink($dir);
            return;
        }
        foreach (scandir($dir) as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            if (is_link($path)) {
                unlink($path);
            } elseif (is_dir($path)) {
                $this->rrmdir($path);
            } else {
                unlink($path);
            }
        }
        rmdir($dir);
    }
}
