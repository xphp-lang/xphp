<?php

declare(strict_types=1);

namespace XPHP\Transpiler\Monomorphize;

use PhpParser\ParserFactory;
use PhpParser\PrettyPrinter\Standard as StandardPrinter;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use XPHP\Diagnostics\Diagnostic;
use XPHP\Diagnostics\DiagnosticCollector;
use XPHP\FileSystem\FileFinder\NativeFileFinder;
use XPHP\FileSystem\FileReader\NativeFileReader;
use XPHP\FileSystem\FileWriter\NativeFileWriter;
use XPHP\TestSupport\CompiledFixture;

/**
 * End-to-end coverage for type-argument inference (the optional turbofish): a bare generic call
 * whose type arguments are determined by its ordinary arguments is inferred and dispatched exactly
 * as an explicit `::<>` turbofish would be. Covers free-function, static-method, and instance-method
 * calls; that inference records the same instantiation an explicit turbofish records; that check and
 * compile agree; and that a call whose arguments do NOT determine the type still falls back to the
 * `xphp.missing_type_argument` error rather than silently emitting a broken call.
 */
final class GenericInferenceIntegrationTest extends TestCase
{
    private string $work;

    protected function setUp(): void
    {
        $this->work = sys_get_temp_dir() . '/xphp-inference-' . uniqid('', true);
        mkdir($this->work, 0o755, true);
    }

    protected function tearDown(): void
    {
        self::rrmdir($this->work);
    }

    private const LIB = <<<'PHP'
    <?php
    declare(strict_types=1);
    namespace App;
    class Plastic {}
    function identity<T>(T $x): T { return $x; }
    final class Factory { public static function make<T>(T $x): T { return $x; } }
    final class Bag { public function put<U>(U $x): U { return $x; } }
    PHP;

    #[RunInSeparateProcess]
    public function testInferredCallArgumentsRunAtRuntime(): void
    {
        // The non-negotiable gate: execute the emitted output. Every call site is bare; that the
        // program runs and returns the right values proves the turbofish-less calls inferred their
        // type arguments and dispatched to real specializations.
        $fixture = CompiledFixture::compile(
            __DIR__ . '/../../fixture/compile/inferred_call_arguments/source',
            'inference',
        );
        try {
            $fixture->registerAutoload('App');
            $runtime = require __DIR__ . '/../../fixture/compile/inferred_call_arguments/verify/runtime.php';
            $runtime($fixture);
        } finally {
            $fixture->cleanup();
        }
    }

    public function testFreeFunctionInfersFromLiteral(): void
    {
        $dist = $this->compile([
            'Lib.xphp' => self::LIB,
            'Use.xphp' => "<?php\ndeclare(strict_types=1);\nnamespace App;\n\$r = identity(5);\n",
        ]);
        // The bare call was specialized (mangled `identity_T_<…>`), not left as a bare `identity`.
        self::assertStringContainsString('identity_T_', self::read($dist, 'Use.php'));
    }

    public function testStaticMethodInfersFromLiteral(): void
    {
        $dist = $this->compile([
            'Lib.xphp' => self::LIB,
            'Use.xphp' => "<?php\ndeclare(strict_types=1);\nnamespace App;\n\$r = Factory::make('hi');\n",
        ]);
        self::assertStringContainsString('make_', self::read($dist, 'Use.php'));
    }

    public function testInstanceMethodInfersFromLiteral(): void
    {
        $dist = $this->compile([
            'Lib.xphp' => self::LIB,
            'Use.xphp' => "<?php\ndeclare(strict_types=1);\nnamespace App;\n\$b = new Bag();\n\$r = \$b->put(9);\n",
        ]);
        self::assertStringContainsString('put_', self::read($dist, 'Use.php'));
    }

    public function testInferenceProducesTheSameSpecializationAsATurbofish(): void
    {
        // The inferred call and the explicit-turbofish call must dispatch to the byte-identical
        // mangled specialization — inference just writes the turbofish for you.
        $inferred = self::read($this->compile([
            'Lib.xphp' => self::LIB,
            'Use.xphp' => "<?php\ndeclare(strict_types=1);\nnamespace App;\n\$r = identity(5);\n",
        ]), 'Use.php');
        $explicit = self::read($this->compile([
            'Lib.xphp' => self::LIB,
            'Use.xphp' => "<?php\ndeclare(strict_types=1);\nnamespace App;\n\$r = identity::<int>(5);\n",
        ]), 'Use.php');

        self::assertSame(1, preg_match('/identity_T_\w+/', $inferred, $inferredMatch));
        self::assertSame(1, preg_match('/identity_T_\w+/', $explicit, $explicitMatch));
        self::assertSame($explicitMatch[0], $inferredMatch[0]);
    }

