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

    /**
     * Background-warmer hook: stash a parsed result against `$uri` UNLESS
     * an entry already exists.  The "if-absent" semantic is load-bearing
     * -- the warmer fires after `initialized` and the user may already
     * have opened a file by then; `getOrParse` would overwrite the
     * version-N open-doc entry with a stale version-0 disk-side parse.
     *
     * Cached at the sentinel version 0 -- LSP-issued versions start at 1
     * (per the protocol's `TextDocumentItem.version` semantics), so a
     * subsequent `didOpen` flow always sees a version mismatch on the
     * first read and reparses with the live in-memory text.  No callers
     * need to know about the sentinel.
     *
     * Used by {@see \XPHP\Lsp\Analyzer\ParsedDocumentCacheWarmer} to
     * pre-populate ASTs for every filesystem-indexed `file://` URI so
     * that the cold first reference search doesn't pay the per-file
     * parse cost in-band.
     */
    public function seedIfAbsent(string $uri, string $source): void
    {
        if (isset($this->entries[$uri])) {
            return;
        }
        $result = $this->analyzer->analyzeFile($source);
        $this->entries[$uri] = ['version' => 0, 'result' => $result];
    }

    /**
     * Cache-hit lookup for callers that want to avoid parsing on miss --
     * the filesystem-pass branch of {@see \XPHP\Lsp\Resolver\ReferenceFinder}
     * needs to know "do I have a warmed parse for this URI?" without
     * paying for an Analyzer round-trip if not.  Returns null on miss.
     */
    public function peek(string $uri): ?ParseResult
    {
        return $this->entries[$uri]['result'] ?? null;
    }

    /**
     * Drop every entry seeded by the warmer / filesystem pass
     * (version sentinel 0).  Counterpart to {@see \XPHP\Lsp\Reflection\FqnIndex::invalidateFilesystem}:
     * the file watcher calls both together on external `Changed` /
     * `Created` / `Deleted` events so the cache doesn't serve stale
     * ASTs from before the change.
     *
     * Open-doc entries (LSP-versioned starting at 1, refreshed via
     * `didChange` version bumps) are left alone -- their staleness is
     * already handled by the existing version-mismatch reparse in
     * {@see getOrParse}.  Distinguishing by `version === 0` rather
     * than by URI prefix is load-bearing: open docs also use
     * `file://` URIs, so a prefix filter would drop them too.
     *
     * @return int number of dropped entries (Stderr-facing for the
     *             watch handler's observability line)
     */
    public function forgetFilesystem(): int
    {
        $dropped = 0;
        foreach ($this->entries as $uri => $entry) {
            if ($entry['version'] === 0) {
                unset($this->entries[$uri]);
                $dropped++;
            }
        }
        return $dropped;
    }
}
