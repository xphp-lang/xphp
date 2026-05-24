<?php

declare(strict_types=1);

namespace XPHP\Lsp\Resolver;

use XPHP\Lsp\Handler\WorkspaceSymbols;

/**
 * Unified function-FQN / class-FQN index combining:
 *
 *  - **Workspace symbols** -- enumerated lazily on each call from open
 *    documents via `WorkspaceSymbols`.  Cheap (cached ASTs).
 *  - **Stub symbols** -- the ~5000 function + ~1000 class FQNs in
 *    `phpstorm-stubs`, loaded from a one-time-built JSON cache via
 *    `StubsIndex::loadOrBuild()` and memoized for the LSP session.
 *
 * Used by `PhpCompletionResolver` to back expression-position completion
 * (bare identifier, `new |`).  Worse-reflection itself only does
 * lookup-by-name; an enumerated index is the missing piece.
 */
final class CompletionIndex
{
    private ?StubsIndex $stubsCache = null;

    public function __construct(
        private readonly WorkspaceSymbols $workspaceSymbols,
        private readonly string $stubsDir,
    ) {
    }

    /**
     * @return list<string>  De-duplicated class FQNs from workspace + stubs.
     */
    public function classFqns(): array
    {
        /** @var array<string, bool> $fqns */
        $fqns = [];
        foreach ($this->workspaceSymbols->allClassFqns() as $fqn) {
            $fqns[$fqn] = true;
        }
        foreach ($this->stubsIndex()->classes as $fqn) {
            $fqns[$fqn] = true;
        }
        return array_keys($fqns);
    }

    /**
     * @return list<string>  De-duplicated function FQNs from workspace + stubs.
     */
    public function functionFqns(): array
    {
        /** @var array<string, bool> $fqns */
        $fqns = [];
        foreach ($this->workspaceSymbols->allFunctionFqns() as $fqn) {
            $fqns[$fqn] = true;
        }
        foreach ($this->stubsIndex()->functions as $fqn) {
            $fqns[$fqn] = true;
        }
        return array_keys($fqns);
    }

    private function stubsIndex(): StubsIndex
    {
        if ($this->stubsCache === null) {
            $this->stubsCache = StubsIndex::loadOrBuild($this->stubsDir);
        }
        return $this->stubsCache;
    }
}
