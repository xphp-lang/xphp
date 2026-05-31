<?php

declare(strict_types=1);

namespace XPHP\Lsp\Handler;

use Amp\CancellationToken;
use Amp\Promise;
use Amp\Success;
use Phpactor\LanguageServer\Core\Handler\CanRegisterCapabilities;
use Phpactor\LanguageServer\Core\Handler\Handler;
use Phpactor\LanguageServer\Core\Workspace\Workspace as PhpactorWorkspace;
use Phpactor\LanguageServerProtocol\CodeAction;
use Phpactor\LanguageServerProtocol\CodeActionOptions;
use Phpactor\LanguageServerProtocol\CodeActionParams;
use Phpactor\LanguageServerProtocol\ServerCapabilities;
use XPHP\Lsp\PositionMap;
use XPHP\Lsp\Resolver\DiagnosticCodeActionProvider;
use XPHP\Lsp\Resolver\ImportCodeActionProvider;
use XPHP\Lsp\Resolver\OptimizeImportsCodeActionProvider;

/**
 * `textDocument/codeAction` handler.
 *
 * Surfaces lightbulb / Alt+Enter quick-fixes for the cursor's
 * range and any diagnostics in it.  Currently returns an empty
 * action list -- this is scaffolding so the LSP capability is
 * advertised correctly (clients suppress the lightbulb UI for
 * servers that don't advertise it, even if a future fix is
 * available).
 *
 * Concrete quick-fixes will land in follow-up commits, each tied
 * to a specific diagnostic code emitted by the analyzer (e.g.
 * "Did you mean ...?" for the undefined-bareword diagnostic from
 * commit 47a37fa).  Each fix will:
 *  1. Inspect `$params->context->diagnostics` for codes it handles.
 *  2. Build a CodeAction with a WorkspaceEdit (textEdits)
 *     OR a Command to execute server-side.
 *  3. Optionally defer the heavy lookup to
 *     `codeAction/resolve` via XphpCodeActionResolveHandler.
 *
 * Available since IntelliJ Platform 2023.2 (the codeAction
 * capability itself); 2024.2 for the `resolve` round-trip.
 */
final class XphpCodeActionHandler implements Handler, CanRegisterCapabilities
{
    public function __construct(
        private readonly PhpactorWorkspace $workspace,
        private readonly ImportCodeActionProvider $importProvider,
        private readonly DiagnosticCodeActionProvider $diagnosticProvider,
        private readonly OptimizeImportsCodeActionProvider $optimizeImportsProvider,
    ) {
    }

    public function methods(): array
    {
        return [
            'textDocument/codeAction' => 'codeAction',
        ];
    }

    public function registerCapabiltiies(ServerCapabilities $capabilities): void
    {
        $capabilities->codeActionProvider = new CodeActionOptions(
            // `resolveProvider: true` opts into the
            // `codeAction/resolve` round-trip so quick-fixes
            // can emit lightweight items up-front and defer
            // the actual WorkspaceEdit construction to the
            // moment the user accepts the action.
            resolveProvider: true,
        );
    }

    /**
     * @return Promise<list<CodeAction>>
     */
    public function codeAction(CodeActionParams $params, ?CancellationToken $cancel = null): Promise
    {
        if ($cancel !== null && $cancel->isRequested()) {
            return new Success([]);
        }
        $uri = $params->textDocument->uri;
        if (!$this->workspace->has($uri)) {
            return new Success([]);
        }
        $item = $this->workspace->get($uri);
        $positionMap = new PositionMap($item->text);
        $offset = $positionMap->positionToOffset(
            $params->range->start->line,
            $params->range->start->character,
        );
        $importActions = $this->importProvider->actionsAt($uri, $item->version, $item->text, $offset);
        $diagnosticActions = $this->diagnosticProvider->actionsFor(
            $uri,
            $item->version,
            $item->text,
            $params->context->diagnostics ?? [],
        );
        $optimizeActions = $this->optimizeImportsProvider->actionsFor($uri, $item->version, $item->text);
        return new Success(array_merge($importActions, $diagnosticActions, $optimizeActions));
    }
}
