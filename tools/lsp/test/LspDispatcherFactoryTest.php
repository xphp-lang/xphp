<?php

declare(strict_types=1);

namespace XPHP\Lsp\Test;

use Phpactor\LanguageServerProtocol\ClientCapabilities;
use Phpactor\LanguageServerProtocol\InitializeParams;
use Phpactor\LanguageServerProtocol\InitializeResult;
use Phpactor\LanguageServerProtocol\TextDocumentSyncKind;
use Phpactor\LanguageServerProtocol\WorkspaceClientCapabilities;
use Phpactor\LanguageServerProtocol\WorkspaceEditClientCapabilities;
use Phpactor\LanguageServer\Test\LanguageServerTester;
use PHPUnit\Framework\TestCase;
use XPHP\Lsp\LspDispatcherFactory;

/**
 * End-to-end test of the LSP wire flow using phpactor's LanguageServerTester. The
 * tester instantiates the dispatcher exactly the way the real server would and lets
 * us push JSON-RPC messages through it.
 *
 * Covers the load-bearing assertion from the plan: a synthetic `initialize` request
 * must round-trip and return a properly-shaped InitializeResult whose capabilities
 * announce textDocumentSync.FULL — without that, nothing downstream of the handshake
 * (didOpen, didChange, didSave, diagnostics) can possibly work.
 *
 * Engine-driven publishDiagnostics flow is covered by the smoke test in --lint mode
 * (Server::runLintMode) plus the per-provider unit tests in
 * XphpDiagnosticsProviderTest; a fully-async LSP-level diagnostics assertion needs
 * coordinated delays + event-loop pumping which is out of scope for this chunk.
 */
final class LspDispatcherFactoryTest extends TestCase
{
    public function testInitializeHandshakeReturnsServerCapabilities(): void
    {
        $tester = $this->buildTester();

        $result = $tester->initialize();

        self::assertInstanceOf(InitializeResult::class, $result);
        self::assertSame(
            TextDocumentSyncKind::FULL,
            $result->capabilities->textDocumentSync,
            'textDocumentSync must be FULL — XphpTextDocumentHandler registers this and the client needs it to know how to push updates',
        );
    }

    public function testServerInfoAdvertisesXphpName(): void
    {
        $tester = $this->buildTester();

        $result = $tester->initialize();

        self::assertNotNull($result->serverInfo);
        self::assertSame('xphp-lsp', $result->serverInfo['name'] ?? null);
    }

    public function testRenameProviderAdvertised(): void
    {
        // Phase 4.2 wires XphpRenameHandler.  Without this capability
        // PhpStorm's Rename refactoring (Shift+F6) won't route to the
        // LSP.  Same bool-not-options-object trick as every other
        // capability we advertise.
        $tester = $this->buildTester();

        $result = $tester->initialize();

        self::assertTrue(
            $result->capabilities->renameProvider,
            'renameProvider must be announced as bool true',
        );
    }

    public function testReferencesProviderAdvertised(): void
    {
        // Phase 4.1 wires XphpReferencesHandler.  Without this capability
        // PhpStorm's "Find Usages" (Alt+F7) won't even ask the LSP.  Same
        // bool-not-options-object trick used everywhere else.
        $tester = $this->buildTester();

        $result = $tester->initialize();

        self::assertTrue(
            $result->capabilities->referencesProvider,
            'referencesProvider must be announced as bool true',
        );
    }

    public function testWorkspaceSymbolProviderAdvertised(): void
    {
        // Phase 2.2 wires XphpWorkspaceSymbolHandler.  Without this
        // capability PhpStorm's "Go to Symbol" popup stays empty when it
        // queries the LSP for workspace-wide candidates.  Same bool-not-
        // options-object trick as hover / documentSymbol.
        $tester = $this->buildTester();

        $result = $tester->initialize();

        self::assertTrue(
            $result->capabilities->workspaceSymbolProvider,
            'workspaceSymbolProvider must be announced as bool true',
        );
    }

    public function testDocumentSymbolProviderAdvertised(): void
    {
        // Without this capability, clients won't issue
        // textDocument/documentSymbol and the Structure / "Go to Symbol in
        // File" UIs stay empty.  Phase 2.1 wires XphpDocumentSymbolHandler --
        // this assertion guards against a regression where the handler is
        // present but un-announced.
        $tester = $this->buildTester();

        $result = $tester->initialize();

        self::assertTrue(
            $result->capabilities->documentSymbolProvider,
            'documentSymbolProvider must be announced as bool true (NOT a DocumentSymbolOptions object -- IntelliJ rejects the empty-object encoding)',
        );
    }

