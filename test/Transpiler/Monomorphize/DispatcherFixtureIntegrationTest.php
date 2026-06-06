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
 * Fixture-based coverage of the four Phase 5 dispatcher consumers
 * (P5.4 capture-free, P5.5 arrow, P5.6 `use ()`, P5.7 defaults). The
 * inline-source unit tests in `ClosureDispatcherTest`,
 * `ArrowSpecializationTest`, `UseClosureSpecializationTest`, and
 * `ClosureArrowDefaultsTest` cover the same paths at a higher density;
 * this file pins the durable fixture artifacts so the emitted PHP
 * shape (dispatcher closure + match arms + lifted-param specializations)
 * stays observable in `test/fixture/`.
 */
final class DispatcherFixtureIntegrationTest extends TestCase
{
    private string $workDir;
    private string $targetDir;
    private string $cacheDir;

    protected function setUp(): void
    {
        $this->workDir = sys_get_temp_dir() . '/xphp-fixture-disp-' . uniqid('', true);
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

    public function testCaptureFreeFixtureRoutesViaDispatcher(): void
    {
        // Fixture: `closure_generic/`. The original P5.4 consumer --
        // a capture-free `function<K, V>(...)` with two duplicate-tuple
        // call sites that dedupe to a single specialization.
        $out = $this->compileFixture('closure_generic');

        self::assertMatchesRegularExpression(
            '/function closure_pair_T_[0-9a-f]+\(string \$key, int \$value\): array/',
            $out,
        );
        self::assertStringContainsString('__xphp_tag', $out);
        self::assertStringContainsString('match ($__xphp_tag)', $out);
        // Two same-tuple calls dedupe to one specialization.
        preg_match_all('/function closure_pair_T_[0-9a-f]+\(/', $out, $matches);
        self::assertCount(1, $matches[0]);
        // Both call sites carry the tag prefix.
        preg_match_all("/\\\$pair\\('T_[0-9a-f]+', /", $out, $callMatches);
        self::assertCount(2, $callMatches[0]);
    }

    public function testArrowFixtureSynthesizesUseClauseFromImplicitCapture(): void
    {
        // Fixture: `closure_dispatcher_arrow/`. The arrow body references
        // `$y` from the outer scope; the analyzer harvests it and the
        // dispatcher's `use ($y)` snapshots the value at the assign site.
        $out = $this->compileFixture('closure_dispatcher_arrow');

        // Dispatcher carries the synthesized `use ($y)` clause.
        self::assertMatchesRegularExpression(
            '/function \(string \$__xphp_tag, mixed \.\.\.\$__xphp_args\) use \(\$y\)/',
            $out,
        );
        // Specialized fn declares the lifted `mixed $y` trailing param.
        self::assertMatchesRegularExpression(
            '/function closure_id_T_[0-9a-f]+\(int \$x, mixed \$y\)/',
            $out,
        );

        // Runtime: the capture moment is the assign site, so the call
        // sees y=1 even though the outer y reassigns to 2 before it.
        $output = $this->execCompiled($this->workDir . '/dist/Use.php', 'echo "r={$resultArrow};y={$y};";');
        self::assertContains('r=43;y=2;', $output);
    }

    public function testUseClauseFixturePropagatesByRefThroughDispatcher(): void
    {
        // Fixture: `closure_dispatcher_use_clause/`. The `use (&$counter)`
        // by-ref capture must survive: dispatcher's `use (&$counter)`,
        // lifted param `mixed &$counter`, named-arg forwarding to the
        // specialized fn -- all preserve ref-ness so the outer $counter
        // mutates.
        $out = $this->compileFixture('closure_dispatcher_use_clause');

        // Dispatcher's `use` clause carries both the by-value $base AND
        // the by-ref &$counter.
        self::assertStringContainsString('use ($base, &$counter)', $out);
        // Specialized fns declare the lifted params with matching ref-ness.
        self::assertMatchesRegularExpression(
            '/function closure_f_T_[0-9a-f]+\([^)]+, mixed \$base, mixed &\$counter\)/',
            $out,
        );

        // Two distinct specializations (T=int and T=string).
        preg_match_all('/function closure_f_T_[0-9a-f]+\(/', $out, $matches);
        self::assertCount(2, $matches[0]);

        // Runtime: $counter mutates across the three calls; the int
        // specialization handles calls A + B; the string specialization
        // handles call C; both observe the live $counter.
        $output = $this->execCompiled(
            $this->workDir . '/dist/Use.php',
            'echo "A=" . implode(",", $callA) . ";B=" . implode(",", $callB) . ";C=" . implode(",", $callC) . ";counter={$counter};";',
        );
        self::assertContains('A=1,10,1;B=2,10,2;C=hi,10,3;counter=3;', $output);
    }

    public function testDefaultsFixturePadsEmptyTurbofish(): void
    {
        // Fixture: `closure_dispatcher_defaults/`. Empty-turbofish
        // `$f::<>()` calls trigger `Registry::padArgsWithDefaults` at
        // record time; the per-call-site tag stash ensures the runtime
        // tag matches the dispatcher arm built from the padded tuple.
        $out = $this->compileFixture('closure_dispatcher_defaults');

        // The empty turbofish `$f::<>(42)` and the explicit `$f::<string>('hi')`
        // produce two distinct specializations on $f.
        preg_match_all('/function closure_f_T_[0-9a-f]+\(/', $out, $fMatches);
        self::assertCount(2, $fMatches[0]);

        // The arrow $g specializes against the default (string).
        self::assertMatchesRegularExpression(
            '/function closure_g_T_[0-9a-f]+\(string \$x\): string/',
            $out,
        );

        // Runtime: each call routes to the right specialization and
        // returns the expected value.
        $output = $this->execCompiled(
            $this->workDir . '/dist/Use.php',
            'echo "c1={$resultPaddedClosure};c2={$resultExplicitClosure};a={$resultPaddedArrow};";',
        );
        self::assertContains('c1=#42;c2=#hi;a=world;', $output);
    }

    // ----- helpers ---------------------------------------------------------

    private function compileFixture(string $name): string
    {
        $sourceDir = realpath(__DIR__ . '/../../fixture/compile/' . $name . '/source')
            ?: throw new RuntimeException(sprintf('Fixture `%s` missing', $name));
        $compiler = $this->buildCompiler();
        $sources = (new NativeFileFinder())->find($sourceDir)
            ->filter(static fn (string $f): bool => str_ends_with($f, '.xphp'));
        $compiler->compile($sources, $sourceDir, $this->targetDir, $this->cacheDir);
        $out = file_get_contents($this->targetDir . '/Use.php');
        self::assertIsString($out, sprintf('compile produced no Use.php for fixture %s', $name));
        return $out;
    }

    /**
     * @return list<string>
     */
    private function execCompiled(string $compiledFile, string $printer): array
    {
        $runScript = $this->workDir . '/run.php';
        file_put_contents($runScript, sprintf(
            "<?php\nrequire %s;\n%s\n",
            var_export($compiledFile, true),
            $printer,
        ));
        $output = [];
        $exit = 0;
        exec('php ' . escapeshellarg($runScript) . ' 2>&1', $output, $exit);
        self::assertSame(0, $exit, "Run failed:\n" . implode("\n", $output));
        return $output;
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
