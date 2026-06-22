<?php

declare(strict_types=1);

namespace XPHP\Console\Command;

use PhpParser\ParserFactory;
use PhpParser\PrettyPrinter\Standard as StandardPrinter;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use XPHP\Config\ManifestResolver;
use XPHP\Config\SourceResolver;
use XPHP\FileSystem\FileFinder\NativeFileFinder;
use XPHP\FileSystem\FileReader\NativeFileReader;
use XPHP\FileSystem\FileWriter\NativeFileWriter;
use XPHP\Transpiler\Monomorphize\Compiler;
use XPHP\Transpiler\Monomorphize\SpecializedClassGenerator;
use XPHP\Transpiler\Monomorphize\Specializer;
use XPHP\Transpiler\Monomorphize\XphpSourceParser;

final class CompileCommandTest extends TestCase
{
    private string $work;

    protected function setUp(): void
    {
        $this->work = sys_get_temp_dir() . '/xphp-compile-cmd-' . uniqid('', true);
        mkdir($this->work, 0o755, true);
    }

    protected function tearDown(): void
    {
        self::rrmdir($this->work);
    }

    public function testSingleDirPositionalFormStillWorks(): void
    {
        mkdir($this->work . '/src', 0o755, true);
        file_put_contents($this->work . '/src/Plain.xphp', "<?php\nnamespace App;\nclass Plain {}\n");

        $tester = $this->tester();
        $exit = $tester->execute([
            'source' => $this->work . '/src',
            'target' => $this->work . '/dist',
            'cache' => $this->work . '/cache',
        ]);

        self::assertSame(Command::SUCCESS, $exit);
        self::assertStringContainsString('Compiled', $tester->getDisplay());
        self::assertFileExists($this->work . '/dist/Plain.php');
        // positional cache arg honoured (registry lands there).
        self::assertFileExists($this->work . '/cache/registry.json');
    }

    public function testSingleDirTargetCacheOptionsOverridePositional(): void
    {
        mkdir($this->work . '/src', 0o755, true);
        file_put_contents($this->work . '/src/Plain.xphp', "<?php\nnamespace App;\nclass Plain {}\n");

        $exit = $this->tester()->execute([
            'source' => $this->work . '/src',
            'target' => $this->work . '/posdist',
            'cache' => $this->work . '/poscache',
            '--target' => $this->work . '/optdist',
            '--cache' => $this->work . '/optcache',
        ]);

        self::assertSame(Command::SUCCESS, $exit);
        self::assertFileExists($this->work . '/optdist/Plain.php', '--target option wins over positional');
        self::assertFileDoesNotExist($this->work . '/posdist/Plain.php');
        self::assertFileExists($this->work . '/optcache/registry.json', '--cache option wins over positional');
        self::assertFileDoesNotExist($this->work . '/poscache/registry.json');
    }

    public function testCrossPackageConsumeViaConfigEmitsUpstreamAndRunsWithoutFatal(): void
    {
        // Upstream ships a generic Box<T> as .xphp source + its manifest.
        $this->writePackage('lib', '{"sources":["src"]}', [
            'src/Box.xphp' => "<?php\nnamespace App;\nclass Box<T> { public function __construct(public T \$v) {} }\n",
        ]);
        // Downstream includes it and instantiates Box::<int>, with build dirs in the manifest.
        $this->writePackage('app', '{"sources":["src"],"include":["../lib"],"target":"dist","cache":"cache"}', [
            'src/Use.xphp' => "<?php\nnamespace App;\n\$b = new Box::<int>(1);\n",
        ]);

        $exit = $this->tester()->execute(['--config' => $this->work . '/app']);
        self::assertSame(Command::SUCCESS, $exit);

        // Emit-all: the UPSTREAM marker is emitted into the consumer's output (not skipped) ...
        self::assertFileExists($this->work . '/app/dist/Box.php', 'upstream Box marker must be emitted');
        self::assertFileExists($this->work . '/app/dist/Use.php');
        // ... and the consumer-driven specialization exists in the cache.
        $specs = glob($this->work . '/app/cache/Generated/App/Box/T_*.php') ?: [];
        self::assertCount(1, $specs, 'one Box<int> specialization');

        // And the compiled output loads + runs with NO "interface not found" / fatal.
        self::assertSame('OK', $this->runCompiledOutput($this->work . '/app/dist', $this->work . '/app/cache'));
    }

