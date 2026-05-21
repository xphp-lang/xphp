<?php

declare(strict_types=1);

namespace XPHP\Lsp\Diagnostics;

use Phpactor\LanguageServerProtocol\Diagnostic as LspDiagnostic;
use Phpactor\LanguageServerProtocol\Position;
use Phpactor\LanguageServerProtocol\Range;
use XPHP\Lsp\Analyzer\Diagnostic;

/**
 * Translates the analyzer's framework-neutral Diagnostic into phpactor's wire-format
 * Diagnostic. The two layers exist so the analyzer is testable without spinning up
 * the LSP transport; the boundary lives in exactly one place — here.
 */
final readonly class DiagnosticTranslator
{
    public static function toLsp(Diagnostic $d): LspDiagnostic
    {
        $lsp = new LspDiagnostic(
            range: new Range(
                new Position($d->startLine, $d->startCharacter),
                new Position($d->endLine, $d->endCharacter),
            ),
            message: $d->message,
        );
        $lsp->severity = $d->severity->value;
        $lsp->code = $d->code->value;
        $lsp->source = 'xphp';
        return $lsp;
    }
}
