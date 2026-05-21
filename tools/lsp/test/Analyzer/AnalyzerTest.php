<?php

declare(strict_types=1);

namespace XPHP\Lsp\Test\Analyzer;

use PhpParser\ParserFactory;
use PHPUnit\Framework\TestCase;
use XPHP\Lsp\Analyzer\Analyzer;
use XPHP\Lsp\Analyzer\DiagnosticCode;
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
        self::assertSame(DiagnosticCode::Parse, $result->diagnostics[0]->code);
        self::assertSame(DiagnosticSeverity::Error, $result->diagnostics[0]->severity);
        // The message MUST carry both the literal "Syntax error: " prefix AND
        // the parser's own error description. Locks the Concat mutation that
        // would drop either operand.
        self::assertStringStartsWith('Syntax error: ', $result->diagnostics[0]->message);
        self::assertGreaterThan(
            strlen('Syntax error: '),
            strlen($result->diagnostics[0]->message),
            'message must include the underlying parser detail, not just the literal prefix',
        );
    }

    // Note: the `catch (RuntimeException $e)` branch in Analyzer::analyzeFile
    // is defensive — XphpSourceParser only throws RuntimeException when its
    // underlying parser returns null, which the default nikic configuration
    // doesn't do (Throwing error handler is the default). Constructing a
    // failure scenario would need a custom parser, but XphpSourceParser is
    // `final` so we can't extend it. The catch block is ignored in
    // infection.json5 with documented rationale.

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
