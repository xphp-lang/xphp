<?php

declare(strict_types=1);

namespace XPHP\Lsp\Test;

use Phpactor\LanguageServerProtocol\ClientCapabilities;
use Phpactor\LanguageServerProtocol\InitializeParams;
use Phpactor\LanguageServerProtocol\InitializeResult;
use Phpactor\LanguageServerProtocol\TextDocumentSyncKind;
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

    private function buildTester(): LanguageServerTester
    {
        return new LanguageServerTester(
            new LspDispatcherFactory(),
            new InitializeParams(new ClientCapabilities()),
        );
    }
}
