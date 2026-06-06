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

    public function testArrowSpecializationEndToEndSingleCapture(): void
    {
        // The sprint plan's canonical test:
        //   $y = 1; $id = fn<T>(T $x): T => $x + $y; $y = 2; $id::<int>(42);
        // Expected: 43 (capture moment is the arrow's evaluation, not the call).
        $dir = $this->mkdir('arrow-capture');
        file_put_contents($dir . '/Use.xphp', <<<'PHP'
        <?php
        namespace App\ArrowCapture;
        $y = 1;
        $id = fn<T>(T $x): T => $x + $y;
        $y = 2;
        $result = $id::<int>(42);
        PHP);

        $this->compile($dir);

        $runScript = $dir . '/run.php';
        file_put_contents($runScript, <<<PHP
        <?php
        require '{$dir}/dist/Use.php';
        echo "result={\$result};y={\$y};";
        PHP);

        [$exit, $output] = $this->execScript($runScript);
        self::assertSame(0, $exit, "Run failed:\n" . implode("\n", $output));
        // Capture-at-declaration: $y inside the closure stayed at 1
        // even though the outer $y reassigned to 2 before the call.
        self::assertContains('result=43;y=2;', $output);

        $this->rrmdir(dirname($dir));
    }

    public function testArrowSpecializationEndToEndMultipleCaptures(): void
    {
        $dir = $this->mkdir('arrow-multi');
        file_put_contents($dir . '/Use.xphp', <<<'PHP'
        <?php
        namespace App\ArrowMulti;
        $a = 10;
        $b = 20;
        $f = fn<T>(T $x): T => $x + $a + $b;
        $result = $f::<int>(1);
        PHP);

        $this->compile($dir);
        $runScript = $dir . '/run.php';
        file_put_contents($runScript, <<<PHP
        <?php
        require '{$dir}/dist/Use.php';
        echo "result={\$result};";
        PHP);

        [$exit, $output] = $this->execScript($runScript);
        self::assertSame(0, $exit, "Run failed:\n" . implode("\n", $output));
        self::assertContains('result=31;', $output);

        $this->rrmdir(dirname($dir));
    }

    public function testArrowSpecializationEndToEndNoCaptures(): void
    {
        $dir = $this->mkdir('arrow-empty');
        file_put_contents($dir . '/Use.xphp', <<<'PHP'
        <?php
        namespace App\ArrowEmpty;
        $f = fn<T>(T $x): T => $x;
        $result = $f::<int>(42);
        PHP);

        $this->compile($dir);
        $runScript = $dir . '/run.php';
        file_put_contents($runScript, <<<PHP
        <?php
        require '{$dir}/dist/Use.php';
        echo "result={\$result};";
        PHP);

        [$exit, $output] = $this->execScript($runScript);
        self::assertSame(0, $exit, "Run failed:\n" . implode("\n", $output));
        self::assertContains('result=42;', $output);

        $this->rrmdir(dirname($dir));
    }

    public function testArrowSpecializationCaptureShadowingParamName(): void
    {
        // Param wins -- the outer $x = 99 is NOT captured because `x`
        // is in the arrow's param-set.
        $dir = $this->mkdir('arrow-shadow');
        file_put_contents($dir . '/Use.xphp', <<<'PHP'
        <?php
        namespace App\ArrowShadow;
        $x = 99;
        $f = fn<T>(T $x): T => $x;
        $result = $f::<int>(7);
        PHP);

        $this->compile($dir);
        $runScript = $dir . '/run.php';
        file_put_contents($runScript, <<<PHP
        <?php
        require '{$dir}/dist/Use.php';
        echo "result={\$result};";
        PHP);

        [$exit, $output] = $this->execScript($runScript);
        self::assertSame(0, $exit, "Run failed:\n" . implode("\n", $output));
        self::assertContains('result=7;', $output);

        $this->rrmdir(dirname($dir));
    }

    public function testArrowSpecializationMultipleArgTuples(): void
    {
        $dir = $this->mkdir('arrow-multitup');
        file_put_contents($dir . '/Use.xphp', <<<'PHP'
        <?php
        namespace App\ArrowMultiTup;
        $f = fn<T>(T $x): T => $x;
        $a = $f::<int>(10);
        $b = $f::<string>('hi');
        PHP);

        $this->compile($dir);
        $out = file_get_contents($dir . '/dist/Use.php');
        self::assertIsString($out);
        preg_match_all('/function closure_f_T_[0-9a-f]+\(/', $out, $matches);
        self::assertCount(2, $matches[0]);

        $runScript = $dir . '/run.php';
        file_put_contents($runScript, <<<PHP
        <?php
        require '{$dir}/dist/Use.php';
        echo "a={\$a};b={\$b};";
        PHP);

        [$exit, $output] = $this->execScript($runScript);
        self::assertSame(0, $exit, "Run failed:\n" . implode("\n", $output));
        self::assertContains('a=10;b=hi;', $output);

        $this->rrmdir(dirname($dir));
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

    public function testArrowSpecializationReservedArgsCaptureAlsoTriggersRename(): void
    {
        // Symmetric to the `__xphp_tag` test -- a capture named
        // `__xphp_args` collides with the dispatcher's variadic param.
        // Both the tag AND args params get renamed together.
        $dir = $this->mkdir('arrow-reserved-args');
        file_put_contents($dir . '/Use.xphp', <<<'PHP'
        <?php
        namespace App\ArrowReservedArgs;
        $__xphp_args = 200;
        $f = fn<T>(T $x): T => $x + $__xphp_args;
        $result = $f::<int>(3);
        PHP);

        $this->compile($dir);
        $runScript = $dir . '/run.php';
        file_put_contents($runScript, <<<PHP
        <?php
        require '{$dir}/dist/Use.php';
        echo "result={\$result};";
        PHP);

        [$exit, $output] = $this->execScript($runScript);
        self::assertSame(0, $exit, "Run failed:\n" . implode("\n", $output));
        self::assertContains('result=203;', $output);

        $out = file_get_contents($dir . '/dist/Use.php');
        self::assertMatchesRegularExpression(
            '/mixed \.\.\.\$__xphp_args_[0-9a-f]{8}/',
            $out,
        );

        $this->rrmdir(dirname($dir));
    }

    public function testArrowSpecializationReservedCaptureAutoRenamesDispatcherParam(): void
    {
        // Capture name `__xphp_tag` collides with the dispatcher's
        // tag param. The dispatcher should auto-rename its own tag
        // param to a collision-free alternative; the user's
        // `$__xphp_tag` keeps its name.
        $dir = $this->mkdir('arrow-reserved');
        file_put_contents($dir . '/Use.xphp', <<<'PHP'
        <?php
        namespace App\ArrowReserved;
        $__xphp_tag = 100;
        $f = fn<T>(T $x): T => $x + $__xphp_tag;
        $result = $f::<int>(5);
        PHP);

        $this->compile($dir);
        $runScript = $dir . '/run.php';
        file_put_contents($runScript, <<<PHP
        <?php
        require '{$dir}/dist/Use.php';
        echo "result={\$result};";
        PHP);

        [$exit, $output] = $this->execScript($runScript);
        self::assertSame(0, $exit, "Run failed:\n" . implode("\n", $output));
        self::assertContains('result=105;', $output);

        // The dispatcher's tag param was renamed (not the default).
        $out = file_get_contents($dir . '/dist/Use.php');
        self::assertMatchesRegularExpression(
            '/string \$__xphp_tag_[0-9a-f]{8}/',
            $out,
        );

        $this->rrmdir(dirname($dir));
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

    /**
     * @return array{0: int, 1: list<string>}
     */
    private function execScript(string $script): array
    {
        $output = [];
        $exit = 0;
        exec('php ' . escapeshellarg($script) . ' 2>&1', $output, $exit);
        return [$exit, $output];
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
