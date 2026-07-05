<?php

declare(strict_types=1);

namespace XPHP\StaticAnalysis;

use XPHP\Diagnostics\DiagnosticCollector;
use XPHP\FileSystem\FilepathArray;

/**
 * The validation gate both `xphp check` and the safe-by-default `xphp compile` run: the generic validators
 * plus (when the generics pass and PHPStan is requested) the PHPStan pass over the compiled output, merged
 * into one {@see DiagnosticCollector}. {@see CheckGate} is the production implementation.
 */
interface Gate
{
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
    ): DiagnosticCollector;
}
