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
     * @return array{prefix: string, containerName: string, slot: int}|null
     *   null  → cursor is not in a type-arg position
     *   array → cursor IS in a type-arg position.
     *           `prefix`        - substring typed since the last `<` or `,`
     *                             (post-whitespace), used to filter candidates.
     *           `containerName` - the Name preceding the unmatched `<` (the
     *                             generic class / function whose type-args we
     *                             are inside).  Same form as it appeared in
     *                             source -- may be a short name (`Box`) or a
     *                             qualified one (`App\Box`).
     *           `slot`          - 0-based index of the type-arg slot the
     *                             cursor sits in (0 for `Box<|`, 1 for
     *                             `Pair<Foo, |`, ...).  Used by bound-aware
     *                             completion to pick the relevant bound.
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
        // Count commas seen at depth 0 along the way -- that's the slot
        // index for the cursor's argument position.
        $depth = 0;
        $slot = 0;
        $i = $prefixStart - 1;
        while ($i >= 0) {
            $c = $source[$i];
            if ($c === '>') {
                $depth++;
                $i--;
                continue;
            }
            if ($c === ',' && $depth === 0) {
                $slot++;
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
                    // Scan the container Name backwards: identifier bytes,
                    // possibly through `\` separators.
                    $nameEnd = $i; // exclusive
                    $nameStart = $j;
                    while ($nameStart > 0 && self::isIdentifierByte($source[$nameStart - 1])) {
                        $nameStart--;
                    }
                    $containerName = substr($source, $nameStart, $nameEnd - $nameStart);
                    return [
                        'prefix' => $prefix,
                        'containerName' => $containerName,
                        'slot' => $slot,
                    ];
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

    /**
     * Full identifier under the cursor, only when the cursor is inside a
     * generic `<…>` clause AND on (or adjacent to) identifier bytes.
     *
     * Built on top of [[detect]]: that method returns the prefix to the LEFT
     * of the cursor; here we additionally scan FORWARD from the cursor and
     * concatenate the trailing identifier bytes, yielding the full identifier
     * span the user is actually pointing at.
     *
     * Returns null when:
     *  - the cursor isn't in a type-arg position (whatever [[detect]]
     *    decides), or
     *  - there's no identifier byte at the cursor and no prefix to the left
     *    (e.g. cursor on whitespace inside `<…>`).
     *
     * Used by the definition handler for Ctrl+click on a type-arg class name
     * like `User` in `identity<User>(...)`.  The completion handler uses the
     * `detect`-only prefix because completion needs the typed-so-far stem,
     * not the full identifier including the suffix the user hasn't typed.
     */
    public static function identifierAt(string $source, int $offset): ?string
    {
        $context = self::detect($source, $offset);
        if ($context === null) {
            return null;
        }

        // Walk forward from the cursor capturing the trailing identifier
        // bytes -- the part of the name to the RIGHT of the cursor that the
        // user has already typed.
        $length = strlen($source);
        $end = $offset;
        while ($end < $length && self::isIdentifierByte($source[$end])) {
            $end++;
        }
        $suffix = substr($source, $offset, $end - $offset);

        $full = $context['prefix'] . $suffix;
        return $full === '' ? null : $full;
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
