<?php

declare(strict_types=1);

namespace XPHP\Lsp\Analyzer;

use PhpParser\Node;
use XPHP\Transpiler\Monomorphize\ByteOffsetMap;

/**
 * Result of analyzing a single .xphp file.
 *
 * `ast` is null when the parser couldn't recover (the syntax error was unrecoverable),
 * in which case `diagnostics` carries the parse errors. Even with `ast` set, diagnostics
 * may still be non-empty — recoverable parse errors and bound violations both go here.
 *
 * `byteOffsetMap` translates AST byte offsets (which are positioned in the
 * stripped post-xphp-rewrite source) back to the original source -- needed
 * any time a position is sent to the LSP client.  Identity map when no
 * length-changing replacement fired (the common case).
 */
final readonly class ParseResult
{
    /**
     * @param list<Node\Stmt>|null $ast
     * @param list<Diagnostic> $diagnostics
     */
    public function __construct(
        public ?array $ast,
        public array $diagnostics,
        public ByteOffsetMap $byteOffsetMap,
    ) {
    }
}
