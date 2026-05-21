<?php

declare(strict_types=1);

namespace XPHP\Lsp\Analyzer;

use PhpParser\Error as PhpParserError;
use RuntimeException;
use XPHP\Lsp\PositionMap;
use XPHP\Transpiler\Monomorphize\XphpSourceParser;

/**
 * Per-file analyzer wrapping XphpSourceParser in try/catch so an LSP client can
 * see *all* errors in a document instead of just the first one. The existing
 * compiler pipeline is throw-on-first-error; we deliberately wrap rather than
 * modify it (see plan, "Strategic note on error model" — option B).
 *
 * Workspace-level checks (bound validation across the cross-file TypeHierarchy)
 * are layered on top of this in `WorkspaceAnalyzer`; the per-file analyzer here
 * handles only what's local to a single document.
 */
final readonly class Analyzer
{
    public function __construct(private XphpSourceParser $parser)
    {
    }

    public function analyzeFile(string $source): ParseResult
    {
        $positionMap = new PositionMap($source);

        try {
            $ast = $this->parser->parse($source);
            return new ParseResult($ast, []);
        } catch (PhpParserError $e) {
            // nikic/php-parser carries the start line on PhpParser\Error directly.
            // We pin the diagnostic to the full line — column-accurate ranges are a
            // follow-up using $e->getStartColumn($source).
            return new ParseResult(
                ast: null,
                diagnostics: [self::buildLineDiagnostic(
                    $positionMap,
                    $e->getStartLine(),
                    DiagnosticCode::Parse,
                    'Syntax error: ' . $e->getRawMessage(),
                )],
            );
        } catch (RuntimeException $e) {
            // XphpSourceParser also throws plain RuntimeException for "parser returned null"
            // and similar unrecoverable states; surface those as line-1 errors so the user
            // sees *something* in the gutter rather than nothing.
            return new ParseResult(
                ast: null,
                diagnostics: [self::buildLineDiagnostic(
                    $positionMap,
                    1,
                    DiagnosticCode::ParseInternal,
                    $e->getMessage(),
                )],
            );
        }
    }

    private static function buildLineDiagnostic(
        PositionMap $positionMap,
        int $nikicLine,
        DiagnosticCode $code,
        string $message,
    ): Diagnostic {
        [$startLine, $startChar, $endLine, $endChar] = $positionMap->fullLineRangeFromNikic($nikicLine);
        return new Diagnostic(
            startLine: $startLine,
            startCharacter: $startChar,
            endLine: $endLine,
            endCharacter: $endChar,
            message: $message,
            code: $code,
        );
    }
}
