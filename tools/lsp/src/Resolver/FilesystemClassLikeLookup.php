<?php

declare(strict_types=1);

namespace XPHP\Lsp\Resolver;

use PhpParser\Node\Stmt\ClassLike;
use XPHP\Lsp\Reflection\FqnIndex;

/**
 * Resolve a ClassLike FQN by re-parsing on-disk files via `FqnIndex`.
 *
 * Sibling to `WorkspaceClassLikeLookup` (open-doc impl); the
 * `ClassLikeLookup` interface keeps them interchangeable.  Used in
 * `CompositeClassLikeLookup` as the fallback for FQNs not currently open
 * in the editor -- e.g. `App\Containers\Collection` when the user is
 * editing usage code in another file with Collection.xphp closed.
 *
 * Returns `ClassLike` nodes with xphp attributes attached
 * (`ATTR_GENERIC_PARAMS`, `ATTR_TEMPLATE_FQN`) so `GenericResolver` can
 * substitute type-args without re-parsing.
 */
final class FilesystemClassLikeLookup implements ClassLikeLookup
{
    public function __construct(
        private readonly FqnIndex $index,
    ) {
    }

    public function find(string $fqn): ?ClassLike
    {
        return $this->index->classLikeFor($fqn);
    }
}
