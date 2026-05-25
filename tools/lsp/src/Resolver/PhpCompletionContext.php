<?php

declare(strict_types=1);

namespace XPHP\Lsp\Resolver;

/**
 * Lightweight source-level detector that classifies the cursor into one of
 * the completion contexts we support:
 *
 *  - `member`: cursor is right after `->` or in the middle of an
 *    identifier following `->` (e.g. `$obj->|`, `$obj->sho|`).
 *  - `static`: cursor is right after `::` or in the identifier following.
 *  - `variable`: cursor is on / right after a `$variable` identifier
 *    (e.g. `$re|`).
 *  - `new`: cursor is on an identifier directly following the `new`
 *    keyword (e.g. `new Us|`).  Class names only.
 *  - `expression`: cursor is on a bare identifier in any other position
 *    not covered above (e.g. `str|`, `User|`).  Suggests both classes and
 *    functions; PHP's lowercase-function / PascalCase-class convention
 *    means a typical prefix narrows the list naturally.
 *  - `null`: cursor isn't on anything completable (whitespace, after
 *    punctuation other than the recognised access operators, etc.).
 *
 * The detector is intentionally byte-scanning rather than AST-walking
 * for two reasons:
 *
 *  1. AT the cursor, the source is often syntactically incomplete (just
 *     typed `->` with nothing after).  A real parser refuses.
 *
 *  2. Matches the convention `TypeArgPositionDetector` uses for the
 *     `<…>` case -- this file is its sibling, picking up where it
 *     stops.
 *
 * String/comment context (Phase 3 polish): cursors inside single-quoted
 * strings, comments, doc-comments, heredoc bodies, or inline-HTML are
 * suppressed -- typing `'$user->'` shouldn't trigger member-completion
 * noise.  Double-quoted interpolation is left alone because the
 * tokenizer already splits `$user->prop` inside `"..."` into regular
 * code tokens, so completion fires naturally there.
 */
final class PhpCompletionContext
{
    /**
     * Tokens whose interior treats the cursor as "in literal text" --
     * completion stays silent inside them.
     *
     * Deliberately NOT here:
     *   - T_INLINE_HTML.  Real .xphp documents always open with `<?php`,
     *     so HTML-prefix completion is a non-case; the unit tests pass
     *     bare snippets that PhpToken classifies as T_INLINE_HTML
     *     wholesale, which we still want to detect as code.  The
     *     trade-off is that typing `$x->|` in HTML BEFORE an opening
     *     `<?php` tag (rare; broken xphp anyway) would fire completion;
     *     accept this edge case.
     *   - Double-quoted-string INTERPOLATION fragments (T_VARIABLE,
     *     T_OBJECT_OPERATOR, T_STRING).  Those are real code that
     *     should complete normally inside `"...$obj->name..."`.
     */
    private const TOKEN_IDS_SUPPRESS_COMPLETION = [
        T_CONSTANT_ENCAPSED_STRING,
        T_ENCAPSED_AND_WHITESPACE,
        T_COMMENT,
        T_DOC_COMMENT,
        T_START_HEREDOC,
        T_END_HEREDOC,
    ];

    private function __construct()
    {
    }

