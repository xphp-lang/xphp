<?php

declare(strict_types=1);

namespace XPHP\Lsp\Handler;

/**
 * Decide whether a cursor position is inside the generic-args clause of an
 * xphp type expression — i.e. inside the `<…>` that follows a Name.
 *
 * Strategy: walk the source backwards from the cursor with a `<>` depth
 * counter. Decrement on `>`, increment on `<`. If the depth ever reaches +1 on
 * a `<` and the byte immediately before that `<` is an identifier byte, the
 * cursor is in a type-arg position relative to that Name.
 *
 * Single-pass, no string/comment awareness — that's fine because xphp generic
 * syntax doesn't appear inside strings or comments (the parser-level scanner
 * already handles those for parsing; for completion, false positives in
 * strings just suggest classes that the user will ignore).
 *
 * Limits intentionally accepted:
 *  - Doesn't bind the surrounding Name to the candidate filter (so we can't
 *    yet prune by "bounds declared on T of Box"). That requires AST work
 *    and lands alongside bound-aware completion later.
 */
final readonly class TypeArgPositionDetector
{
    /**
     * @return array{prefix: string}|null
     *   null  → cursor is not in a type-arg position
     *   array → cursor IS in a type-arg position; `prefix` is the substring the
     *           user has typed since the last `<` or `,` (post-whitespace),
     *           used to filter completion candidates.
     */
    public static function detect(string $source, int $offset): ?array
    {
        $length = strlen($source);
        if ($offset > $length) {
            return null;
        }
        // Pull the partial identifier under the cursor out — that's the prefix
        // the user has already typed since the last `<` or `,`. Identifier
        // bytes include backslashes (so `App\Pla|` is a single FQN-style
        // prefix).
        $prefixStart = $offset;
        while ($prefixStart > 0 && self::isIdentifierByte($source[$prefixStart - 1])) {
            $prefixStart--;
        }
        $prefix = substr($source, $prefixStart, $offset - $prefixStart);

        // Walk back from the prefix start with a `<>` depth counter. We're
        // looking for the FIRST `<` at depth 0 (i.e. an unmatched opener).
        $depth = 0;
        $i = $prefixStart - 1;
        while ($i >= 0) {
            $c = $source[$i];
            if ($c === '>') {
                $depth++;
                $i--;
                continue;
            }
            if ($c === '<') {
                if ($depth === 0) {
                    // Found the unmatched opener. The byte before it must be
                    // an identifier byte (the generic Name) — otherwise it's
                    // a less-than operator.
                    $j = $i - 1;
                    if ($j < 0 || !self::isIdentifierByte($source[$j])) {
                        return null;
                    }
                    return ['prefix' => $prefix];
                }
                $depth--;
                $i--;
                continue;
            }
            if (self::isInterArgByte($c) || self::isIdentifierByte($c)) {
                $i--;
                continue;
            }
            // Anything else (`(`, `;`, `=`, `{`, …) breaks the type-arg
            // context — we're not inside a `<…>` clause.
            return null;
        }
        return null;
    }

    private static function isIdentifierByte(string $byte): bool
    {
        return ctype_alnum($byte) || $byte === '_' || $byte === '\\';
    }

    private static function isInterArgByte(string $byte): bool
    {
        // Whitespace + commas separate args; both are legal inside `<…>`.
        return $byte === ' ' || $byte === "\t" || $byte === "\n" || $byte === "\r" || $byte === ',';
    }
}
