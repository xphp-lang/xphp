<?php

declare(strict_types=1);

namespace XPHP\StaticAnalysis;

use XPHP\Diagnostics\DiagnosticCollector;
use XPHP\FileSystem\FilepathArray;
use XPHP\Transpiler\Monomorphize\Compiler;

/**
 * The validation gate shared by `xphp check` and the safe-by-default `xphp compile`: run the generic
 * validators ({@see Compiler::check}) and, only when those pass, layer the PHPStan pass over the compiled
 * output ({@see StaticAnalysisGate}) into the same collector — one gate, one report.
 *
 * PHPStan is only layered on a clean generic check because invalid generics can't be compiled to the
 * concrete output PHPStan analyses, and reporting both at once would bury the real (generic) errors. A
 * missing PHPStan degrades to a non-failing warning inside {@see StaticAnalysisGate}, so the gate never
 * hard-fails merely because PHPStan is absent.
 */
final readonly class CheckGate implements Gate
{
    public function __construct(
        private Compiler $compiler,
        private StaticAnalysisGate $staticAnalysisGate,
    ) {
    }

    /**
     * @param ?array<string,string> $rootByFile
     */
    public function run(
        FilepathArray $sources,
        string $sourceDir,
        string $workingDir,
        bool $runPhpStan,
        ?string $phpstanBin,
        ?string $phpstanConfig,
        ?array $rootByFile,
    ): DiagnosticCollector {
        $diagnostics = $this->compiler->check($sources);

        if (!$diagnostics->hasErrors() && $runPhpStan) {
            $findings = $this->staticAnalysisGate->analyze(
                $sources,
                $sourceDir,
                $workingDir,
                $phpstanBin,
                $phpstanConfig,
                $rootByFile,
            );
            foreach ($findings as $finding) {
                $diagnostics->add($finding);
            }
        }

        return $diagnostics;
    }
}