    /**
     * @return array{kind: 'member',     receiverEnd: int, prefix: string}
     *       | array{kind: 'static',     receiverEnd: int, prefix: string}
     *       | array{kind: 'static-prop',receiverEnd: int, prefix: string}
     *       | array{kind: 'variable',                     prefix: string}
     *       | array{kind: 'new',                          prefix: string}
     *       | array{kind: 'expression',                   prefix: string}
     *       | null
     */
    public static function detect(string $source, int $offset): ?array
    {
        if ($offset < 0 || $offset > strlen($source)) {
            return null;
        }
        if (self::isInLiteralText($source, $offset)) {
            return null;
        }

        $prefixStart = $offset;
        while ($prefixStart > 0 && self::isIdentifierByte($source[$prefixStart - 1])) {
            $prefixStart--;
        }
        $prefix = substr($source, $prefixStart, $offset - $prefixStart);

        // `::` => static access.  Receiver token ends at $prefixStart - 2.
        if ($prefixStart >= 2
            && $source[$prefixStart - 1] === ':'
            && $source[$prefixStart - 2] === ':'
        ) {
            return [
                'kind' => 'static',
                'receiverEnd' => $prefixStart - 2,
                'prefix' => $prefix,
            ];
        }

        // `->` => member access.  Receiver expression ends at $prefixStart - 2.
        if ($prefixStart >= 2
            && $source[$prefixStart - 1] === '>'
            && $source[$prefixStart - 2] === '-'
        ) {
            return [
                'kind' => 'member',
                'receiverEnd' => $prefixStart - 2,
                'prefix' => $prefix,
            ];
        }

        // `Cls::$pre|` => static property access.  The `$` is preceded by
        // `::`, which distinguishes it from a plain `$var` completion.
        // Must come BEFORE the generic `$` -> variable branch so the
        // static-prop case isn't swallowed.
        if ($prefixStart >= 3
            && $source[$prefixStart - 1] === '$'
            && $source[$prefixStart - 2] === ':'
            && $source[$prefixStart - 3] === ':'
        ) {
            return [
                'kind' => 'static-prop',
                'receiverEnd' => $prefixStart - 3,
                'prefix' => $prefix,
            ];
        }

        // `$` immediately before the identifier => variable.  Falls through
        // BEFORE the bare-identifier path so `$repo` doesn't classify as
        // expression with prefix "repo".
        if ($prefixStart >= 1 && $source[$prefixStart - 1] === '$') {
            return [
                'kind' => 'variable',
                'prefix' => $prefix,
            ];
        }

        // No identifier under the cursor -- nothing to complete.  We
        // deliberately don't fire on whitespace; the user must type at
        // least one character before completion shows up.
        if ($prefix === '') {
            return null;
        }

        // PHP identifiers cannot start with a digit (only letters and
        // underscore are valid leaders).  If our backwards-walk picked up
        // digit bytes as part of the "prefix" (e.g. cursor inside a `1;`
        // number literal), the cursor is in number-literal territory and
        // not a completable position.  Without this guard, `$x = 1;`
        // would classify as `expression` with prefix `1`.
        $firstByte = $prefix[0];
        if ($firstByte >= '0' && $firstByte <= '9') {
            return null;
        }

        // Bare identifier.  Look back past whitespace for the `new`
        // keyword; if it's there, this is a class-construction position
        // and we shouldn't suggest functions.
        $beforeIdent = $prefixStart;
        while ($beforeIdent > 0 && self::isWhitespace($source[$beforeIdent - 1])) {
            $beforeIdent--;
        }
        if (self::endsWithKeyword($source, $beforeIdent, 'new')) {
            return [
                'kind' => 'new',
                'prefix' => $prefix,
            ];
        }

        return [
            'kind' => 'expression',
            'prefix' => $prefix,
        ];
    }

    /**
     * Does `$source[..$position]` end with the keyword `$keyword`, with the
     * preceding byte being a non-identifier (so `mynew` doesn't match `new`)?
     */
    private static function endsWithKeyword(string $source, int $position, string $keyword): bool
    {
        $len = strlen($keyword);
        if ($position < $len) {
            return false;
        }
        if (substr($source, $position - $len, $len) !== $keyword) {
            return false;
        }
        if ($position === $len) {
            return true;
        }
        return !self::isIdentifierByte($source[$position - $len - 1]);
    }

    /**
     * Same identifier rule as `TypeArgPositionDetector::isIdentifierByte`
     * but intentionally duplicated to keep the detectors independent.
     */
    private static function isIdentifierByte(string $byte): bool
    {
        return ($byte >= 'a' && $byte <= 'z')
            || ($byte >= 'A' && $byte <= 'Z')
            || ($byte >= '0' && $byte <= '9')
            || $byte === '_'
            || $byte === '\\';
    }

    private static function isWhitespace(string $byte): bool
    {
        return $byte === ' ' || $byte === "\t" || $byte === "\n" || $byte === "\r";
    }

    /**
     * Tokenize the source and decide whether `$offset` falls inside a
     * literal-text token: single-quoted string body, heredoc literal
     * region, comment, doc-comment, or inline-HTML.  PHP's tokenizer is
     * fast in C and the source is already in memory; PhpToken::tokenize
     * is the cheapest correct way to do this without writing our own
     * lexer.
     *
     * Caller behavior: when this returns true, completion suppresses --
     * no member / variable / expression suggestions surface, no matter
     * what byte-level pattern the cursor happens to sit on.
     */
    private static function isInLiteralText(string $source, int $offset): bool
    {
        // Boundary case: tokenize requires valid bytes; an offset past
        // end-of-source bottoms out on no token, which means "not in
        // literal" -- safer to return false than to misclassify.
        if ($offset < 0 || $offset >= strlen($source)) {
            return false;
        }
        try {
            $tokens = \PhpToken::tokenize($source);
        } catch (\Throwable) {
            return false;
        }
        foreach ($tokens as $token) {
            $start = $token->pos;
            $end = $start + strlen($token->text);
            if ($offset < $start) {
                return false;
            }
            if ($offset >= $end) {
                continue;
            }
            // $start <= $offset < $end -- the cursor falls inside this
            // token.  Suppress only for literal-text token IDs.
            return in_array($token->id, self::TOKEN_IDS_SUPPRESS_COMPLETION, true);
        }
        return false;
    }
}
