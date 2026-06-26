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
 * Exercises the fixed-point loop's cycle-detection by feeding it a pathologically
 * recursive template (Recursive<T> with a `Recursive<Recursive<T>>` property).
 *
 * Kills the `Identical` (`===` vs `!==`) and `Increment` (`$depth++` vs `$depth--`)
 * mutations in Compiler::compile's loop:
 *   - `Identical` flip: depth never increments because the inner `if ($countAfter !== $countBefore) continue`
 *     short-circuits past `$depth++` whenever new entries are added — the depth cap is never tripped,
 *     and the recursive fixture would loop forever (PHPUnit timeout / Infection timeout = kill).
 *   - `Increment` flip: `$depth--` makes the counter go negative, the `> MAX_SPECIALIZATION_DEPTH`
 *     check is never satisfied, and the loop runs forever — same kill mechanism.
 */
final class CompilerDepthCapTest extends TestCase
{
    private string $workDir;

    protected function setUp(): void
    {
        $this->workDir = sys_get_temp_dir() . '/xphp-depth-' . uniqid('', true);
        mkdir($this->workDir, 0o755, true);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->workDir)) {
            self::rrmdir($this->workDir);
        }
    }

    public function testRecursiveTemplateExceedsDepthCapAndThrows(): void
    {
        $sourceDir = realpath(__DIR__ . '/../../fixture/compile/depth_cap/source')
            ?: throw new RuntimeException('Fixture missing');

        $compiler = $this->buildCompiler();
        $sources = (new NativeFileFinder())
            ->find($sourceDir)
            ->filter(static fn (string $f): bool => str_ends_with($f, '.xphp'));

        $this->expectException(RuntimeException::class);
        // The localized diagnostic names the growing type family (here the recursive template) instead
        // of dumping the whole registry.
        $this->expectExceptionMessageMatches(
            '/Generic specialization did not converge \(exceeded depth \d+\): the type family rooted at '
            . '"App\\\\[^"]*Recursive"/',
        );
        $compiler->compile(
            $sources,
            $sourceDir,
            $this->workDir . '/dist',
            $this->workDir . '/.xphp-cache',
        );
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
