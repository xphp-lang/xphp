<?php

declare(strict_types=1);

namespace XPHP\Lsp\Handler\SemanticTokens;

/**
 * Pre-encoding representation of one semantic token: absolute LSP position
 * + length + classification.
 *
 * The visitor emits a list of these in source order; {@see Encoder}
 * packs them into the delta-encoded integer array LSP requires.  Keeping
 * the pre-encoded form around as a value object means tests can assert
 * "the AST produces these tokens at these positions" without having to
 * reverse the delta encoding -- the encoding itself gets its own
 * targeted tests.
 *
 * Lengths are in UTF-16 code units (per LSP spec), matching the column
 * units {@see \XPHP\Lsp\PositionMap} already emits.  For ASCII-only
 * identifiers (the vast majority of PHP source) this equals the byte
 * length; for supplementary-plane codepoints inside a token, the
 * caller must compute UTF-16 length explicitly.
 *
 * @internal value object; immutable once constructed.
 */
final class TokenSpec
{
    /**
     * @param int          $line       0-indexed line in the source file
     * @param int          $startChar  0-indexed UTF-16 column on that line
     * @param int          $length     token length in UTF-16 code units
     * @param string       $type       must be one of {@see TokenLegend::TOKEN_TYPES}
     * @param list<string> $modifiers  zero or more entries from {@see TokenLegend::TOKEN_MODIFIERS}
     */
    public function __construct(
        public readonly int $line,
        public readonly int $startChar,
        public readonly int $length,
        public readonly string $type,
        public readonly array $modifiers = [],
    ) {
    }
}