    public function testGlobDiscoveryViaConfig(): void
    {
        $this->writePackage('app', '{"sources":["src"],"include":["vendor/*/*"]}', [
            'src/Use.xphp' => "<?php\nnamespace App;\nclass App {}\n",
        ]);
        $this->writePackage('app/vendor/org/pkg', '{"sources":["src"]}', [
            'src/Dep.xphp' => "<?php\nnamespace Dep;\nclass Dep {}\n",
        ]);
        // A non-xphp vendor dir — must be skipped silently.
        mkdir($this->work . '/app/vendor/org/plain', 0o755, true);

        $exit = $this->tester()->execute(['--config' => $this->work . '/app', '--target' => $this->work . '/out', '--cache' => $this->work . '/c']);

        self::assertSame(Command::SUCCESS, $exit);
        self::assertFileExists($this->work . '/out/Use.php');
        self::assertFileExists($this->work . '/out/Dep.php', 'discovered vendor package compiled');
    }

    public function testManifestTargetCacheUsedAndOptionOverrides(): void
    {
        // Non-default dir names ("build"/"artifacts") so the precedence is distinguishable from the
        // 'dist'/'.xphp-cache' fallbacks.
        $this->writePackage('app', '{"sources":["src"],"target":"build","cache":"artifacts"}', [
            'src/A.xphp' => "<?php\nnamespace App;\nclass A {}\n",
        ]);

        // Manifest target/cache are used when no option is given (and not the 'dist'/'.xphp-cache' defaults).
        $this->tester()->execute(['--config' => $this->work . '/app']);
        self::assertFileExists($this->work . '/app/build/A.php');
        self::assertFileExists($this->work . '/app/artifacts/registry.json');
        self::assertFileDoesNotExist($this->work . '/app/dist/A.php');

        // --target / --cache options override the manifest values.
        $this->tester()->execute([
            '--config' => $this->work . '/app',
            '--target' => $this->work . '/over-dist',
            '--cache' => $this->work . '/over-cache',
        ]);
        self::assertFileExists($this->work . '/over-dist/A.php');
        self::assertFileExists($this->work . '/over-cache/registry.json');
    }

    public function testAutodetectsXphpJsonInWorkingDirectory(): void
    {
        $this->writePackage('app', '{"sources":["src"],"target":"dist","cache":"cache"}', [
            'src/A.xphp' => "<?php\nnamespace App;\nclass A {}\n",
        ]);

        $prev = getcwd();
        chdir($this->work . '/app');
        try {
            $exit = $this->tester()->execute([]); // no source, no --config → auto-detect cwd/xphp.json
        } finally {
            chdir($prev !== false ? $prev : '/');
        }

        self::assertSame(Command::SUCCESS, $exit);
        self::assertFileExists($this->work . '/app/dist/A.php');
    }

    public function testConfigOptionTakesPrecedenceOverAutodetectedManifest(): void
    {
        // cwd holds an xphp.json (the auto-detect candidate) → srcA; --config points elsewhere → srcB.
        $this->writePackage('app', json_encode(['sources' => ['srcA'], 'target' => $this->work . '/distA', 'cache' => $this->work . '/cacheA']) ?: '{}', [
            'srcA/A.xphp' => "<?php\nnamespace App;\nclass A {}\n",
            'srcB/B.xphp' => "<?php\nnamespace App;\nclass B {}\n",
        ]);
        file_put_contents(
            $this->work . '/app/other.json',
            json_encode(['sources' => ['srcB'], 'target' => $this->work . '/distB', 'cache' => $this->work . '/cacheB']) ?: '{}',
        );

        $prev = getcwd();
        chdir($this->work . '/app');
        try {
            $exit = $this->tester()->execute(['--config' => $this->work . '/app/other.json']);
        } finally {
            chdir($prev !== false ? $prev : '/');
        }

        self::assertSame(Command::SUCCESS, $exit);
        self::assertFileExists($this->work . '/distB/B.php', '--config manifest is used');
        self::assertFileDoesNotExist($this->work . '/distA/A.php', 'auto-detected manifest is NOT used when --config is given');
    }

