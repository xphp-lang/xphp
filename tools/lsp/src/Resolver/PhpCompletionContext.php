<?php

declare(strict_types=1);

namespace XPHP\Lsp\Resolver;

/**
 * Lightweight source-level detector that classifies the cursor into one of
 * the completion contexts we support:
 *
 *  - `member`: cursor is right after `->` or in the middle of an
 *    identifier following `->` (e.g. `$obj->|`, `$obj->sho|`).  We need
 *    the receiver-side offset (byte just before `->`) so worse-reflection
 *    can reflect that expression and tell us its type.
 *
 *  - `static`: cursor is right after `::` or in the identifier following.
 *    We need the class identifier (or the receiver expression -- e.g.
 *    `self::|`, `parent::|`, `Foo\Bar::|`) so we can reflect the class.
 *
 *  - `null`: cursor isn't in either of the supported member-access shapes.
 *    The fall-through behaviour is "no PHP completion offered" -- callers
 *    should preserve whatever other completion path applies (type-args,
 *    no completion at all, etc.).
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
 */
final class PhpCompletionContext
{
    private function __construct()
    {
    }

    /**
     * @return array{kind: 'member', receiverEnd: int, prefix: string}
     *       | array{kind: 'static', receiverEnd: int, prefix: string}
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

        if ($prefixStart < 2) {
            return null;
        }

        // `::` => static access.  Receiver token ends at $prefixStart - 2.
        if ($source[$prefixStart - 1] === ':' && $source[$prefixStart - 2] === ':') {
            return [
                'kind' => 'static',
                'receiverEnd' => $prefixStart - 2,
                'prefix' => $prefix,
            ];
        }

        // `->` => member access.  Receiver expression ends at $prefixStart - 2.
        if ($source[$prefixStart - 1] === '>' && $source[$prefixStart - 2] === '-') {
            return [
                'kind' => 'member',
                'receiverEnd' => $prefixStart - 2,
                'prefix' => $prefix,
            ];
        }

        return null;
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
}
