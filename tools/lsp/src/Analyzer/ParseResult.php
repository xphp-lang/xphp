<?php

declare(strict_types=1);

namespace XPHP\Lsp\Analyzer;

use PhpParser\Node;

/**
 * Result of analyzing a single .xphp file.
 *
 * `ast` is null when the parser couldn't recover (the syntax error was unrecoverable),
 * in which case `diagnostics` carries the parse errors. Even with `ast` set, diagnostics
 * may still be non-empty — recoverable parse errors and bound violations both go here.
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
    ) {
    }
}
