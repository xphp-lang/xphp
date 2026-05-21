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
class Analyzer
{
    public function __construct(private readonly XphpSourceParser $parser)
    {
    }

    public function analyzeFile(string $source): ParseResult
    {
        $positionMap = new PositionMap($source);

        try {
            $ast = $this->parser->parse($source);
            return new ParseResult($ast, []);
        } catch (PhpParserError $e) {
            return new ParseResult(
                ast: null,
                diagnostics: [self::buildParseErrorDiagnostic($positionMap, $e, $source)],
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

    /**
     * Map a `PhpParser\Error` to a Diagnostic with column-accurate range when
     * the parser kept enough info (`hasColumnInfo()`), falling back to a
     * full-line underline otherwise. nikic returns 1-based columns; we
     * subtract 1 for LSP's 0-based shape.
     */
    private static function buildParseErrorDiagnostic(
        PositionMap $positionMap,
        PhpParserError $e,
        string $source,
    ): Diagnostic {
        $message = 'Syntax error: ' . $e->getRawMessage();
        if (!$e->hasColumnInfo()) {
            return self::buildLineDiagnostic($positionMap, $e->getStartLine(), DiagnosticCode::Parse, $message);
        }
        $startLine = PositionMap::lspLineFromNikic($e->getStartLine());
        $endLine = PositionMap::lspLineFromNikic($e->getEndLine());
        return new Diagnostic(
            startLine: $startLine,
            startCharacter: $e->getStartColumn($source) - 1,
            endLine: $endLine,
            // endColumn from nikic is the column of the LAST character (1-based,
            // inclusive). LSP ranges are half-open, so we don't subtract 1.
            endCharacter: $e->getEndColumn($source),
            message: $message,
            code: DiagnosticCode::Parse,
        );
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
