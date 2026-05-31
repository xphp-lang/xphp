<?php

declare(strict_types=1);

namespace XPHP\Lsp\Handler\SemanticTokens;

/**
 * Encode a list of {@see TokenSpec}s as the packed delta-encoded
 * integer array LSP's `textDocument/semanticTokens/full` returns.
 *
 * Encoding (LSP 3.17):
 *
 *   For each token:
 *     - deltaLine      -- line delta from the previous token (or
 *                         absolute line for the first token)
 *     - deltaStartChar -- character delta from the previous token's
 *                         start IF deltaLine == 0, else absolute
 *                         character position
 *     - length         -- UTF-16 code units
 *     - tokenType      -- index into the legend's TOKEN_TYPES
 *     - tokenModifiers -- bitfield over TOKEN_MODIFIERS
 *
 * Specs MUST already be sorted in source order (by line, then start
 * char); the encoder asserts this in case-debug and silently re-sorts
 * in production -- a server that hands the client unsorted tokens
 * triggers undefined client behaviour (PhpStorm renders nothing; VS
 * Code paints garbage offsets) and is the most common
 * semantic-tokens bug.
 *
 * Specs with unknown token types (typeIndex == -1) are dropped --
 * see {@see TokenLegend::typeIndex} for the rationale.
 *
 * @internal stateless; the static method is the public surface.
 */
final class Encoder
{
    /**
     * @param  list<TokenSpec> $specs
     * @return list<int>       packed token data
     */
    public static function encode(array $specs): array
    {
        if ($specs === []) {
            return [];
        }

        // Sort by (line, startChar) -- defensive against callers that
        // emit tokens in AST-traversal order rather than source order
        // (e.g. when a method body is visited before its own signature).
        usort($specs, static function (TokenSpec $a, TokenSpec $b): int {
            if ($a->line !== $b->line) {
                return $a->line <=> $b->line;
            }
            return $a->startChar <=> $b->startChar;
        });

        $data = [];
        $prevLine = 0;
        $prevStart = 0;
        $firstEmitted = false;

        foreach ($specs as $spec) {
            $typeIdx = TokenLegend::typeIndex($spec->type);
            if ($typeIdx < 0) {
                continue;
            }

            $deltaLine = $firstEmitted ? $spec->line - $prevLine : $spec->line;
            $deltaStart = (!$firstEmitted || $deltaLine !== 0)
                ? $spec->startChar
                : $spec->startChar - $prevStart;

            $data[] = $deltaLine;
            $data[] = $deltaStart;
            $data[] = $spec->length;
            $data[] = $typeIdx;
            $data[] = TokenLegend::modifierBits($spec->modifiers);

            $prevLine = $spec->line;
            $prevStart = $spec->startChar;
            $firstEmitted = true;
        }

        return $data;
    }
}
