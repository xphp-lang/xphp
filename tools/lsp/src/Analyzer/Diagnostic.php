<?php

declare(strict_types=1);

namespace XPHP\Lsp\Analyzer;

/**
 * Framework-neutral diagnostic with positions in LSP shape (0-based line + character).
 *
 * Handlers translate this to phpactor/language-server-protocol's Diagnostic at the
 * wire boundary. Keeping the analyzer output framework-free means the test suite
 * can assert against plain PHP objects without spinning up an LSP transport.
 *
 * `$code` is required and typed: every diagnostic declares its category up
 * front so editors / users can pattern-match by code (e.g. "downgrade
 * xphp.parse.internal to info"). The previous freeform `string $code = ''`
 * encouraged inconsistency between catch sites.
 */
final readonly class Diagnostic
{
    public function __construct(
        public int $startLine,
        public int $startCharacter,
        public int $endLine,
        public int $endCharacter,
        public string $message,
        public DiagnosticCode $code,
        public DiagnosticSeverity $severity = DiagnosticSeverity::Error,
    ) {
    }
}
