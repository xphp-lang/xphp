<?php

declare(strict_types=1);

namespace XPHP\Lsp\Handler;

use Amp\Promise;
use Amp\Success;
use Phpactor\LanguageServer\Core\Handler\Handler;
use Phpactor\LanguageServerProtocol\DidChangeWatchedFilesParams;
use XPHP\Lsp\Reflection\FqnIndex;

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
 * Strategy: bulk-invalidate.  Surgical per-file updates would save the
 * ~100ms rebuild cost on the next query, but they double the code path
 * (parse + merge vs. just re-walking) and the rebuild is already fast
 * enough that the next FqnIndex query (typically one keystroke later)
 * isn't perceived as a stall.
 */
final class XphpFileWatcherHandler implements Handler
{
    public function __construct(
        private readonly FqnIndex $fqnIndex,
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
        $count = count($params->changes);
        if ($count > 0) {
            @fwrite(STDERR, sprintf(
                "[xphp-lsp watch] invalidating filesystem index (%d change%s)\n",
                $count,
                $count === 1 ? '' : 's',
            ));
            $this->fqnIndex->invalidateFilesystem();
        }
        // LSP notifications don't have a response payload, but the
        // phpactor dispatcher still expects a Promise return -- resolve
        // with null to signal "handled, no result."
        return new Success(null);
    }
}
