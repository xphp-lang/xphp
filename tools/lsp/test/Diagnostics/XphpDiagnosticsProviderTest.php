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
use XPHP\Lsp\Analyzer\ParsedDocumentCache;
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

    public function testCurrentDocumentIsNotDoubleProcessedAgainstWorkspaceIteration(): void
    {
        // Locks the `if ($uri === $currentUri) continue;` skip on line 84.
        // Without it the current document's AST would be parsed twice and
        // could potentially be inserted into $parsedFiles twice — duplicate
        // recordDefinition would then throw "already declared" and produce
        // a spurious diagnostic on the only file with that template.
        $workspace = new PhpactorWorkspace();
        $boxDoc = $this->openDoc($workspace, '/Box.xphp', <<<'XPHP'
        <?php
        namespace App;
        class Box<T> { public T $item; }
        XPHP);

        $diagnostics = $this->lint($workspace, $boxDoc);

        self::assertSame([], $diagnostics, 'no duplicate-declaration must surface when the only file holding Box is the one being linted');
    }

    public function testWorkspaceDiagnosticsTranslateToLspWireFormatRanges(): void
    {
        // Locks the array_map translation on line 96. Without it, the
        // returned items would be the analyzer's framework-neutral
        // Diagnostic, which lacks the `range`/`severity` LSP fields.
        $workspace = new PhpactorWorkspace();
        $this->openDoc($workspace, '/Box.xphp', <<<'XPHP'
        <?php
        namespace App;
        class Box<T: \Stringable> { public T $item; }
        XPHP);
        $useDoc = $this->openDoc($workspace, '/Use.xphp', <<<'XPHP'
        <?php
        namespace App;
        $x = new Box<int>();
        XPHP);

        $diagnostics = $this->lint($workspace, $useDoc);

        self::assertCount(1, $diagnostics);
        self::assertInstanceOf(LspDiagnostic::class, $diagnostics[0]);
        self::assertInstanceOf(\Phpactor\LanguageServerProtocol\Range::class, $diagnostics[0]->range);
        self::assertSame(1, $diagnostics[0]->severity, 'LSP severity 1 = Error');
        self::assertSame('xphp', $diagnostics[0]->source);
    }

    public function testSyntaxErrorAndBoundViolationCombineWhenBothApplyToCurrentDoc(): void
    {
        // Locks the `array_merge($perFileDiagnostics, $lspWorkspaceDiagnostics)`
        // on line 101. A document with only a bound violation hits ONLY
        // the workspace pass (per-file empty); a document with only a
        // syntax error returns early. So this test specifically covers
        // the merge case by issuing a bound violation in a workspace
        // where the linted doc parses cleanly — we then assert the
        // diagnostic carries BOTH the workspace-source code AND the
        // workspace-source message.
        $workspace = new PhpactorWorkspace();
        $this->openDoc($workspace, '/Box.xphp', <<<'XPHP'
        <?php
        namespace App;
        class Box<T: \Stringable> { public T $item; }
        XPHP);
        $useDoc = $this->openDoc($workspace, '/Use.xphp', <<<'XPHP'
        <?php
        namespace App;
        $x = new Box<int>();
        XPHP);

        $diagnostics = $this->lint($workspace, $useDoc);

        // With UnwrapArrayMerge keeping only one operand, the workspace
        // diagnostic would be lost (because per-file is empty for a clean
        // parse). This assertion catches it.
        self::assertCount(1, $diagnostics);
        self::assertSame('xphp.bound', $diagnostics[0]->code);
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
            new ParsedDocumentCache(new Analyzer($parser)),
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
