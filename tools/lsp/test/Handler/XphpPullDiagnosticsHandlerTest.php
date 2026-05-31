<?php

declare(strict_types=1);

namespace XPHP\Lsp\Test\Handler;

use PhpParser\ParserFactory;
use Phpactor\LanguageServer\Core\Workspace\Workspace as PhpactorWorkspace;
use Phpactor\LanguageServerProtocol\Diagnostic;
use Phpactor\LanguageServerProtocol\ServerCapabilities;
use Phpactor\LanguageServerProtocol\TextDocumentItem;
use PHPUnit\Framework\TestCase;
use XPHP\Lsp\Analyzer\Analyzer;
use XPHP\Lsp\Analyzer\ParsedDocumentCache;
use XPHP\Lsp\Analyzer\WorkspaceAnalyzer;
use XPHP\Lsp\Diagnostics\XphpDiagnosticsProvider;
use XPHP\Lsp\Handler\XphpPullDiagnosticsHandler;
use XPHP\Lsp\Reflection\FqnIndex;
use XPHP\Transpiler\Monomorphize\XphpSourceParser;

use function Amp\Promise\wait;

final class XphpPullDiagnosticsHandlerTest extends TestCase
{
    public function testMethodsMapRegistersDiagnosticEndpoint(): void
    {
        $handler = $this->handler(new PhpactorWorkspace());
        self::assertArrayHasKey('textDocument/diagnostic', $handler->methods());
        self::assertSame('diagnostic', $handler->methods()['textDocument/diagnostic']);
    }

    public function testRegisterCapabilitiesAdvertisesDiagnosticProvider(): void
    {
        $handler = $this->handler(new PhpactorWorkspace());
        $capabilities = new ServerCapabilities();
        $handler->registerCapabiltiies($capabilities);

        self::assertIsArray($capabilities->diagnosticProvider);
        // LSP 3.17 DiagnosticOptions required fields.
        self::assertArrayHasKey('interFileDependencies', $capabilities->diagnosticProvider);
        self::assertTrue($capabilities->diagnosticProvider['interFileDependencies']);
        self::assertArrayHasKey('workspaceDiagnostics', $capabilities->diagnosticProvider);
        self::assertFalse($capabilities->diagnosticProvider['workspaceDiagnostics']);
    }

    public function testReturnsFullReportWithEmptyItemsForUnknownUri(): void
    {
        $handler = $this->handler(new PhpactorWorkspace());
        $report = wait($handler->diagnostic(['uri' => '/never-opened.xphp']));
        self::assertSame('full', $report['kind']);
        self::assertSame([], $report['items']);
    }

    public function testReturnsFullReportWithEmptyItemsForMissingUri(): void
    {
        // Malformed textDocument dict (no uri key) must still get a
        // well-formed empty report, not an exception.
        $handler = $this->handler(new PhpactorWorkspace());
        $report = wait($handler->diagnostic([]));
        self::assertSame('full', $report['kind']);
        self::assertSame([], $report['items']);
    }

    public function testReturnsFullReportWithEmptyItemsForNonStringUri(): void
    {
        // Locks the `is_string($uri)` guard.  An invalid textDocument
        // (uri is an int, etc.) still produces a well-formed empty
        // report.
        $handler = $this->handler(new PhpactorWorkspace());
        $report = wait($handler->diagnostic(['uri' => 42]));
        self::assertSame('full', $report['kind']);
        self::assertSame([], $report['items']);
    }

    public function testReturnsFullReportWithEmptyItemsWhenCancelRequested(): void
    {
        // Cancel-poll guard: a pre-cancelled token must short-circuit
        // without running analyzeSync.
        $workspace = new PhpactorWorkspace();
        $workspace->open(new TextDocumentItem('/parse-error.xphp', 'xphp', 1, '<?php class {'));
        $handler = $this->handler($workspace);

        $cancel = new \Amp\CancellationTokenSource();
        $cancel->cancel();

        $report = wait($handler->diagnostic(
            ['uri' => '/parse-error.xphp'],
            $cancel->getToken(),
        ));
        self::assertSame('full', $report['kind']);
        self::assertSame([], $report['items']);
    }

    public function testSurfacesProviderDiagnosticsForKnownUri(): void
    {
        // The shared `analyzeSync` path produces at least one diagnostic
        // for a document with a parse error -- the pull-handler must
        // surface it in the `items` array.
        $workspace = new PhpactorWorkspace();
        $workspace->open(new TextDocumentItem('/parse-error.xphp', 'xphp', 1, <<<'XPHP'
        <?php
        namespace App;
        class { // missing class name → parse error
        XPHP));
        $handler = $this->handler($workspace);

        $report = wait($handler->diagnostic(['uri' => '/parse-error.xphp']));
        self::assertSame('full', $report['kind']);
        self::assertNotEmpty($report['items'], 'parse-error document must surface at least one diagnostic');
        self::assertContainsOnlyInstancesOf(Diagnostic::class, $report['items']);
    }

    public function testReturnsFullReportWithEmptyItemsForCleanDocument(): void
    {
        // A document with no syntax / bound errors gets a 'full' report
        // with an empty items array.  Locks the round-trip from
        // analyzeSync (which returns []) through the handler shape.
        $workspace = new PhpactorWorkspace();
        $workspace->open(new TextDocumentItem('/clean.xphp', 'xphp', 1, <<<'XPHP'
        <?php
        namespace App;
        class Tag {}
        XPHP));
        $handler = $this->handler($workspace);

        $report = wait($handler->diagnostic(['uri' => '/clean.xphp']));
        self::assertSame('full', $report['kind']);
        self::assertSame([], $report['items']);
    }

    private function handler(PhpactorWorkspace $workspace): XphpPullDiagnosticsHandler
    {
        $parser = new XphpSourceParser((new ParserFactory())->createForHostVersion());
        $cache = new ParsedDocumentCache(new Analyzer($parser));
        $provider = new XphpDiagnosticsProvider(
            $cache,
            new WorkspaceAnalyzer(),
            $workspace,
            new FqnIndex($workspace, $cache, $parser, ''),
        );
        return new XphpPullDiagnosticsHandler($workspace, $provider);
    }
}
