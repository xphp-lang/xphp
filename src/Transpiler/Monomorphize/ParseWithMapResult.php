<?php

declare(strict_types=1);

namespace XPHP\Transpiler\Monomorphize;

use PhpParser\Node\Stmt;

/**
 * Bundle returned by `XphpSourceParser::parseTolerantWithMap()`.
 *
 * The byte-offset map is needed any time AST positions are emitted back to
 * an LSP client -- the AST's offsets point into the stripped source, but
 * the client's editor view is over the original xphp source.  Most files
 * see the identity map.
 */
final readonly class ParseWithMapResult
{
    /**
     * @param list<Stmt> $ast
     */
    public function __construct(
        public array $ast,
        public ByteOffsetMap $byteOffsetMap,
    ) {
    }
}