    /**
     * @dataProvider clientSupportsRenameFileOpCases
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('clientSupportsRenameFileOpCases')]
    public function testClientSupportsRenameFileOpDetection(
        ?ClientCapabilities $capabilities,
        bool $expected,
    ): void {
        // Pins the `$initializeParams->capabilities?->workspace?->workspaceEdit?->resourceOperations ?? null`
        // chain plus the `is_array` / `in_array('rename', ...)` filter
        // against NullSafePropertyCall / FalseValue mutants.  See the
        // helper's docblock for the Cycle L init-option override that
        // was reverted after prod-test proved it self-defeating
        // (PhpStorm aborts the whole WorkspaceEdit when it sees an
        // unsupported resource op due to `failureHandling: "abort"`).
        $reflection = new \ReflectionClass(LspDispatcherFactory::class);
        $method = $reflection->getMethod('clientSupportsRenameFileOp');
        $method->setAccessible(true);

        $params = new InitializeParams($capabilities ?? new ClientCapabilities());
        // Force the capabilities to null when the case requests it
        // (InitializeParams' constructor doesn't accept null).
        if ($capabilities === null) {
            $params->capabilities = null;
        }

        self::assertSame($expected, $method->invoke(null, $params));
    }

    /**
     * @return iterable<string, array{ClientCapabilities|null, bool}>
     */
    public static function clientSupportsRenameFileOpCases(): iterable
    {
        $bareCaps = new ClientCapabilities();

        $emptyWorkspace = new ClientCapabilities();
        $emptyWorkspace->workspace = new WorkspaceClientCapabilities();

        $emptyWorkspaceEdit = new ClientCapabilities();
        $emptyWorkspaceEdit->workspace = new WorkspaceClientCapabilities();
        $emptyWorkspaceEdit->workspace->workspaceEdit = new WorkspaceEditClientCapabilities();

        $renameSupported = new ClientCapabilities();
        $renameSupported->workspace = new WorkspaceClientCapabilities();
        $renameSupported->workspace->workspaceEdit = new WorkspaceEditClientCapabilities();
        $renameSupported->workspace->workspaceEdit->resourceOperations = ['rename'];

        $createOnly = new ClientCapabilities();
        $createOnly->workspace = new WorkspaceClientCapabilities();
        $createOnly->workspace->workspaceEdit = new WorkspaceEditClientCapabilities();
        $createOnly->workspace->workspaceEdit->resourceOperations = ['create'];

        $renameAndCreate = new ClientCapabilities();
        $renameAndCreate->workspace = new WorkspaceClientCapabilities();
        $renameAndCreate->workspace->workspaceEdit = new WorkspaceEditClientCapabilities();
        $renameAndCreate->workspace->workspaceEdit->resourceOperations = ['create', 'rename', 'delete'];

        yield 'capabilities is null' => [null, false];
        yield 'workspace is null' => [$bareCaps, false];
        yield 'workspaceEdit is null' => [$emptyWorkspace, false];
        yield 'resourceOperations is null' => [$emptyWorkspaceEdit, false];
        yield 'resourceOperations is ["rename"]' => [$renameSupported, true];
        yield 'resourceOperations is ["create"] only' => [$createOnly, false];
        yield 'resourceOperations includes "rename"' => [$renameAndCreate, true];
    }

    public function testCodeLensCommandIsDispatchableViaExecuteCommandFallback(): void
    {
        // CodeLens emits `editor.action.showReferences` with
        // locations baked in; well-behaved clients (VS Code, LSP4IJ,
        // Helix) dispatch the command client-side and open Find
        // Usages directly -- no executeCommand request reaches the
        // server.  Any client that doesn't recognize the
        // convention falls back to `workspace/executeCommand` --
        // phpactor's CommandDispatcher would throw `Command "..."
        // not found` on an unregistered name and surface that as a
        // JSON-RPC error toast.  The dispatcher registers a
        // server-side no-op for the command name as a safety net so
        // the fallback path is silent.
        $tester = $this->buildTester();
        $tester->initialize();

        $response = \Amp\Promise\wait(
            $tester->workspace()->executeCommand(
                \XPHP\Lsp\Handler\XphpCodeLensHandler::COMMAND_NAME,
                ['file:///x.xphp', ['line' => 0, 'character' => 0], []],
            ),
        );

        self::assertNull($response->error, 'no JSON-RPC error from executeCommand');
    }

    private function buildTester(): LanguageServerTester
    {
        return new LanguageServerTester(
            new LspDispatcherFactory(),
            new InitializeParams(new ClientCapabilities()),
        );
    }
}
