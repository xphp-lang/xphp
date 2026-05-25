<?php

declare(strict_types=1);

namespace XPHP\Lsp\Resolver;

use PhpParser\Node\Stmt\ClassLike;

/**
 * Resolves a class FQN to its nikic `ClassLike` AST node *with the
 * xphp-specific attributes still attached*.
 *
 * Why a dedicated interface instead of reusing `WorkspaceSourceLocator` /
 * `FilesystemSourceLocator`: those return *stripped* `TextDocument`s for
 * worse-reflection to re-parse with `tolerant-php-parser`.  The strip
 * preserves byte offsets but discards every `XphpSourceParser` attribute
 * we attached (`ATTR_GENERIC_PARAMS`, `ATTR_TEMPLATE_FQN`, etc.) -- which
 * is exactly what the monomorphization-aware resolver needs.
 *
 * This lookup goes through `ParsedDocumentCache` instead, returning the
 * cached AST with attributes intact.
 */
interface ClassLikeLookup
{
    /**
     * Return the `ClassLike` declaration matching the given FQN, or null
     * if no document in scope declares it.  The returned node carries the
     * full set of XphpSourceParser attributes -- callers can read
     * `ATTR_GENERIC_PARAMS` on it without re-parsing.
     */
    public function find(string $fqn): ?ClassLike;
}
