<?php

declare(strict_types=1);

namespace XPHP\Lsp\Handler;

use Amp\CancellationToken;
use Amp\Promise;
use Amp\Success;
use Phpactor\LanguageServer\Core\Handler\CanRegisterCapabilities;
use Phpactor\LanguageServer\Core\Handler\Handler;
use Phpactor\LanguageServer\Core\Workspace\Workspace as PhpactorWorkspace;
use Phpactor\LanguageServerProtocol\ServerCapabilities;
use Phpactor\LanguageServerProtocol\TextDocumentItem;
use XPHP\Lsp\Diagnostics\XphpDiagnosticsProvider;

/**
 * `textDocument/diagnostic` handler — LSP 3.17 pull-mode diagnostics.
 *
 * Modern clients (PhpStorm 2026.1+) prefer asking the server for
 * diagnostics on demand instead of waiting for push notifications.
 * Predictable update timing (no debounce window in the middle), no
 * missed updates after a long-suspended laptop wakes up, and the
 * client can re-request after focus / configuration changes without
 * forcing a didChange round-trip.
 *
 * The server still supports push-mode via `XphpDiagnosticsProvider`
 * so older clients connect cleanly; both modes share the same
 * analysis pass through `analyzeSync`.
 *
 * Returns a raw-array `DocumentDiagnosticReport` because the
 * vendored `phpactor/language-server-protocol` (v3.5) lacks the
 * LSP 3.17 typed shapes (`DocumentDiagnosticReport`,
 * `DocumentDiagnosticParams`, `DiagnosticOptions`).  The phpactor
 * serializer accepts the raw array form, matching the same pattern
 * `XphpCallHierarchyHandler::prepare` uses for its raw-array params.
 */
final class XphpPullDiagnosticsHandler implements Handler, CanRegisterCapabilities
{
    public function __construct(
        private readonly PhpactorWorkspace $workspace,
        private readonly XphpDiagnosticsProvider $provider,
    ) {
    }

    public function methods(): array
    {
        return ['textDocument/diagnostic' => 'diagnostic'];
    }

    public function registerCapabiltiies(ServerCapabilities $capabilities): void
    {
        // DiagnosticOptions per LSP 3.17:
        //   identifier?: string             (omitted — we have only one provider)
        //   interFileDependencies: bool     (true — workspace bound check spans files)
        //   workspaceDiagnostics: bool      (false — we don't implement workspace/diagnostic)
        $capabilities->diagnosticProvider = [
            'interFileDependencies' => true,
            'workspaceDiagnostics' => false,
        ];
    }

    /**
     * `DocumentDiagnosticParams` is `{textDocument}` -- the framework's
     * PassThroughArgumentResolver splits the JSON-RPC params object
     * into positional arguments (one per top-level key) and spreads
     * them via `(...$args)` in HandlerMethodRunner.  The first
     * positional value is therefore the textDocument dict, NOT the
     * full params object.  Signature mirrors that splat order so PHP
     * receives the right value.
     *
     * @param array{uri?: string, ...} $textDocument the inner
     *                                  TextDocumentIdentifier dict
     * @return Promise<array{kind: string, items: list<\Phpactor\LanguageServerProtocol\Diagnostic>}>
     */
    public function diagnostic(array $textDocument, ?CancellationToken $cancel = null): Promise
    {
        if ($cancel !== null && $cancel->isRequested()) {
            return new Success(['kind' => 'full', 'items' => []]);
        }
        $uri = $textDocument['uri'] ?? null;
        if (!is_string($uri) || !$this->workspace->has($uri)) {
            return new Success(['kind' => 'full', 'items' => []]);
        }
        $item = $this->workspace->get($uri);
        $diagnostics = $this->provider->analyzeSync(new TextDocumentItem(
            $item->uri,
            $item->languageId,
            $item->version,
            $item->text,
        ));
        return new Success(['kind' => 'full', 'items' => $diagnostics]);
    }
}
