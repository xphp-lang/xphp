<?php

declare(strict_types=1);

namespace XPHP\Lsp\Handler\SemanticTokens;

/**
 * Static legend for `textDocument/semanticTokens`.
 *
 * The LSP spec demands the server advertise an ordered list of token
 * types and modifiers at `initialize` time.  The packed token array
 * returned later carries indices into this list, not strings, so the
 * order is load-bearing -- adding a new type goes at the END.  Index
 * stability across server versions matters because clients cache the
 * legend per connection.
 *
 * The subset chosen here covers every classification the xphp AST
 * visitor emits (PHP-shaped + xphp generic forms) and uses only
 * standard LSP token types -- both PhpStorm and VS Code default themes
 * have entries for each, so no per-editor color configuration is
 * required.
 *
 * @see https://microsoft.github.io/language-server-protocol/specifications/lsp/3.17/specification/#textDocument_semanticTokens
 */
final class TokenLegend
{
    /**
     * @var list<string>
     */
    public const TOKEN_TYPES = [
        'namespace',
        'type',
        'class',
        'interface',
        'enum',
        'typeParameter',
        'parameter',
        'variable',
        'property',
        'function',
        'method',
        'keyword',
        'modifier',
        'comment',
        'string',
        'number',
        'operator',
    ];

    /**
     * @var list<string>
     */
    public const TOKEN_MODIFIERS = [
        'declaration',
        'definition',
        'readonly',
        'static',
        'deprecated',
        'abstract',
    ];

    /**
     * Index of a token type in {@see TOKEN_TYPES}.  Returns -1 for an
     * unknown type so the caller can hard-fail in tests but the
     * server never crashes on an unrecognised classification.
     */
    public static function typeIndex(string $tokenType): int
    {
        $idx = array_search($tokenType, self::TOKEN_TYPES, true);
        return $idx === false ? -1 : $idx;
    }

    /**
     * Encode a list of modifier names as a bitfield over
     * {@see TOKEN_MODIFIERS}.  Unknown modifiers are silently dropped
     * (same fail-soft posture as {@see typeIndex}).
     *
     * @param list<string> $modifiers
     */
    public static function modifierBits(array $modifiers): int
    {
        $bits = 0;
        foreach ($modifiers as $modifier) {
            $idx = array_search($modifier, self::TOKEN_MODIFIERS, true);
            if ($idx !== false) {
                $bits |= 1 << $idx;
            }
        }
        return $bits;
    }
}
