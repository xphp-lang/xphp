<?php

declare(strict_types=1);

namespace XPHP\Lsp\Handler;

use Amp\CancellationToken;
use Amp\Failure;
use Amp\Promise;
use Amp\Success;
use Phpactor\LanguageServer\Core\Handler\CanRegisterCapabilities;
use Phpactor\LanguageServer\Core\Handler\Handler;
use Phpactor\LanguageServer\Core\Rpc\ErrorCodes;
use Phpactor\LanguageServer\Core\Workspace\Workspace as PhpactorWorkspace;
use Phpactor\LanguageServerProtocol\RenameParams;
use Phpactor\LanguageServerProtocol\ServerCapabilities;
use RuntimeException;
use XPHP\Lsp\PositionMap;
use XPHP\Lsp\Resolver\InvalidRenameNameException;
use XPHP\Lsp\Resolver\RenameProvider;

/**
 * `textDocument/rename` -- backs PhpStorm's "Rename" refactoring
 * (Shift+F6) and VS Code's "Rename Symbol" (F2).
 *
 * Thin shim around `RenameProvider`: validate the request shape,
 * convert LSP `Position` to byte offset, delegate.  Invalid identifier
 * names surface as LSP error responses so the client shows a useful
 * message instead of silently failing.
 *
 * Capability advertised as bool `true` (NOT `RenameOptions`) for the
 * same IntelliJ-strict-encoding reason as every other capability we
 * advertise -- empty options objects encode as `[]` and IntelliJ
 * rejects them.
 */
final class XphpRenameHandler implements Handler, CanRegisterCapabilities
{
    public function __construct(
        private readonly PhpactorWorkspace $workspace,
        private readonly RenameProvider $provider,
    ) {
    }

    public function methods(): array
    {
        return [
            'textDocument/rename' => 'rename',
        ];
    }

    public function registerCapabiltiies(ServerCapabilities $capabilities): void
    {
        $capabilities->renameProvider = true;
    }

    /**
     * @return Promise<\Phpactor\LanguageServerProtocol\WorkspaceEdit|null>
     */
    public function rename(RenameParams $params, ?CancellationToken $cancel = null): Promise
    {
        if ($cancel !== null && $cancel->isRequested()) {
            return new Success(null);
        }
        $uri = $params->textDocument->uri;
        if (!$this->workspace->has($uri)) {
            return new Success(null);
        }
        $item = $this->workspace->get($uri);
        $offset = (new PositionMap($item->text))->positionToOffset(
            $params->position->line,
            $params->position->character,
        );
        try {
            $edit = $this->provider->rename($uri, $offset, $params->newName, $cancel);
        } catch (InvalidRenameNameException $e) {
            return new Failure(new RuntimeException($e->getMessage(), ErrorCodes::InvalidParams));
        }
        return new Success($edit);
    }
}
