<?php

declare(strict_types=1);

namespace XPHP\Lsp\Workspace;

use PhpParser\Node;

/**
 * In-memory store of document state, keyed by LSP URI (the editor sends URIs;
 * we use those verbatim as keys). Holds the latest source the editor has sent us
 * via didOpen / didChange, plus the parsed AST when parsing succeeded.
 *
 * The store does NOT persist to disk — the LSP keeps the editor's in-memory view
 * as the source of truth while a document is open; unsaved changes are visible to
 * diagnostics + hover + go-to-def via this store.
 */
final class DocumentStore
{
    /** @var array<string, array{source: string, ast: ?list<Node\Stmt>}> */
    private array $documents = [];

    public function open(string $uri, string $source): void
    {
        $this->documents[$uri] = ['source' => $source, 'ast' => null];
    }

    public function change(string $uri, string $source): void
    {
        if (!isset($this->documents[$uri])) {
            $this->open($uri, $source);
            return;
        }
        $this->documents[$uri]['source'] = $source;
        $this->documents[$uri]['ast'] = null;  // invalidate parsed AST until next analyze
    }

    public function close(string $uri): void
    {
        unset($this->documents[$uri]);
    }

    public function source(string $uri): ?string
    {
        return $this->documents[$uri]['source'] ?? null;
    }

    /**
     * @param list<Node\Stmt> $ast
     */
    public function cacheAst(string $uri, array $ast): void
    {
        if (!isset($this->documents[$uri])) {
            return;
        }
        $this->documents[$uri]['ast'] = $ast;
    }

    /**
     * @return list<Node\Stmt>|null
     */
    public function ast(string $uri): ?array
    {
        return $this->documents[$uri]['ast'] ?? null;
    }

    /**
     * @return list<string>
     */
    public function openUris(): array
    {
        return array_keys($this->documents);
    }
}
