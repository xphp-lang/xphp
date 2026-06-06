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
 * End-to-end integration tests for the closure-dispatcher pipeline.
 * Exercises the full `Compiler::compile` path so the visitor's two-pass
 * collection + finalize phase actually fires.
 */
final class ClosureDispatcherIntegrationTest extends TestCase
{
    public function testCaptureFreeFixtureStillCompilesViaDispatcher(): void
    {
        // The pre-P5.4 single-call form: ONE tuple → dispatcher routes
        // ONE match arm. Specialized function present; call site rewritten
        // to invoke `$pair` with the tag prepended.
        $dir = $this->mkdir('disp-single');
        file_put_contents($dir . '/Use.xphp', <<<'PHP'
        <?php
        namespace App;
        $pair = function<K, V>(K $key, V $value): array {
            return [$key, $value];
        };
        $pair::<string, int>('age', 42);
        PHP);

        $this->compile($dir);
        $out = file_get_contents($dir . '/dist/Use.php');
        self::assertIsString($out);

        self::assertMatchesRegularExpression(
            '/function closure_pair_T_[0-9a-f]+\(string \$key, int \$value\): array/',
            $out,
        );
        // The specialization must land INSIDE `namespace App` so its FQN
        // matches what the dispatcher's match-arm calls (which uses the
        // fully-qualified `\App\closure_pair_T_<hash>`).
        self::assertMatchesRegularExpression(
            '/namespace App;[\s\S]*function closure_pair_T_/',
            $out,
            'specialization must live inside the namespace block, not at top level',
        );
        // The original `$pair('age', 42)` call form should no longer exist;
        // it's been rewritten with the tag prefix.
        self::assertStringNotContainsString("\$pair('age', 42)", $out);
        self::assertMatchesRegularExpression(
            "/\\\$pair\\('T_[0-9a-f]+', 'age', 42\\)/",
            $out,
        );
        // The original `function<K, V>` template's body is gone; the
        // Assign's RHS is now the dispatcher closure.
        self::assertStringNotContainsString('function<K, V>', $out);
        self::assertStringContainsString('__xphp_tag', $out);

        $this->rrmdir(dirname($dir));
    }

    public function testTwoTupleEndToEnd(): void
    {
        $dir = $this->mkdir('disp-two');
        file_put_contents($dir . '/Use.xphp', <<<'PHP'
        <?php
        namespace App;
        $pair = function<K, V>(K $key, V $value): array {
            return [$key, $value];
        };
        $pair::<string, int>('age', 42);
        $pair::<int, string>(7, 'lucky');
        PHP);

        $this->compile($dir);
        $out = file_get_contents($dir . '/dist/Use.php');
        self::assertIsString($out);

        // Two specialized declarations -- different hashes for (string,int)
        // and (int,string).
        preg_match_all('/function closure_pair_T_[0-9a-f]+\(/', $out, $matches);
        self::assertCount(2, $matches[0]);

        // Dispatcher has exactly two non-default arms.
        preg_match_all("/'T_[0-9a-f]+' => /", $out, $armMatches);
        self::assertCount(2, $armMatches[0]);

        // Both call sites carry their respective tags.
        self::assertMatchesRegularExpression(
            "/\\\$pair\\('T_[0-9a-f]+', 'age', 42\\)/",
            $out,
        );
        self::assertMatchesRegularExpression(
            "/\\\$pair\\('T_[0-9a-f]+', 7, 'lucky'\\)/",
            $out,
        );

        $this->rrmdir(dirname($dir));
    }

    public function testDuplicateCallSitesShareSingleSpecialization(): void
    {
        // Two calls with the same arg-tuple → one specialization, one
        // match arm.
        $dir = $this->mkdir('disp-dup');
        file_put_contents($dir . '/Use.xphp', <<<'PHP'
        <?php
        namespace App;
        $pair = function<K, V>(K $key, V $value): array {
            return [$key, $value];
        };
        $pair::<string, int>('age', 42);
        $pair::<string, int>('count', 7);
        PHP);

        $this->compile($dir);
        $out = file_get_contents($dir . '/dist/Use.php');
        self::assertIsString($out);

        preg_match_all('/function closure_pair_T_[0-9a-f]+\(/', $out, $matches);
        self::assertCount(1, $matches[0], 'one specialization despite two call sites');

        preg_match_all("/'T_[0-9a-f]+' => /", $out, $armMatches);
        self::assertCount(1, $armMatches[0]);

        $this->rrmdir(dirname($dir));
    }

    public function testRuntimeRoutingThroughDispatcher(): void
    {
        // Full runtime exec: compile, then execute the resulting PHP and
        // observe the routed return values.
        $dir = $this->mkdir('disp-runtime');
        file_put_contents($dir . '/Use.xphp', <<<'PHP'
        <?php
        namespace App\DispRun;
        $id = function<T>(T $x): T {
            return $x;
        };
        $a = $id::<int>(42);
        $b = $id::<string>('hello');
        PHP);

        $this->compile($dir);

        $runScript = $dir . '/run.php';
        file_put_contents($runScript, <<<PHP
        <?php
        require '{$dir}/dist/Use.php';
        echo "a={\$a};b={\$b};";
        PHP);

        $output = [];
        $exit = 0;
        exec('php ' . escapeshellarg($runScript) . ' 2>&1', $output, $exit);
        self::assertSame(0, $exit, "Run failed:\n" . implode("\n", $output));
        self::assertContains('a=42;b=hello;', $output);

        $this->rrmdir(dirname($dir));
    }

