<?php

declare(strict_types=1);

namespace XPHP\Lsp\Analyzer;

/**
 * Mirrors the LSP DiagnosticSeverity numeric values exactly so handlers can
 * pass `->value` straight into the wire-format Diagnostic without translation.
 */
enum DiagnosticSeverity: int
{
    case Error = 1;
    case Warning = 2;
    case Information = 3;
    case Hint = 4;
}
