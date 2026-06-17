<?php

declare(strict_types=1);

namespace XPHP\Transpiler\Monomorphize;

use PhpParser\ParserFactory;
use PhpParser\PrettyPrinter\Standard as StandardPrinter;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use XPHP\Diagnostics\DiagnosticCollector;
use XPHP\FileSystem\FileFinder\NativeFileFinder;
use XPHP\FileSystem\FilepathArray;
use XPHP\FileSystem\FileReader\NativeFileReader;
use XPHP\FileSystem\FileWriter\NativeFileWriter;

/**
 * End-to-end of the validate-only `Compiler::check()` path: it collects every generic
 * error in one run and halts after validation (never specializes or emits), so a
 * bound-violating source returns diagnostics instead of throwing.
 */
final class CheckPassIntegrationTest extends TestCase
{
    public function testMultipleErrorsAreCollectedInOneRun(): void
    {
        $diagnostics = $this->check('multi_error');

        self::assertTrue($diagnostics->hasErrors());
        self::assertCount(2, $diagnostics->all());
        foreach ($diagnostics->all() as $d) {
            self::assertSame(Registry::CODE_BOUND_VIOLATION, $d->code);
            self::assertNotNull($d->location);
            self::assertStringEndsWith('Use.xphp', $d->location->file);
        }
    }

    public function testCleanSourcesProduceNoDiagnostics(): void
    {
        $diagnostics = $this->check('clean');

        self::assertFalse($diagnostics->hasErrors());
        self::assertSame([], $diagnostics->all());
    }

    public function testDefaultBoundViolationIsCollectedByCheck(): void
    {
        // Exercises the validateDefaultsAgainstBounds() step of check().
        $diagnostics = $this->check('default_violation');

        self::assertCount(1, $diagnostics->all());
        self::assertSame(Registry::CODE_DEFAULT_BOUND_VIOLATION, $diagnostics->all()[0]->code);
    }

    public function testVariancePositionViolationIsCollectedByCheck(): void
    {
        // Exercises the validateVariancePositions() step of check().
        $diagnostics = $this->check('variance_violation');

        self::assertCount(1, $diagnostics->all());
        self::assertSame(VariancePositionValidator::CODE_VARIANCE_POSITION, $diagnostics->all()[0]->code);
    }

    public function testCompileStillThrowsOnVariancePositionViolation(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('not allowed for covariant variance');

        $work = sys_get_temp_dir() . '/xphp-check-compile-' . uniqid('', true);
        mkdir($work, 0o755, true);
        try {
            $this->buildCompiler()->compile($this->sources('variance_violation'), $this->sourceDir('variance_violation'), $work . '/dist', $work . '/cache');
        } finally {
            self::rrmdir($work);
        }
    }

    public function testInnerVarianceViolationIsCollectedByCheck(): void
    {
        // Composition case the position check misses → only inner-variance reports it,
        // and the position check does NOT also flag it (no double report).
        $diagnostics = $this->check('inner_variance');

        self::assertCount(1, $diagnostics->all());
        $d = $diagnostics->all()[0];
        self::assertSame(InnerVarianceValidator::CODE_INNER_VARIANCE, $d->code);
        // Located at the `Container<T>` return type in P.xphp (line 12).
        self::assertNotNull($d->location);
        self::assertStringEndsWith('P.xphp', $d->location->file);
        self::assertSame(12, $d->location->line);
    }

    public function testMissingTypeArgumentIsCollectedByCheck(): void
    {
        $diagnostics = $this->check('missing_arg');

        self::assertCount(1, $diagnostics->all());
        self::assertSame(Registry::CODE_MISSING_TYPE_ARGUMENT, $diagnostics->all()[0]->code);
        self::assertNotNull($diagnostics->all()[0]->location);
    }

    public function testUndefinedTemplateIsCollectedByCheck(): void
    {
        // Exercises the collectUndefinedTemplates() step of check().
        $diagnostics = $this->check('undefined_template');

        self::assertCount(1, $diagnostics->all());
        self::assertSame(Registry::CODE_UNDEFINED_TEMPLATE, $diagnostics->all()[0]->code);
    }

    public function testCompileStillThrowsOnUndefinedTemplate(): void
    {
        // The same condition is a hard error in compile-mode (no collector) — confirms the
        // shared message builder keeps the throw path intact.
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('was instantiated but never defined');

        $work = sys_get_temp_dir() . '/xphp-check-compile-' . uniqid('', true);
        mkdir($work, 0o755, true);
        try {
            $this->buildCompiler()->compile($this->sources('undefined_template'), $this->sourceDir('undefined_template'), $work . '/dist', $work . '/cache');
        } finally {
            self::rrmdir($work);
        }
    }

    private function check(string $fixture): DiagnosticCollector
    {
        return $this->buildCompiler()->check($this->sources($fixture));
    }

    private function sources(string $fixture): FilepathArray
    {
        return (new NativeFileFinder())
            ->find($this->sourceDir($fixture))
            ->filter(static fn (string $f): bool => str_ends_with($f, '.xphp'));
    }

    private function sourceDir(string $fixture): string
    {
        return realpath(__DIR__ . '/../../fixture/check/' . $fixture . '/source')
            ?: throw new RuntimeException("Fixture missing: {$fixture}");
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
}