    public function testUnknownTagAtRuntimeThrows(): void
    {
        // Compile + run, then call the dispatcher with a bogus tag.
        $dir = $this->mkdir('disp-bogus');
        file_put_contents($dir . '/Use.xphp', <<<'PHP'
        <?php
        namespace App\DispBogus;
        $id = function<T>(T $x): T { return $x; };
        $id::<int>(1);
        PHP);

        $this->compile($dir);

        $runScript = $dir . '/run.php';
        file_put_contents($runScript, <<<PHP
        <?php
        require '{$dir}/dist/Use.php';
        try {
            \$id('T_bogus', 99);
            echo 'noexc';
        } catch (\\RuntimeException \$e) {
            echo 'caught:' . \$e->getMessage();
        }
        PHP);

        $output = [];
        $exit = 0;
        exec('php ' . escapeshellarg($runScript) . ' 2>&1', $output, $exit);
        self::assertSame(0, $exit, "Run failed:\n" . implode("\n", $output));
        self::assertContains('caught:Unknown generic specialization tag: T_bogus', $output);

        $this->rrmdir(dirname($dir));
    }

    public function testArrowSpecializesViaDispatcher(): void
    {
        // P5.5: arrow rejection lifted. Capture-free arrow specializes
        // through the same dispatcher path as capture-free closures.
        $dir = $this->mkdir('disp-arrow');
        file_put_contents($dir . '/Use.xphp', <<<'PHP'
        <?php
        namespace App;
        $id = fn<T>(T $x): T => $x;
        $id::<int>(1);
        PHP);

        $this->compile($dir);
        $out = file_get_contents($dir . '/dist/Use.php');
        self::assertIsString($out);
        self::assertStringContainsString('closure_id_T_', $out);
        self::assertStringContainsString('__xphp_tag', $out);
        $this->rrmdir(dirname($dir));
    }

    public function testUseClauseClosureSpecializesViaDispatcher(): void
    {
        // P5.6: closure-with-`use` rejection lifted. The dispatcher
        // forwards the user's `use (...)` clause onto itself and lifts
        // each capture as a trailing `mixed` param on the specialized
        // function.
        $dir = $this->mkdir('disp-use');
        file_put_contents($dir . '/Use.xphp', <<<'PHP'
        <?php
        namespace App;
        $y = 1;
        $f = function<T>(T $x) use ($y) { return [$x, $y]; };
        $f::<int>(42);
        PHP);

        $this->compile($dir);
        $out = file_get_contents($dir . '/dist/Use.php');
        self::assertIsString($out);
        self::assertStringContainsString('closure_f_T_', $out);
        self::assertStringContainsString('use ($y)', $out);
        $this->rrmdir(dirname($dir));
    }

    public function testEmptyArgSetsLeavesOriginalAssignUntouched(): void
    {
        // Template declared but never called via turbofish. The Assign
        // RHS stays as the original closure body; no dispatcher emitted.
        $dir = $this->mkdir('disp-empty');
        file_put_contents($dir . '/Use.xphp', <<<'PHP'
        <?php
        namespace App;
        $id = function<T>(T $x): T { return $x; };
        PHP);

        $this->compile($dir);
        $out = file_get_contents($dir . '/dist/Use.php');
        self::assertIsString($out);
        // No dispatcher tag-parameter; no specialized function emitted.
        self::assertStringNotContainsString('__xphp_tag', $out);
        self::assertStringNotContainsString('closure_id_T_', $out);
        // The original closure (after generic-param strip) survives.
        self::assertStringContainsString('return $x;', $out);

        $this->rrmdir(dirname($dir));
    }

    public function testNestedScopeCallsShareDispatcher(): void
    {
        // The SAME `$pair` template is called twice: once in main scope,
        // once inside an inner anonymous closure body. Both call sites
        // must land in the SAME dispatch-plan entry (keyed by the
        // template's startFilePos), so we end up with ONE dispatcher
        // and ONE match arm.
        //
        // The inner closure has no turbofish on its own params (it's a
        // plain Closure that uses `use ($pair)` to import the outer
        // dispatcher), so the visitor's `currentScopeClosureTemplates`
        // lookup of `$pair` walks through to the imported template.
        // This pins that we don't accidentally create a second
        // dispatch-plan entry for the inner-scope call.
        $dir = $this->mkdir('disp-nested');
        file_put_contents($dir . '/Use.xphp', <<<'PHP'
        <?php
        namespace App\NestedDisp;
        $id = function<T>(T $x): T { return $x; };
        $id::<int>(1);
        $id::<int>(2);
        PHP);

        $this->compile($dir);
        $out = file_get_contents($dir . '/dist/Use.php');
        self::assertIsString($out);

        // Single specialization shared by both calls.
        preg_match_all('/function closure_id_T_[0-9a-f]+\(/', $out, $matches);
        self::assertCount(1, $matches[0]);

        $this->rrmdir(dirname($dir));
    }

    // ----- helpers ---------------------------------------------------------

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
        // The compile writes to ../dist; the convenience accessor below
        // wants $sourceDir/dist, so symlink there for the tests.
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
