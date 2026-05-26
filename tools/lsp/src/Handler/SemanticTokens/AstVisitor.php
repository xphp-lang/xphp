<?php

declare(strict_types=1);

namespace XPHP\Lsp\Handler\SemanticTokens;

use PhpParser\Node;
use XPHP\Lsp\PositionMap;
use XPHP\Transpiler\Monomorphize\ByteOffsetMap;

/**
 * Walk the xphp AST and emit {@see TokenSpec} entries for every
 * syntactic construct PhpStorm + VS Code should color.
 *
 * Slice 1 (this file initially):  the visitor exists but emits NO
 * tokens.  Wiring proof only -- proves the handler -> visitor ->
 * encoder pipeline runs end-to-end and returns an empty array to the
 * client.  Subsequent slices fill in the per-node visit logic.
 *
 * Position translation: AST node offsets are byte-indexed into the
 * STRIPPED buffer (`<...>` clauses excised before parsing).  Map back
 * to original-source byte offsets via {@see ByteOffsetMap}, then to
 * LSP `{line, character}` via {@see PositionMap}.  LSP character units
 * are UTF-16 code points by spec; PositionMap handles that.
 */
final class AstVisitor
{
    public function __construct(
        private readonly PositionMap $positionMap,
        private readonly ByteOffsetMap $byteOffsetMap,
    ) {
    }

    /**
     * Walk the AST and return the source-order list of tokens.
     *
     * @param  array<int, Node> $stmts AST root statements
     * @return list<TokenSpec>
     */
    public function visit(array $stmts): array
    {
        // Slice 1: empty.  The dispatcher pipeline + encoder still run
        // end-to-end; the client receives an empty packed array, which
        // is the LSP-spec equivalent of "no semantic information yet".
        return [];
    }
}
