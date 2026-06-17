<?php

declare(strict_types=1);

namespace XPHP\Diagnostics;

/**
 * A position in an original `.xphp` source file.
 *
 * `line` is 1-based and comes straight from the AST node (`getStartLine()`),
 * which already maps to the original source line (the parser's strip pass is
 * newline-preserving). `column` is optional and best-effort — `null` when the
 * column is unknown (Phase 1 reports line-only).
 */
final readonly class SourceLocation
{
    public function __construct(
        public string $file,
        public int $line,
        public ?int $column = null,
    ) {
    }
}