    public function testCheckAcceptsAnInferableCall(): void
    {
        $collector = $this->check([
            'Lib.xphp' => self::LIB,
            'Use.xphp' => "<?php\ndeclare(strict_types=1);\nnamespace App;\n\$r = identity(5);\n",
        ]);
        self::assertSame([], $collector->all(), 'an inferable bare call must not be flagged');
    }

    public function testExplicitTurbofishStillCompiles(): void
    {
        // No regression: an explicit turbofish is unchanged by inference.
        $dist = $this->compile([
            'Lib.xphp' => self::LIB,
            'Use.xphp' => "<?php\ndeclare(strict_types=1);\nnamespace App;\n\$r = identity::<int>(5);\n",
        ]);
        self::assertStringContainsString('identity_T_', self::read($dist, 'Use.php'));
    }

    public function testFirstClassCallableIsNotInferred(): void
    {
        // `identity(...)` creates a Closure; it must not be inferred or flagged.
        $collector = $this->check([
            'Lib.xphp' => self::LIB,
            'Use.xphp' => "<?php\ndeclare(strict_types=1);\nnamespace App;\n\$cb = identity(...);\n",
        ]);
        self::assertSame([], $collector->all(), 'a first-class-callable must not be inferred or flagged');
    }

    public function testConflictingArgumentsFallBackToErrorInCheck(): void
    {
        // pair<T>(T $a, T $b) called (int, string): T is witnessed as two types → no inference →
        // today's missing-type-argument error.
        $collector = $this->check([
            'Lib.xphp' => "<?php\ndeclare(strict_types=1);\nnamespace App;\nfunction pair<T>(T \$a, T \$b): T { return \$a; }\n",
            'Use.xphp' => "<?php\ndeclare(strict_types=1);\nnamespace App;\n\$r = pair(1, 'x');\n",
        ]);
        $codes = array_map(static fn (Diagnostic $d): string => $d->code, $collector->all());
        self::assertContains(Registry::CODE_MISSING_TYPE_ARGUMENT, $codes);
    }

    public function testConflictingArgumentsFailCompile(): void
    {
        // Parity: the same conflict throws in compile mode.
        $this->expectException(RuntimeException::class);
        $this->compile([
            'Lib.xphp' => "<?php\ndeclare(strict_types=1);\nnamespace App;\nfunction pair<T>(T \$a, T \$b): T { return \$a; }\n",
            'Use.xphp' => "<?php\ndeclare(strict_types=1);\nnamespace App;\n\$r = pair(1, 'x');\n",
        ]);
    }

    // --- helpers (kept local, matching the other Monomorphize integration tests) ---------------

    /** @param array<string, string> $files */
    private function compile(array $files): string
    {
        $src = $this->writeSources($files);
        $dist = $src . '/dist';
        $this->newCompiler()->compile($this->sourcesIn($src), $src, $dist, $src . '/.xphp-cache');
        return $dist;
    }

    /** @param array<string, string> $files */
    private function check(array $files): DiagnosticCollector
    {
        $src = $this->writeSources($files);
        return $this->newCompiler()->check($this->sourcesIn($src));
    }

    /** @param array<string, string> $files */
    private function writeSources(array $files): string
    {
        $src = $this->work . '/' . uniqid('src', true);
        mkdir($src, 0o755, true);
        foreach ($files as $name => $contents) {
            file_put_contents($src . '/' . $name, $contents);
        }
        return $src;
    }

    private function sourcesIn(string $src): \XPHP\FileSystem\FilepathArray
    {
        return (new NativeFileFinder())->find($src)
            ->filter(static fn (string $f): bool => str_ends_with($f, '.xphp'));
    }

    private function newCompiler(): Compiler
    {
        $printer = new StandardPrinter();
        $writer = new NativeFileWriter();
        return new Compiler(
            new NativeFileReader(),
            $writer,
            new XphpSourceParser((new ParserFactory())->createForHostVersion()),
            new Specializer(),
            new SpecializedClassGenerator($printer, $writer),
            $printer,
        );
    }

    private static function read(string $dir, string $file): string
    {
        $path = $dir . '/' . $file;
        return is_file($path) ? (file_get_contents($path) ?: '') : '';
    }

    private static function rrmdir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            is_dir($path) ? self::rrmdir($path) : unlink($path);
        }
        rmdir($dir);
    }
}
