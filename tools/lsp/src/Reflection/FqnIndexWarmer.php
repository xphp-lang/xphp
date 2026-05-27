<?php

declare(strict_types=1);

namespace XPHP\Lsp\Reflection;

use Phpactor\LanguageServer\Event\Initialized;
use Psr\EventDispatcher\ListenerProviderInterface;
use XPHP\Lsp\Stderr;

use function Amp\asyncCall;

/**
 * Listens for phpactor's `Initialized` event and asynchronously warms
 * the {@see FqnIndex}'s filesystem walk so the first user-facing
 * request (hover, definition, completion) doesn't pay the ~500ms
 * scan cost in-band.
 *
 * Prod-log evidence (id=6 textDocument/definition, motivating
 * commit fix I):
 *
 *   00:53:32.941  request id=6 dispatched
 *   00:53:33.471  [xphp-lsp fqn-index] indexed 153 FQNs from 168 files
 *                 ^---  500ms inside the request
 *   00:53:36.163  response (~2.9s total -- partly because the walk
 *                 ran inside the request)
 *
 * Strategy: hook the `Initialized` event (fired by phpactor's
 * `InitializeMiddleware` after the client confirms it received the
 * `initialize` response), use `Amp\asyncCall` to dispatch the walk
 * onto the next event-loop tick.  This keeps the warm-up off the
 * synchronous initialize handshake while still landing well before
 * the user's first hover.
 *
 * Warm path: call any FqnIndex method that triggers
 * `filesystemMap()` and `filesystemKinds()`.  We use `allClassFqns()`
 * because it pulls BOTH (plus open-doc FQNs), so on first request
 * the resolver chain hits a fully-populated cache.
 *
 * Subscribed via `ServiceProviders` / `ListenerProviderInterface` --
 * same shape as `DidChangeWatchedFilesListener` (phpactor-shipped).
 * Wired into the event dispatcher in `LspDispatcherFactory::create`.
 */
class FqnIndexWarmer implements ListenerProviderInterface
{
    public function __construct(private readonly FqnIndex $fqnIndex)
    {
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
        asyncCall(function () {
            // allClassFqns() pulls both filesystem and open-doc data;
            // this single call warms every cache the resolver chain
            // touches on a first hover / definition / completion.
            $count = count($this->fqnIndex->allClassFqns());
            Stderr::write(sprintf(
                "[xphp-lsp warmer] fqn-index warmed (%d FQNs)\n",
                $count,
            ));
        });
    }
}
