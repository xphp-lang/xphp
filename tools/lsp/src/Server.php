<?php

declare(strict_types=1);

namespace XPHP\Lsp;

use PhpParser\ParserFactory;
use Phpactor\LanguageServer\LanguageServerBuilder;
use XPHP\Lsp\Analyzer\Analyzer;
use XPHP\Lsp\Analyzer\Diagnostic;
use XPHP\Lsp\Analyzer\WorkspaceAnalyzer;
use XPHP\Transpiler\Monomorphize\XphpSourceParser;

/**
 * Entry point for the xphp Language Server.
 *
 * Two modes:
 *
 *   xphp-lsp                       (no args)  → speak LSP over stdio
 *   xphp-lsp --lint <file> [...]              → one-shot analyzer; prints diagnostics
 *                                              and exits non-zero on errors
 *
 * The --lint mode is the analyzer's "headless" surface: CI can call it on every
 * .xphp file in a PR to surface diagnostics without standing up an LSP client.
 * It also doubles as the integration-test affordance for the LSP test suite.
 *
 * The stdio mode wires phpactor/language-server's LanguageServerBuilder
 * (see LspDispatcherFactory) and runs until the client closes the channel. The
 * dispatcher factory is intentionally separated from the analyzer so the analyzer
 * can be tested without spinning up a transport — see test/Analyzer/*.
 */
final class Server
{
    /**
     * @param list<string> $argv
     */
    public static function run(array $argv): int
    {
        if (in_array('--lint', $argv, true)) {
            return self::runLintMode($argv);
        }
        return self::runLspMode();
    }

    /**
     * @param list<string> $argv
     */
    private static function runLintMode(array $argv): int
    {
        $files = array_values(array_filter(
            array_slice($argv, 1),
            static fn (string $a): bool => $a !== '--lint' && !str_starts_with($a, '--'),
        ));
        if ($files === []) {
            Stderr::write( "Usage: xphp-lsp --lint <file.xphp> [<file.xphp> ...]\n");
            return 2;
        }

        $analyzer = self::buildAnalyzer();
        $workspaceAnalyzer = new WorkspaceAnalyzer();

        // Phase 1: per-file parse + syntax diagnostics.
        $perFileSyntax = [];
        $parsedFiles = [];
        foreach ($files as $path) {
            $source = @file_get_contents($path);
            if ($source === false) {
                Stderr::write( "{$path}: cannot read\n");
                return 2;
            }
            $result = $analyzer->analyzeFile($source);
            $perFileSyntax[$path] = $result->diagnostics;
            if ($result->ast !== null) {
                $parsedFiles[$path] = ['ast' => $result->ast, 'source' => $source];
            }
        }

        // Phase 2: workspace-level diagnostics across files that parsed cleanly.
        $workspaceDiagnostics = $workspaceAnalyzer->analyze($parsedFiles);

        $exitCode = 0;
        foreach ($files as $path) {
            $all = array_merge($perFileSyntax[$path] ?? [], $workspaceDiagnostics[$path] ?? []);
            foreach ($all as $d) {
                self::printDiagnostic($path, $d);
                $exitCode = 1;
            }
        }
        return $exitCode;
    }

    private static function runLspMode(): int
    {
        // Build phpactor's standard LSP server over stdio. The dispatcher factory
        // wires our XphpDiagnosticsProvider through phpactor's DiagnosticsEngine,
        // which handles enqueue / debounce / publishDiagnostics for us. The
        // LanguageServerBuilder defaults to stdio when no .tcpServer() is set.
        LanguageServerBuilder::create(new LspDispatcherFactory())
            ->build()
            ->run();
        return 0;
    }

    private static function buildAnalyzer(): Analyzer
    {
        return new Analyzer(new XphpSourceParser((new ParserFactory())->createForHostVersion()));
    }

    private static function printDiagnostic(string $path, Diagnostic $d): void
    {
        // Compiler-style "file:line:col: severity: message" output. Editors and CI
        // both grep this format.
        $line = $d->startLine + 1;
        $col = $d->startCharacter + 1;
        $sev = strtolower($d->severity->name);
        fwrite(STDOUT, "{$path}:{$line}:{$col}: {$sev}: [{$d->code->value}] {$d->message}\n");
    }
}
