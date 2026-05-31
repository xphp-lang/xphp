<?php

declare(strict_types=1);

namespace XPHP\Lsp\Handler;

use Amp\CancellationToken;
use Amp\Promise;
use Amp\Success;
use Phpactor\LanguageServer\Core\Handler\CanRegisterCapabilities;
use Phpactor\LanguageServer\Core\Handler\Handler;
use Phpactor\LanguageServer\Core\Workspace\Workspace as PhpactorWorkspace;
use Phpactor\LanguageServerProtocol\ReferenceParams;
use Phpactor\LanguageServerProtocol\ServerCapabilities;
use XPHP\Lsp\PositionMap;
use XPHP\Lsp\Resolver\ReferenceFinder;

/**
 * `textDocument/references` -- backs PhpStorm's "Find Usages" (Alt+F7)
 * and VS Code's "Find All References" (Shift+F12).
 *
 * Thin shim around `ReferenceFinder`: convert LSP `Position` to a byte
 * offset, delegate the walk, return Locations.  Capability advertised
 * as bool `true` per the IntelliJ-strict-encoding pattern shared with
 * hover, definition, documentSymbol, workspaceSymbol.
 */
final class XphpReferencesHandler implements Handler, CanRegisterCapabilities
{
    public function __construct(
        private readonly PhpactorWorkspace $workspace,
        private readonly ReferenceFinder $finder,
    ) {
    }

    public function methods(): array
    {
        return [
            'textDocument/references' => 'references',
        ];
    }

    public function registerCapabiltiies(ServerCapabilities $capabilities): void
    {
        $capabilities->referencesProvider = true;
    }

    /**
     * @return Promise<list<\Phpactor\LanguageServerProtocol\Location>>
     */
    public function references(ReferenceParams $params, ?CancellationToken $cancel = null): Promise
    {
        if ($cancel !== null && $cancel->isRequested()) {
            return new Success([]);
        }
        $uri = $params->textDocument->uri;
        if (!$this->workspace->has($uri)) {
            return new Success([]);
        }
        $item = $this->workspace->get($uri);
        $offset = (new PositionMap($item->text))->positionToOffset(
            $params->position->line,
            $params->position->character,
        );
        $includeDeclaration = $params->context->includeDeclaration ?? true;
        return new Success($this->finder->findReferences($uri, $offset, $includeDeclaration, $cancel));
    }
}
