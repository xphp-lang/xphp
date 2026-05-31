<?php

declare(strict_types=1);

namespace XPHP\Lsp\Analyzer;

use PhpParser\Error as PhpParserError;
use PhpParser\Node;
use PhpParser\Node\Expr\ConstFetch;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;
use RuntimeException;
use XPHP\Lsp\PositionMap;
use XPHP\Transpiler\Monomorphize\ByteOffsetMap;
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
            [$ast, $byteOffsetMap] = $this->parser->parseWithMap($source);
            $diagnostics = self::collectUndefinedNameDiagnostics($ast, $positionMap, $byteOffsetMap);
            return new ParseResult($ast, $diagnostics, $byteOffsetMap);
        } catch (PhpParserError $e) {
            // Strict parse failed (trailing `$x->` etc).  Fall back to
            // tolerant parsing so downstream consumers (`WorkspaceSourceLocator`,
            // documentSymbol, etc.) can still see whatever class /
            // function declarations parsed cleanly BEFORE the broken
            // tail.  Without this the in-memory locator skips the doc
            // and worse-reflection falls through to the on-disk version,
            // which can be missing edits the user just made.
            $tolerant = $this->parser->parseTolerantWithMap($source);
            return new ParseResult(
                ast: $tolerant?->ast,
                diagnostics: [self::buildParseErrorDiagnostic($positionMap, $e, $source)],
                byteOffsetMap: $tolerant?->byteOffsetMap ?? ByteOffsetMap::identity(),
            );
        } catch (RuntimeException $e) {
            // XphpSourceParser also throws plain RuntimeException for "parser returned null"
            // and similar unrecoverable states; surface those as line-1 errors so the user
            // sees *something* in the gutter rather than nothing.
            return new ParseResult(
                ast: null,
                byteOffsetMap: ByteOffsetMap::identity(),
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
        // nikic's `getStartColumn` / `getEndColumn` validate that the
        // attached byte position is `<= strlen($source)` and throw
        // `RuntimeException("Invalid position information")` otherwise.
        // The strip pass should preserve byte length, but a mid-edit
        // buffer + tolerant-parse-recovery can land an `endFilePos`
        // one past EOF, and the exception propagates all the way out
        // through `documentHighlight` -- PhpStorm responds with
        // `Diagnostic provider "xphp" errored ..., removing from pool`
        // and stops asking us for diagnostics for the rest of the
        // session (prod log id=122 of
        // xphp-20260529-195706-986.log).  Fall back to a line-only
        // range when either column lookup throws.
        try {
            $startCharacter = $e->getStartColumn($source) - 1;
            $endCharacter = $e->getEndColumn($source);
        } catch (RuntimeException) {
            return self::buildLineDiagnostic($positionMap, $e->getStartLine(), DiagnosticCode::Parse, $message);
        }
        $startLine = PositionMap::lspLineFromNikic($e->getStartLine());
        $endLine = PositionMap::lspLineFromNikic($e->getEndLine());
        return new Diagnostic(
            startLine: $startLine,
            startCharacter: $startCharacter,
            endLine: $endLine,
            // endColumn from nikic is the column of the LAST character (1-based,
            // inclusive). LSP ranges are half-open, so we don't subtract 1.
            endCharacter: $endCharacter,
            message: $message,
            code: DiagnosticCode::Parse,
        );
    }

    /**
     * Bareword pseudo-constants PHP recognises natively.  Used as the
     * exhaustive whitelist for the undefined-name heuristic: a
     * single-segment lowercase ConstFetch that ISN'T in this set is
     * almost certainly a typo (e.g. `nul` for `null`).  Uppercase
     * identifiers (PHP_EOL, M_PI, user-defined UPPER_SNAKE_CASE
     * constants) are NEVER flagged because the LSP doesn't yet
     * maintain a workspace-wide constant index and would false-positive
     * on every `define('FOO', ...)` declaration.
     */
    /** @internal exposed for the anonymous AST visitor. */
    public const PSEUDO_CONSTANTS = ['null' => true, 'true' => true, 'false' => true];

    /**
     * Walk the AST for `Expr\ConstFetch` nodes whose name is a
     * single-segment lowercase identifier outside the known pseudo-
     * constant set.  Emit a Warning per occurrence -- catches typos
     * like `$x ?? nul` that would otherwise only surface at runtime
     * (`Error: Undefined constant "nul"` in PHP 8+).
     *
     * @param  list<Node\Stmt>|null $ast
     * @return list<Diagnostic>
     */
    private static function collectUndefinedNameDiagnostics(
        ?array $ast,
        PositionMap $positionMap,
        ByteOffsetMap $byteOffsetMap,
    ): array {
        if ($ast === null || $ast === []) {
            return [];
        }
        $diagnostics = [];
        $traverser = new NodeTraverser();
        $traverser->addVisitor(new class($diagnostics, $positionMap, $byteOffsetMap) extends NodeVisitorAbstract {
            /** @param list<Diagnostic> $diagnostics */
            public function __construct(
                private array &$diagnostics,
                private PositionMap $positionMap,
                private ByteOffsetMap $byteOffsetMap,
            ) {
            }

            public function enterNode(Node $node): null
            {
                if (!$node instanceof ConstFetch) {
                    return null;
                }
                if ($node->name->isFullyQualified() || count($node->name->getParts()) > 1) {
                    // Qualified / FQN names need namespace + workspace
                    // resolution that the LSP doesn't have today; punt.
                    return null;
                }
                $name = $node->name->getParts()[0];
                if ($name !== strtolower($name)) {
                    // UPPER_CASE / CamelCase identifiers are almost
                    // always user-defined constants the LSP can't see.
                    return null;
                }
                if (isset(Analyzer::PSEUDO_CONSTANTS[$name])) {
                    return null;
                }
                $strippedStart = $node->getStartFilePos();
                $strippedEnd = $node->getEndFilePos();
                if ($strippedStart < 0 || $strippedEnd < $strippedStart) {
                    return null;
                }
                $origStart = $this->byteOffsetMap->toOriginal($strippedStart);
                $origEnd = $this->byteOffsetMap->toOriginal($strippedEnd + 1);
                if ($origStart < 0 || $origEnd < $origStart) {
                    return null;
                }
                [$startLine, $startChar] = $this->positionMap->offsetToPosition($origStart);
                [$endLine, $endChar] = $this->positionMap->offsetToPosition($origEnd);
                $this->diagnostics[] = new Diagnostic(
                    startLine: $startLine,
                    startCharacter: $startChar,
                    endLine: $endLine,
                    endCharacter: $endChar,
                    message: sprintf(
                        'Undefined constant "%s". Did you mean a lowercase keyword (null / true / false), '
                            . 'a class constant (`Foo::%s`), or is this a typo?',
                        $name,
                        $name,
                    ),
                    code: DiagnosticCode::UndefinedName,
                    severity: DiagnosticSeverity::Warning,
                );
                return null;
            }
        });
        $traverser->traverse($ast);
        return $diagnostics;
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