    public function testEmptyTargetOptionFallsThroughToManifest(): void
    {
        // An empty --target is treated as absent (stringOrNull), so the manifest value wins —
        // pins that `stringOrNull` requires BOTH is_string AND non-empty.
        $this->writePackage('app', '{"sources":["src"],"target":"build","cache":"artifacts"}', [
            'src/A.xphp' => "<?php\nnamespace App;\nclass A {}\n",
        ]);

        $exit = $this->tester()->execute(['--config' => $this->work . '/app', '--target' => '']);

        self::assertSame(Command::SUCCESS, $exit);
        self::assertFileExists($this->work . '/app/build/A.php', 'empty --target ignored; manifest target used');
    }

    public function testNoSourceProviderIsAClearFailure(): void
    {
        $tester = $this->tester();
        $exit = $tester->execute([]);

        self::assertSame(Command::FAILURE, $exit);
        self::assertStringContainsString('No sources to compile', $tester->getDisplay());
    }

    public function testMissingSingleDirIsAClearFailure(): void
    {
        $tester = $this->tester();
        $exit = $tester->execute(['source' => $this->work . '/ghost']);

        self::assertSame(Command::FAILURE, $exit);
        self::assertStringContainsString('Source directory not found', $tester->getDisplay());
    }

    // --- helpers ---

    private function tester(): CommandTester
    {
        $phpParser = (new ParserFactory())->createForHostVersion();
        $printer = new StandardPrinter();
        $writer = new NativeFileWriter();
        $compiler = new Compiler(
            new NativeFileReader(),
            $writer,
            new XphpSourceParser($phpParser),
            new Specializer(),
            new SpecializedClassGenerator($printer, $writer),
            $printer,
        );
        $sourceResolver = new SourceResolver(
            new NativeFileFinder(),
            new ManifestResolver(new NativeFileReader(), new NativeFileFinder()),
        );

        return new CommandTester(new CompileCommand($sourceResolver, $compiler));
    }

    /** @param array<string,string> $files relative path => content */
    private function writePackage(string $rel, string $manifest, array $files): void
    {
        $dir = $this->work . '/' . $rel;
        mkdir($dir, 0o755, true);
        file_put_contents($dir . '/xphp.json', $manifest);
        foreach ($files as $path => $content) {
            $full = $dir . '/' . $path;
            if (!is_dir(dirname($full))) {
                mkdir(dirname($full), 0o755, true);
            }
            file_put_contents($full, $content);
        }
    }

    /** Load the compiled output (target + generated cache) in a subprocess and require it; "OK" on no fatal. */
    private function runCompiledOutput(string $target, string $cache): string
    {
        $loader = $this->work . '/load.php';
        $prefixes = [
            'XPHP\\Generated\\' => $cache . '/Generated',
            'App\\' => $target,
        ];
        $script = "<?php\n"
            . 'spl_autoload_register(function (string $c): void {' . "\n"
            . '    foreach (' . var_export($prefixes, true) . ' as $p => $base) {' . "\n"
            . '        if (str_starts_with($c, $p)) {' . "\n"
            . '            $f = $base . "/" . str_replace("\\\\", "/", substr($c, strlen($p))) . ".php";' . "\n"
            . '            if (is_file($f)) { require_once $f; }' . "\n"
            . '        }' . "\n"
            . '    }' . "\n"
            . "});\n"
            . 'require ' . var_export($target . '/Use.php', true) . ";\n"
            . "echo \"OK\\n\";\n";
        file_put_contents($loader, $script);

        $out = [];
        $code = 0;
        exec('php ' . escapeshellarg($loader) . ' 2>&1', $out, $code);

        return $code === 0 && in_array('OK', $out, true) ? 'OK' : implode("\n", $out);
    }

    private static function rrmdir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $e) {
            if ($e === '.' || $e === '..') {
                continue;
            }
            $p = $dir . '/' . $e;
            is_dir($p) ? self::rrmdir($p) : unlink($p);
        }
        rmdir($dir);
    }
}
