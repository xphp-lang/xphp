<?php

declare(strict_types=1);

namespace XPHP\StaticAnalysis;

use XPHP\Diagnostics\Diagnostic;
use XPHP\Diagnostics\DiagnosticSource;
use XPHP\Diagnostics\Severity;
use XPHP\Diagnostics\SourceLocation;

/**
 * Re-anchors PHPStan findings — which point at generated specialized-class files
 * in a throwaway temp dir — back onto the originating `.xphp` template
 * declaration, so the report a user sees references their source, not machine
 * output. Each finding's file is matched to the {@see Representative} that was
 * analysed; the diagnostic is emitted at that template's declaration line, with
 * `triggeredBy` naming the concrete instantiation that surfaced it.
 *
 * Findings are PHPStan errors over concrete code, so they map to Error severity
 * (they fail the gate). A finding whose file matches no representative is not
 * expected — only representative files are analysed — so it's surfaced with NO
 * location rather than being dropped (never hide a real error) or pointed at the
 * throwaway temp-dir path it came from (never leak a path the user can't open).
 */
final class PhpStanResultMapper
{
    /**
     * @param list<PhpStanFinding> $findings
     * @param list<Representative> $representatives
     * @return list<Diagnostic>
     */
    public static function map(array $findings, array $representatives): array
    {
        $byFile = [];
        foreach ($representatives as $representative) {
            $byFile[$representative->filePath] = $representative;
        }

        $diagnostics = [];
        foreach ($findings as $finding) {
            $representative = $byFile[$finding->file] ?? null;

            $diagnostics[] = $representative !== null
                ? new Diagnostic(
                    Severity::Error,
                    self::code($finding),
                    $finding->message,
                    new SourceLocation($representative->declFile, $representative->declLine),
                    $representative->label,
                    DiagnosticSource::PhpStan,
                )
                : new Diagnostic(
                    Severity::Error,
                    self::code($finding),
                    $finding->message,
                    null,
                    null,
                    DiagnosticSource::PhpStan,
                );
        }

        return $diagnostics;
    }

    private static function code(PhpStanFinding $finding): string
    {
        return $finding->identifier !== null ? 'phpstan.' . $finding->identifier : 'phpstan.error';
    }
}
