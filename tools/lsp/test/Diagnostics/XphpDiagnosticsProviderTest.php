<?php

declare(strict_types=1);

namespace XPHP\Lsp\Test\Diagnostics;

use Amp\CancellationTokenSource;
use PhpParser\ParserFactory;
use Phpactor\LanguageServer\Core\Workspace\Workspace as PhpactorWorkspace;
use Phpactor\LanguageServerProtocol\Diagnostic as LspDiagnostic;
use Phpactor\LanguageServerProtocol\TextDocumentItem;
use PHPUnit\Framework\TestCase;
use XPHP\Lsp\Analyzer\Analyzer;
use XPHP\Lsp\Analyzer\WorkspaceAnalyzer;
use XPHP\Lsp\Diagnostics\XphpDiagnosticsProvider;
use XPHP\Transpiler\Monomorphize\XphpSourceParser;
use function Amp\Promise\wait;

/**
 * Provider-level tests: feed in TextDocumentItems, assert the LSP-shaped diagnostics
 * the provider returns. Skips the JSON-RPC transport; that's covered separately by
 * LspIntegrationTest.
 */
final class XphpDiagnosticsProviderTest extends TestCase
{
    public function testCleanSingleDocumentReturnsNoDiagnostics(): void
    {
        $workspace = new PhpactorWorkspace();
        $clean = $this->openDoc($workspace, '/clean.xphp', <<<'XPHP'
        <?php
        namespace App;
        class Box<T> { public T $item; }
        XPHP);

        $diagnostics = $this->lint($workspace,$clean);
        self::assertSame([], $diagnostics);
    }

    public function testSyntaxErrorYieldsSingleDiagnostic(): void
    {
        $workspace = new PhpactorWorkspace();
        $broken = $this->openDoc($workspace, '/broken.xphp', <<<'XPHP'
        <?php
        function broken( {
        XPHP);

        $diagnostics = $this->lint($workspace,$broken);
        self::assertCount(1, $diagnostics);
        self::assertSame('xphp', $diagnostics[0]->source);
        self::assertSame('xphp.parse', $diagnostics[0]->code);
        self::assertStringContainsString('Syntax error', $diagnostics[0]->message);
    }

    public function testBoundViolationAcrossOpenDocumentsAttachesToInstantiationFile(): void
    {
        $workspace = new PhpactorWorkspace();
        $this->openDoc($workspace, '/Box.xphp', <<<'XPHP'
        <?php
        namespace App;
        class Box<T: \Stringable>
        {
            public T $item;
        }
        XPHP);
        $useDoc = $this->openDoc($workspace, '/Use.xphp', <<<'XPHP'
        <?php
        namespace App;
        $x = new Box<int>();
        XPHP);

        $diagnostics = $this->lint($workspace,$useDoc);
        self::assertCount(1, $diagnostics);
        self::assertSame('xphp.bound', $diagnostics[0]->code);
        self::assertStringContainsString('Generic bound violated', $diagnostics[0]->message);
        // And the Box.xphp side carries no diagnostic for itself.
        $boxItem = $workspace->get('/Box.xphp');
        $boxDiagnostics = $this->lint($workspace,$boxItem);
        self::assertSame([], $boxDiagnostics);
    }

    public function testProviderNameMatchesEngineRegistration(): void
    {
        // DiagnosticsEngine keys provider state by name; mismatch silently breaks clear-on-update.
        $provider = $this->newProvider(new PhpactorWorkspace());
        self::assertSame('xphp', $provider->name());
    }

    /**
     * @return list<LspDiagnostic>
     */
    private function lint(PhpactorWorkspace $workspace, TextDocumentItem $textDocument): array
    {
        $provider = $this->newProvider($workspace);
        $cancel = (new CancellationTokenSource())->getToken();
        $promise = $provider->provideDiagnostics($textDocument, $cancel);
        $result = wait($promise);
        return is_array($result) ? array_values($result) : [];
    }

    private function newProvider(PhpactorWorkspace $workspace): XphpDiagnosticsProvider
    {
        $parser = new XphpSourceParser((new ParserFactory())->createForHostVersion());
        return new XphpDiagnosticsProvider(
            new Analyzer($parser),
            new WorkspaceAnalyzer(),
            $workspace,
        );
    }

    private function openDoc(PhpactorWorkspace $workspace, string $uri, string $text): TextDocumentItem
    {
        $item = new TextDocumentItem($uri, 'xphp', 1, $text);
        $workspace->open($item);
        return $item;
    }
}
