<?php

declare(strict_types=1);

namespace XPHP\StaticAnalysis;

use PhpParser\ParserFactory;
use PhpParser\PrettyPrinter\Standard as StandardPrinter;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use XPHP\FileSystem\FileFinder\NativeFileFinder;
use XPHP\FileSystem\FileReader\NativeFileReader;
use XPHP\FileSystem\FileWriter\NativeFileWriter;
use XPHP\Transpiler\Monomorphize\Compiler;
use XPHP\Transpiler\Monomorphize\SpecializedClassGenerator;
use XPHP\Transpiler\Monomorphize\Specializer;
use XPHP\Transpiler\Monomorphize\XphpSourceParser;

final class RepresentativeSelectorTest extends TestCase
{
    public function testOneRepresentativePerTemplateAcrossMultipleTemplates(): void
    {
        // multi_type declares two templates (Pair, Map); Pair is instantiated
        // several times. Expect exactly one representative per template (2 total).
        $this->withCompiled('compile/multi_type', function (CompiledWorkspace $w): void {
            $reps = RepresentativeSelector::select($w->registry, $w->generatedDir);

            self::assertCount(2, $reps);
            $templates = array_map(static fn (Representative $r): string => $r->templateFqn, $reps);
            self::assertContains('App\\MultiType\\Containers\\Pair', $templates);
            self::assertContains('App\\MultiType\\Containers\\Map', $templates);

            foreach ($reps as $rep) {
                self::assertFileExists($rep->filePath, "representative file should exist: {$rep->filePath}");
                self::assertStringStartsWith($w->generatedDir, $rep->filePath);
                self::assertGreaterThan(0, $rep->declLine);
                self::assertStringContainsString('<', $rep->label);
            }
        });
    }

    public function testSelectionIsDeterministic(): void
    {
        $this->withCompiled('compile/multi_type', function (CompiledWorkspace $w): void {
            $first = RepresentativeSelector::select($w->registry, $w->generatedDir);
            $second = RepresentativeSelector::select($w->registry, $w->generatedDir);

            $fqns = static fn (array $reps): array
                => array_map(static fn (Representative $r): string => $r->generatedFqn, $reps);

            // Stable order, and each pick is the lexicographically smallest generatedFqn.
            self::assertSame($fqns($first), $fqns($second));
            $sorted = $fqns($first);
            $resorted = $sorted;
            sort($resorted);
            self::assertSame($resorted, $sorted, 'representatives must be sorted by generatedFqn');
        });
    }

    public function testEachTemplatePicksItsLexicographicallySmallestSpecialization(): void
    {
        $this->withCompiled('compile/multi_type', function (CompiledWorkspace $w): void {
            // The expected pick per template: min generatedFqn among its instantiations.
            $minByTemplate = [];
            foreach ($w->registry->instantiations() as $inst) {
                $current = $minByTemplate[$inst->templateFqn] ?? null;
                if ($current === null || strcmp($inst->generatedFqn, $current) < 0) {
                    $minByTemplate[$inst->templateFqn] = $inst->generatedFqn;
                }
            }

            foreach (RepresentativeSelector::select($w->registry, $w->generatedDir) as $rep) {
                self::assertSame(
                    $minByTemplate[$rep->templateFqn],
                    $rep->generatedFqn,
                    "template {$rep->templateFqn} should be represented by its smallest generatedFqn",
                );
            }
        });
    }

    public function testFilePathNormalisesTrailingSlashOnGeneratedDir(): void
    {
        $this->withCompiled('check/body_type_error', function (CompiledWorkspace $w): void {
            $reps = RepresentativeSelector::select($w->registry, $w->generatedDir . '/');

            self::assertStringNotContainsString('//', substr($reps[0]->filePath, 1));
            self::assertFileExists($reps[0]->filePath);
        });
    }

    public function testRepresentativeMapsToTemplateDeclaration(): void
    {
        $this->withCompiled('check/body_type_error', function (CompiledWorkspace $w): void {
            $reps = RepresentativeSelector::select($w->registry, $w->generatedDir);

            self::assertCount(1, $reps);
            $box = $reps[0];
            self::assertSame('App\\Check\\BodyTypeError\\Box', $box->templateFqn);
            self::assertSame('App\\Check\\BodyTypeError\\Box<int>', $box->label);
            self::assertStringEndsWith('Box.xphp', $box->declFile);
            self::assertSame(7, $box->declLine); // `class Box<T>` line
        });
    }

    /** @param callable(CompiledWorkspace): void $assertions */
    private function withCompiled(string $fixture, callable $assertions): void
    {
        $sourceDir = realpath(__DIR__ . '/../fixture/' . $fixture . '/source')
            ?: throw new RuntimeException("fixture missing: {$fixture}");
        $sources = (new NativeFileFinder())
            ->find($sourceDir)
            ->filter(static fn (string $f): bool => str_ends_with($f, '.xphp'));

        $workspace = CompiledWorkspace::compile(
            $this->compiler(),
            $sources,
            $sourceDir,
            sys_get_temp_dir() . '/xphp-repsel-' . uniqid('', true),
        );
        try {
            $assertions($workspace);
        } finally {
            $workspace->cleanup();
        }
    }

    private function compiler(): Compiler
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
