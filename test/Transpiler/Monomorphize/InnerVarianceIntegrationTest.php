<?php

declare(strict_types=1);

namespace XPHP\Transpiler\Monomorphize;

use PhpParser\ParserFactory;
use PhpParser\PrettyPrinter\Standard as StandardPrinter;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use XPHP\FileSystem\FilepathArray;
use XPHP\FileSystem\FileReader\NativeFileReader;
use XPHP\FileSystem\FileWriter\NativeFileWriter;

/**
 * Integration coverage for the inner-template variance pass AS WIRED INTO
 * {@see Compiler::compile} (the `$registry->validateInnerVariance()` call).
 *
 * {@see RegistryInnerVarianceTest} already exercises the validator white-box
 * by calling `validateInnerVariance()` directly, so removing the call from
 * the compile pipeline escapes those unit tests. This test drives the FULL
 * pipeline on real `.xphp` source, so the violation surfaces only if the pass
 * actually runs during `compile()`.
 */
final class InnerVarianceIntegrationTest extends TestCase
{
    private string $workDir;
    private string $targetDir;
    private string $cacheDir;

    protected function setUp(): void
    {
        $this->workDir = sys_get_temp_dir() . '/xphp-inner-variance-' . uniqid('', true);
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

    public function testCovariantOuterInInvariantInnerSlotFailsCompilation(): void
    {
        // The canonical case the parse-time validator CANNOT catch: at parse
        // time we don't yet know Container's slot is invariant, so `+T` in a
        // covariant (return) position passes the surface check. The inner-
        // variance pass composes compose(Covariant, Invariant) = Invariant and
        // rejects `+T`. If the `validateInnerVariance()` call is removed from
        // Compiler::compile, the violation slips through and compilation
        // succeeds -- so this test fails, killing the MethodCallRemoval mutant.
        $sourceDir = $this->workDir . '/src';
        mkdir($sourceDir, 0o755, true);

        $containerFile = $sourceDir . '/Container.xphp';
        file_put_contents($containerFile, <<<'PHP'
        <?php
        namespace App;
        // X has no variance marker -> invariant slot.
        class Container<X>
        {
            public function __construct(public X $item) {}
        }
        PHP);

        $pFile = $sourceDir . '/P.xphp';
        file_put_contents($pFile, <<<'PHP'
        <?php
        namespace App;
        // +T appears only in output position (return), which the parse-time
        // validator accepts -- but Container's slot is invariant, so the
        // composed position is invariant-only and +T is illegal there.
        class P<out T>
        {
            private mixed $store = null;

            public function f(): Container<T>
            {
                return $this->store;
            }
        }
        PHP);

        $compiler = $this->buildCompiler();
        $sources = new FilepathArray($containerFile, $pFile);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Variance violation in template P');
        $this->expectExceptionMessage('+T');
        $this->expectExceptionMessage('invariant-only position');
        $this->expectExceptionMessage('Container');
        $compiler->compile($sources, $sourceDir, $this->targetDir, $this->cacheDir);
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
