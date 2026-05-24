<?php

declare(strict_types=1);

namespace XPHP\Lsp\Test\Handler;

use Phpactor\LanguageServer\Adapter\Psr\AggregateEventDispatcher;
use Phpactor\LanguageServer\Core\Workspace\Workspace as PhpactorWorkspace;
use Phpactor\LanguageServer\Handler\TextDocument\TextDocumentHandler as VendorTextDocumentHandler;
use Phpactor\LanguageServer\Listener\WorkspaceListener;
use Phpactor\LanguageServerProtocol\DidChangeTextDocumentParams;
use Phpactor\LanguageServerProtocol\ServerCapabilities;
use Phpactor\LanguageServerProtocol\TextDocumentContentChangeFullEvent;
use Phpactor\LanguageServerProtocol\TextDocumentItem;
use Phpactor\LanguageServerProtocol\TextDocumentSyncKind;
use PHPUnit\Framework\TestCase;
use XPHP\Lsp\Handler\XphpTextDocumentHandler;

final class XphpTextDocumentHandlerTest extends TestCase
{
    /**
     * Smoking-gun assertion: hydrating the LSP didChange params via the
     * documented `fromArray` path produces a TextDocumentContentChangeFullEvent
     * OBJECT, not an array.  This is what the LanguageSeverProtocolParamsResolver
     * does on every incoming `textDocument/didChange` notification.  The vendor
     * TextDocumentHandler then does `$contentChange['text']` and PHP 8 raises
     * `Error: Cannot use object of type ... as array`.
     *
     * Pinning this shape stops a future upstream change (e.g. switching
     * contentChanges to a raw array map) from silently masking the regression
     * we're guarding against here.
     */
    public function testFromArrayHydratesContentChangesIntoObjectsNotArrays(): void
    {
        $params = DidChangeTextDocumentParams::fromArray([
            'textDocument' => ['uri' => 'file:///a.xphp', 'version' => 2],
            'contentChanges' => [['text' => 'hello']],
        ], true);

        self::assertCount(1, $params->contentChanges);
        $change = $params->contentChanges[0];
        self::assertInstanceOf(TextDocumentContentChangeFullEvent::class, $change);
        self::assertSame('hello', $change->text);
    }

    public function testVendorTextDocumentHandlerThrowsOnObjectContentChanges(): void
    {
        // Locks in the upstream bug: array-subscript on the FullEvent object
        // is what wipes out the entire didChange path in production.  When
        // phpactor releases a fix (and we upgrade), this test must be deleted
        // along with our XphpTextDocumentHandler -- the test failing is the
        // signal that the workaround is no longer needed.
        $workspace = new PhpactorWorkspace();
        $workspace->open(new TextDocumentItem('file:///a.xphp', 'xphp', 1, ''));
        $dispatcher = new AggregateEventDispatcher(new WorkspaceListener($workspace));
        $vendor = new VendorTextDocumentHandler($dispatcher);

        $params = DidChangeTextDocumentParams::fromArray([
            'textDocument' => ['uri' => 'file:///a.xphp', 'version' => 2],
            'contentChanges' => [['text' => '$repo->']],
        ], true);

        self::expectException(\Error::class);
        self::expectExceptionMessageMatches('/Cannot use object .* as array/');
        $vendor->didChange($params);
    }

    public function testOurDidChangeUpdatesWorkspaceWhenContentChangesAreObjects(): void
    {
        $workspace = new PhpactorWorkspace();
        $workspace->open(new TextDocumentItem('file:///a.xphp', 'xphp', 1, ''));
        $dispatcher = new AggregateEventDispatcher(new WorkspaceListener($workspace));
        $handler = new XphpTextDocumentHandler($dispatcher);

        $params = DidChangeTextDocumentParams::fromArray([
            'textDocument' => ['uri' => 'file:///a.xphp', 'version' => 2],
            'contentChanges' => [['text' => '$repo->']],
        ], true);

        $handler->didChange($params);

        $doc = $workspace->get('file:///a.xphp');
        self::assertSame('$repo->', $doc->text);
        self::assertSame(2, $doc->version);
    }

    public function testOurDidChangeAlsoWorksWithRawArrayContentChanges(): void
    {
        // Belt-and-braces: if a future protocol revision delivers contentChanges
        // as raw arrays (or some intermediate framework reverts the hydration),
        // we still update.  Mirrors the vendor's original array-subscript shape.
        $workspace = new PhpactorWorkspace();
        $workspace->open(new TextDocumentItem('file:///b.xphp', 'xphp', 1, ''));
        $dispatcher = new AggregateEventDispatcher(new WorkspaceListener($workspace));
        $handler = new XphpTextDocumentHandler($dispatcher);

        // Skip fromArray hydration and pass raw arrays directly via reflection.
        $params = new DidChangeTextDocumentParams(
            new \Phpactor\LanguageServerProtocol\VersionedTextDocumentIdentifier(3, 'file:///b.xphp'),
            [['text' => 'raw shape']],
        );

        $handler->didChange($params);

        self::assertSame('raw shape', $workspace->get('file:///b.xphp')->text);
    }

    public function testRegisterCapabilitiesAdvertisesFullTextDocumentSync(): void
    {
        $handler = new XphpTextDocumentHandler(new AggregateEventDispatcher());
        $capabilities = new ServerCapabilities();

        $handler->registerCapabiltiies($capabilities);

        self::assertSame(TextDocumentSyncKind::FULL, $capabilities->textDocumentSync);
    }
}
