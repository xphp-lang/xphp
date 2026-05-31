<?php

declare(strict_types=1);

namespace XPHP\Lsp\Handler;

use Amp\CancellationToken;
use Amp\Promise;
use Amp\Success;
use Phpactor\LanguageServer\Core\Handler\CanRegisterCapabilities;
use Phpactor\LanguageServer\Core\Handler\Handler;
use Phpactor\LanguageServer\Core\Workspace\Workspace as PhpactorWorkspace;
use Phpactor\LanguageServerProtocol\DocumentHighlight;
use Phpactor\LanguageServerProtocol\DocumentHighlightKind;
use Phpactor\LanguageServerProtocol\DocumentHighlightParams;
use Phpactor\LanguageServerProtocol\ServerCapabilities;
use XPHP\Lsp\PositionMap;
use XPHP\Lsp\Resolver\ReferenceFinder;

/**
 * `textDocument/documentHighlight` handler.
 *
 * In-file highlighting of every occurrence of the symbol under the
 * cursor.  Backs PhpStorm's "Highlight Usages in File" + the editor's
 * automatic "as you move the cursor" highlighting.  Strict subset of
 * `textDocument/references` -- we run the same resolver and filter to
 * the requesting document only.
 *
 * We don't distinguish read / write / text kind today; everything is
 * reported as `DocumentHighlightKind::TEXT`, which clients render with
 * the default highlight colour.  Read/write classification needs nikic
 * AST parent-walk (assignment LHS vs RHS); not implemented here
 * because the LSP spec marks kind as optional and PhpStorm renders
 * TEXT identically to the other kinds anyway.
 *
 * Available since IntelliJ Platform 2025.3.
 */
final class XphpDocumentHighlightHandler implements Handler, CanRegisterCapabilities
{
    public function __construct(
        private readonly PhpactorWorkspace $workspace,
        private readonly ReferenceFinder $finder,
    ) {
    }

    public function methods(): array
    {
        return [
            'textDocument/documentHighlight' => 'documentHighlight',
        ];
    }

    public function registerCapabiltiies(ServerCapabilities $capabilities): void
    {
        $capabilities->documentHighlightProvider = true;
    }

    /**
     * @return Promise<list<DocumentHighlight>>
     */
    public function documentHighlight(DocumentHighlightParams $params, ?CancellationToken $cancel = null): Promise
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

        // Always include the declaration -- the user expects every
        // mention of the symbol in the file to light up, including the
        // place they put their cursor.
        //
        // `restrictToUri: $uri` confines the scan to the requesting
        // document -- documentHighlight only renders single-file
        // results, and the prior unrestricted scan was the 2026-05-27
        // prod-log 2:43 stall (walking every indexed filesystem path,
        // each triggering worse-reflection on receiver-class inference,
        // only to discard the cross-file hits below).  Cross-file
        // matches stay available through textDocument/references.
        $locations = $this->finder->findReferences($uri, $offset, true, $cancel, $uri);

        $highlights = [];
        foreach ($locations as $location) {
            $highlights[] = new DocumentHighlight(
                range: $location->range,
                kind: DocumentHighlightKind::TEXT,
            );
        }
        return new Success($highlights);
    }
}
