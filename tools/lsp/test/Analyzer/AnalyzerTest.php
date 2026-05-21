<?php

declare(strict_types=1);

namespace XPHP\Lsp\Test\Analyzer;

use PhpParser\ParserFactory;
use PHPUnit\Framework\TestCase;
use XPHP\Lsp\Analyzer\Analyzer;
use XPHP\Lsp\Analyzer\DiagnosticSeverity;
use XPHP\Transpiler\Monomorphize\XphpSourceParser;

final class AnalyzerTest extends TestCase
{
    public function testCleanFileReturnsAstAndNoDiagnostics(): void
    {
        $analyzer = self::buildAnalyzer();
        $result = $analyzer->analyzeFile(<<<'PHP'
        <?php
        declare(strict_types=1);
        namespace App;
        class Box<T> {
            public T $item;
        }
        PHP);

        self::assertSame([], $result->diagnostics);
        self::assertNotNull($result->ast);
    }

    public function testSyntaxErrorProducesDiagnosticWithNullAst(): void
    {
        // Unterminated string literal — unrecoverable parse error.
        $analyzer = self::buildAnalyzer();
        $result = $analyzer->analyzeFile(<<<'PHP'
        <?php
        $broken = "unterminated
        PHP);

        self::assertNull($result->ast, 'unrecoverable syntax error should null out the AST');
        self::assertCount(1, $result->diagnostics);
        self::assertSame('xphp.parse', $result->diagnostics[0]->code);
        self::assertSame(DiagnosticSeverity::Error, $result->diagnostics[0]->severity);
        self::assertStringContainsString('Syntax error', $result->diagnostics[0]->message);
    }

    public function testSyntaxErrorRangeReferencesAValidLine(): void
    {
        $analyzer = self::buildAnalyzer();
        $result = $analyzer->analyzeFile(<<<'PHP'
        <?php
        function broken( {
        PHP);

        self::assertCount(1, $result->diagnostics);
        $d = $result->diagnostics[0];
        // Diagnostic must point at a valid line in the document (0-based).
        self::assertGreaterThanOrEqual(0, $d->startLine);
        self::assertGreaterThanOrEqual($d->startLine, $d->endLine);
    }

    private static function buildAnalyzer(): Analyzer
    {
        return new Analyzer(new XphpSourceParser((new ParserFactory())->createForHostVersion()));
    }
}
