<?php

declare(strict_types=1);

namespace XPHP\Lsp\Analyzer;

/**
 * Version-keyed AST cache. The handlers (hover, definition, completion,
 * diagnostics) used to call `Analyzer::analyzeFile($item->text)` directly on
 * every LSP request, re-parsing every open document every time. On a workspace
 * with N open docs that's O(N) parses per keystroke. nikic is fast (~ms per
 * file) so this was acceptable for small workspaces but degrades quickly past
 * 10-20 open documents — and completion fires this loop on every `<`.
 *
 * Cache invalidation is by-version only. LSP gives us `TextDocumentItem::version`
 * for free — phpactor bumps it on `didChange`. The next `getOrParse()` after
 * a change sees the new version and reparses; otherwise we serve from cache.
 * No explicit invalidation on didOpen/didChange is needed.
 *
 * `forget()` exists for `didClose`: drops the URI from the cache so the LSP
 * session doesn't grow unbounded across long editor sessions.
 */
final class ParsedDocumentCache
{
    /** @var array<string, array{version: int, result: ParseResult}> */
    private array $entries = [];

    public function __construct(private readonly Analyzer $analyzer)
    {
    }

    public function getOrParse(string $uri, int $version, string $source): ParseResult
    {
        $cached = $this->entries[$uri] ?? null;
        if ($cached !== null && $cached['version'] === $version) {
            return $cached['result'];
        }
        $result = $this->analyzer->analyzeFile($source);
        $this->entries[$uri] = ['version' => $version, 'result' => $result];
        return $result;
    }

    public function forget(string $uri): void
    {
        unset($this->entries[$uri]);
    }
}
