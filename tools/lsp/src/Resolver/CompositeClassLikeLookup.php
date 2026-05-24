<?php

declare(strict_types=1);

namespace XPHP\Lsp\Resolver;

use PhpParser\Node\Stmt\ClassLike;

/**
 * Chain of `ClassLikeLookup`s tried in order.  First hit wins.
 *
 * Standard wiring in the LSP factory:
 *   1. `WorkspaceClassLikeLookup`  (open-doc, fresh from cache)
 *   2. `FilesystemClassLikeLookup` (on-disk fallback via FqnIndex)
 *
 * Open-doc beats filesystem so unsaved edits in the editor reflect through
 * to `GenericResolver`'s substitution -- a Collection.xphp where the user
 * just added a new method must surface that method on the next hover,
 * not the older on-disk shape.
 */
final class CompositeClassLikeLookup implements ClassLikeLookup
{
    /** @var list<ClassLikeLookup> */
    private array $lookups;

    public function __construct(ClassLikeLookup ...$lookups)
    {
        $this->lookups = array_values($lookups);
    }

    public function find(string $fqn): ?ClassLike
    {
        foreach ($this->lookups as $lookup) {
            $found = $lookup->find($fqn);
            if ($found !== null) {
                return $found;
            }
        }
        return null;
    }
}
