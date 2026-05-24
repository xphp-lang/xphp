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
 * Out of scope: detecting string/comment context.  False positives there
 * produce no-match-prefix empty lists rather than crashes, which is
 * acceptable for an MVP.
 */
final class PhpCompletionContext
{
    private function __construct()
    {
    }

    /**
     * @return array{kind: 'member',     receiverEnd: int, prefix: string}
     *       | array{kind: 'static',     receiverEnd: int, prefix: string}
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
}
