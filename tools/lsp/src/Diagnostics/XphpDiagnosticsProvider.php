<?php

declare(strict_types=1);

namespace XPHP\Lsp\Diagnostics;

use Amp\CancellationToken;
use Amp\Promise;
use Amp\Success;
use Phpactor\LanguageServer\Core\Diagnostics\DiagnosticsProvider;
use Phpactor\LanguageServer\Core\Workspace\Workspace as PhpactorWorkspace;
use Phpactor\LanguageServerProtocol\Diagnostic as LspDiagnostic;
use Phpactor\LanguageServerProtocol\TextDocumentItem;
use XPHP\Lsp\Analyzer\Analyzer;
use XPHP\Lsp\Analyzer\WorkspaceAnalyzer;

/**
 * Bridges the xphp analyzer (per-file + cross-file) to phpactor's diagnostics engine.
 *
 * Strategy:
 *   1. Parse the document being linted via the per-file Analyzer. If it has syntax
 *      errors we surface those and skip the workspace pass (the AST is unusable).
 *   2. Parse every other currently-open document in the phpactor workspace via the
 *      same Analyzer. Drop any that fail to parse — they get their own diagnostics
 *      when they're individually re-linted.
 *   3. Run the WorkspaceAnalyzer over the parsed set. It catches bound violations,
 *      duplicate templates, and hash collisions.
 *   4. Return only the diagnostics keyed to the document we were asked about.
 *      Cross-file violations on OTHER documents will get re-published when those
 *      documents themselves are re-linted (debounce-driven; an explicit broadcast
 *      pass is a follow-up).
 *
 * Translates framework-neutral Diagnostic → LSP-wire Diagnostic at the boundary
 * via DiagnosticTranslator.
 */
final class XphpDiagnosticsProvider implements DiagnosticsProvider
{
    public function __construct(
        private readonly Analyzer $analyzer,
        private readonly WorkspaceAnalyzer $workspaceAnalyzer,
        private readonly PhpactorWorkspace $workspace,
    ) {
    }

    public function provideDiagnostics(TextDocumentItem $textDocument, CancellationToken $cancel): Promise
    {
        $diagnostics = $this->analyze($textDocument);
        return new Success($diagnostics);
    }

    public function name(): string
    {
        return 'xphp';
    }

    /**
     * @return list<LspDiagnostic>
     */
    private function analyze(TextDocumentItem $textDocument): array
    {
        $currentUri = $textDocument->uri;

        // Per-file syntax pass on the document being linted.
        $currentResult = $this->analyzer->analyzeFile($textDocument->text);
        $perFileDiagnostics = array_map(
            static fn ($d) => DiagnosticTranslator::toLsp($d),
            $currentResult->diagnostics,
        );

        // If the document didn't parse, the workspace pass would skip it anyway —
        // and feeding it through TypeHierarchy::fromAstPerFile() with a null AST
        // is meaningless. Return the syntax diagnostics alone.
        if ($currentResult->ast === null) {
            return $perFileDiagnostics;
        }

        // Build the {uri: {ast, source}} map for the workspace pass: the current
        // document plus every OTHER open document that we can parse cleanly.
        $parsedFiles = [
            $currentUri => ['ast' => $currentResult->ast, 'source' => $textDocument->text],
        ];
        foreach ($this->workspace as $uri => $item) {
            if ($uri === $currentUri) {
                continue;
            }
            $otherResult = $this->analyzer->analyzeFile($item->text);
            if ($otherResult->ast === null) {
                continue;
            }
            $parsedFiles[$uri] = ['ast' => $otherResult->ast, 'source' => $item->text];
        }

        $workspaceByUri = $this->workspaceAnalyzer->analyze($parsedFiles);
        $currentWorkspaceDiagnostics = $workspaceByUri[$currentUri] ?? [];

        $lspWorkspaceDiagnostics = array_map(
            static fn ($d) => DiagnosticTranslator::toLsp($d),
            $currentWorkspaceDiagnostics,
        );

        return array_merge($perFileDiagnostics, $lspWorkspaceDiagnostics);
    }
}
