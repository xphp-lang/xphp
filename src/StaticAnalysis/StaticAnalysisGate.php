<?php

declare(strict_types=1);

namespace XPHP\StaticAnalysis;

use XPHP\Diagnostics\Diagnostic;
use XPHP\Diagnostics\DiagnosticSource;
use XPHP\Diagnostics\Severity;
use XPHP\FileSystem\FilepathArray;
use XPHP\Transpiler\Monomorphize\Compiler;

/**
 * Orchestrates the PHPStan layer of `xphp check`: locate the consumer's PHPStan,
 * compile the sources to a throwaway workspace, run PHPStan over the
 * representative specializations using the consumer's own config, and map any
 * findings back onto the `.xphp` template declarations.
 *
 * A missing PHPStan or a failed run yields a non-failing **Warning** — a missing
 * optional tool, or a consumer-config problem, never turns the gate red on its
 * own (the generic checks already ran). All returned diagnostics are merged into
 * the same report and exit code as those checks.
 */
final readonly class StaticAnalysisGate
{
    public const string CODE_UNAVAILABLE = 'phpstan.unavailable';
    public const string CODE_RUN_FAILED = 'phpstan.run_failed';

    public function __construct(private Compiler $compiler)
    {
    }

    /**
     * @return list<Diagnostic>
     */
    public function analyze(
        FilepathArray $sources,
        string $sourceDir,
        string $workingDir,
        ?string $explicitBin,
        ?string $explicitConfig,
    ): array {
        $bin = PhpStanLocator::fromEnvironment($workingDir)->locate($explicitBin);
        if ($bin === null) {
            return [new Diagnostic(
                Severity::Warning,
                self::CODE_UNAVAILABLE,
                'PHPStan was not found (looked for --phpstan-bin, then vendor/bin/phpstan, then $PATH); '
                    . 'skipping static analysis. Pass --no-phpstan to silence this.',
                null,
                null,
                DiagnosticSource::PhpStan,
            )];
        }

        $config = (new PhpStanConfigResolver($workingDir))->resolve($explicitConfig);
        $workspace = CompiledWorkspace::inTempDir($this->compiler, $sources, $sourceDir, sys_get_temp_dir());
        try {
            $representatives = RepresentativeSelector::select($workspace->registry, $workspace->generatedDir);
            if ($representatives === []) {
                // No generic instantiations → no specialized code for PHPStan to add over
                // the generic checks. Nothing to do.
                return [];
            }

            $result = (new PhpStanRunner($bin))->run(
                array_map(static fn (Representative $r): string => $r->filePath, $representatives),
                self::buildScanDirectories($workspace->distDir, $workspace->generatedDir, $workingDir),
                $config,
                $workspace->root . '/phpstan-ephemeral.neon',
            );

            if (!$result->ranOk) {
                return [new Diagnostic(
                    Severity::Warning,
                    self::CODE_RUN_FAILED,
                    'PHPStan could not complete: ' . self::summarize($result->errorOutput),
                    null,
                    null,
                    DiagnosticSource::PhpStan,
                )];
            }

            return PhpStanResultMapper::map($result->findings, $representatives);
        } finally {
            $workspace->cleanup();
        }
    }

    /**
     * Dirs PHPStan scans for symbol resolution: the compiled output plus the
     * consumer's vendor (when present) so generated code resolves its
     * dependencies. Pure/static so the vendor-inclusion logic is unit-testable.
     *
     * @return list<string>
     */
    public static function buildScanDirectories(string $distDir, string $generatedDir, string $workingDir): array
    {
        $directories = [$distDir, $generatedDir];

        $vendorDir = $workingDir . '/vendor';
        if (is_dir($vendorDir)) {
            $directories[] = $vendorDir;
        }

        return $directories;
    }

    /**
     * Cap a PHPStan failure blob so a fatal that dumps a long trace (or a flood of
     * "unknown class" lines) doesn't become a single unreadable diagnostic message.
     */
    public static function summarize(?string $errorOutput, int $max = 500): string
    {
        if ($errorOutput === null || $errorOutput === '') {
            return 'unknown error';
        }

        return strlen($errorOutput) > $max ? substr($errorOutput, 0, $max) . '…' : $errorOutput;
    }
}
