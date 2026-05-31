<?php

declare(strict_types=1);

namespace XPHP\Lsp\Handler;

use Amp\Promise;
use Amp\Success;
use Phpactor\LanguageServer\Core\Handler\Handler;
use Phpactor\LanguageServer\Core\Workspace\Workspace as PhpactorWorkspace;
use Phpactor\LanguageServerProtocol\DidChangeWatchedFilesParams;
use Phpactor\LanguageServerProtocol\FileChangeType;
use XPHP\Lsp\Analyzer\ParsedDocumentCache;
use XPHP\Lsp\Reflection\FqnIndex;
use XPHP\Lsp\Stderr;

/**
 * `workspace/didChangeWatchedFiles` handler -- keeps `FqnIndex`'s lazy
 * filesystem cache honest across long editor sessions.
 *
 * Without this notification the filesystem walk is one-shot at first
 * query and stays frozen for the rest of the LSP lifetime; adding a new
 * .xphp file on disk (git checkout, IDE-side "New file", external tool)
 * would only surface in workspace symbol search and closed-file GTD
 * after restarting the server.
 *
 * Subscribed via `DidChangeWatchedFilesListener` -- the phpactor-shipped
 * listener does the `client/registerCapability` dance on the
 * `initialized` notification (PhpStorm + VS Code both advertise
 * `dynamicRegistration: true`).  The actual notification routing into
 * this handler is wired by the dispatcher's handler map.
 *
 * Strategy: invalidate ONLY for changes the open-doc layer can't see.
 *
 * When a `Changed` notification arrives for a file that's currently open
 * in the workspace, the open-doc layer has ALREADY been refreshed via
 * the preceding `textDocument/didChange` + `didSave` notifications.  The
 * FqnIndex consults open docs before the filesystem cache, so the
 * filesystem entry for that file is stale-but-unread -- invalidating it
 * forces a several-hundred-millisecond rebuild that no subsequent query
 * needed.
 *
 * Prod-log evidence (the case that motivated this narrowing):
 * `didChange` at 00:25:56.502, then `didChangeWatchedFiles` 0.3s later
 * at 00:25:56.780.  Pre-fix: invalidated the whole index -> 1.4s
 * rebuild on the next hover.  Post-fix: ignored (the file is open;
 * open-doc cache already serves the new text); the next hover hits a
 * still-warm index.
 *
 * External changes (`Created`, `Deleted`, or `Changed` to a file the
 * user hasn't opened) still trigger a bulk invalidation -- those are
 * the cases the watcher exists for.  Per-file surgical updates would
 * save the ~100ms rebuild on the next query but would require a
 * reverse FQN->path index that FqnIndex doesn't currently track; the
 * bulk re-walk is a fine fallback for the rare external-edit case.
 */
final class XphpFileWatcherHandler implements Handler
{
    public function __construct(
        private readonly FqnIndex $fqnIndex,
        private readonly PhpactorWorkspace $workspace,
        private readonly ParsedDocumentCache $parsedDocumentCache,
    ) {
    }

    public function methods(): array
    {
        return [
            'workspace/didChangeWatchedFiles' => 'didChangeWatchedFiles',
        ];
    }

    /**
     * @return Promise<null>
     */
    public function didChangeWatchedFiles(DidChangeWatchedFilesParams $params): Promise
    {
        $external = 0;
        $skippedOpen = 0;
        foreach ($params->changes as $change) {
            if ($change->type === FileChangeType::CHANGED && $this->workspace->has($change->uri)) {
                // The open-doc lifecycle already refreshed this file.
                $skippedOpen++;
                continue;
            }
            $external++;
        }

        if ($external > 0) {
            // Drop warmed AST cache entries alongside the FQN-index
            // invalidation: ParsedDocumentCacheWarmer seeded an entry
            // per indexed file at version 0, and a Changed/Created/
            // Deleted notification means the on-disk source diverged
            // from whatever the warmer parsed.  Without this, a stale
            // AST would survive in ParsedDocumentCache and the next
            // ReferenceFinder filesystem pass would serve outdated
            // results.  Open-doc entries (version >= 1) are left
            // alone -- the existing didChange version-bump path
            // handles those.
            $droppedCache = $this->parsedDocumentCache->forgetFilesystem();
            Stderr::write(sprintf(
                "[xphp-lsp watch] invalidating filesystem index (%d external change%s, %d open-doc skipped, %d cached AST%s dropped)\n",
                $external,
                $external === 1 ? '' : 's',
                $skippedOpen,
                $droppedCache,
                $droppedCache === 1 ? '' : 's',
            ));
            $this->fqnIndex->invalidateFilesystem();
        } elseif ($skippedOpen > 0) {
            Stderr::write(sprintf(
                "[xphp-lsp watch] skipped invalidation (%d open-doc change%s already covered)\n",
                $skippedOpen,
                $skippedOpen === 1 ? '' : 's',
            ));
        }
        // LSP notifications don't have a response payload, but the
        // phpactor dispatcher still expects a Promise return -- resolve
        // with null to signal "handled, no result."
        return new Success(null);
    }
}
