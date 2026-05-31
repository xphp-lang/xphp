<?php

declare(strict_types=1);

namespace XPHP\Lsp\Analyzer;

use Phpactor\LanguageServer\Core\Workspace\Workspace as PhpactorWorkspace;
use Phpactor\LanguageServer\Event\Initialized;
use Psr\EventDispatcher\ListenerProviderInterface;
use Throwable;
use XPHP\Lsp\Reflection\FqnIndex;
use XPHP\Lsp\Stderr;

use function Amp\asyncCall;

/**
 * Pre-parses every filesystem-indexed `.xphp` / `.php` file into
 * {@see ParsedDocumentCache} after the LSP `Initialized` event, so
 * that the cold first reference search ({@see \XPHP\Lsp\Resolver\ReferenceFinder::findReferences}'s
 * filesystem pass) doesn't pay the per-file parse cost in-band.
 *
 * Why this exists -- measured cold cost of a "Show references" click
 * on a 211-file workspace (prod log `xphp-20260530-133004-258.log`,
 * id=23): **7.5 seconds**, almost all of it parse + AST walk in the
 * filesystem pass.  Per-file ~35ms, dominated by parse.  With a warmed
 * AST cache, that drops to walk-only -- empirically 3-5x faster on the
 * playground and growing with workspace size.
 *
 * Pairs with [[FqnIndexWarmer]] (the FQN-index pre-warm) -- both fire
 * on the same `Initialized` event and run on `Amp\asyncCall` so they
 * don't block the initialize handshake.  The two warmers are
 * independent: FQN warming is cheap (~500ms walk, no parse), AST
 * warming is heavier (~2-3s on the playground) so we let them run
 * concurrently rather than chaining.
 *
 * Skips files that are already in the open-doc workspace -- those
 * are version-keyed, live, and would be overwritten by a stale
 * `version=0` warmed entry.  {@see ParsedDocumentCache::seedIfAbsent}
 * is a second line of defence (if-absent guard inside the cache),
 * but the workspace check here avoids the file read entirely for
 * open docs.
 *
 * @see ParsedDocumentCache::seedIfAbsent the sentinel-version seed
 * @see ParsedDocumentCache::forgetFilesystem invalidation pair
 */
final class ParsedDocumentCacheWarmer implements ListenerProviderInterface
{
    public function __construct(
        private readonly FqnIndex $fqnIndex,
        private readonly ParsedDocumentCache $cache,
        private readonly PhpactorWorkspace $workspace,
    ) {
    }

    /**
     * {@inheritDoc}
     */
    public function getListenersForEvent(object $event): iterable
    {
        if ($event instanceof Initialized) {
            return [[$this, 'warm']];
        }
        return [];
    }

    public function warm(Initialized $initialized): void
    {
        asyncCall($this->warmNow(...));
    }

    /**
     * Synchronous warm body, extracted from {@see warm} so unit tests
     * can drive it without yielding to the Amp event loop.  The
     * `asyncCall(...) + Delayed(N) wait` pattern is racy under
     * Infection's parallel workers -- a delay-based "wait for the
     * asyncCall to finish" sometimes returns before the body
     * completes, producing false-positive mutant escapes on the
     * `continue` inside the open-doc skip branch (the cache-write
     * mutation hadn't actually happened yet by assertion time).
     * Production callers continue to use `warm()`; this method is
     * purely a test-friendly handle.
     *
     * @internal
     */
    public function warmNow(): void
    {
        $warmed = 0;
        $skippedOpen = 0;
        $skippedUnreadable = 0;
        $skippedParseError = 0;
        foreach ($this->fqnIndex->indexedFilesystemPaths() as $path) {
            $uri = 'file://' . $path;
            if ($this->workspace->has($uri)) {
                $skippedOpen++;
                continue;
            }
            $source = @file_get_contents($path);
            if ($source === false) {
                $skippedUnreadable++;
                continue;
            }
            try {
                $this->cache->seedIfAbsent($uri, $source);
                $warmed++;
            } catch (Throwable) {
                // Analyzer throws on truly malformed input that not
                // even the tolerant parse path can swallow.  Counted
                // separately for the observability line; doesn't
                // abort the whole warm-up.
                $skippedParseError++;
            }
        }
        Stderr::write(sprintf(
            "[xphp-lsp warmer] parsed-doc cache warmed (%d file%s, skipped: %d open / %d unreadable / %d parse-error)\n",
            $warmed,
            $warmed === 1 ? '' : 's',
            $skippedOpen,
            $skippedUnreadable,
            $skippedParseError,
        ));
    }
}
