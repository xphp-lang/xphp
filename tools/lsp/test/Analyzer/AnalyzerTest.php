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

    public function testSyntaxErrorRangeIsColumnAccurateWhenColumnInfoIsAvailable(): void
    {
        // Locks the `$e->getStartColumn($source) - 1` / `$e->getEndColumn($source)`
        // arithmetic. Earlier the diagnostic always spanned a whole line; now
        // it pins to the actual offending token. nikic's columns are 1-based
        // inclusive; the LSP range is 0-based half-open — that's the `- 1` on
        // start and the absent `- 1` on end.
        //
        // Source: `<?php $x = ;` — the unexpected `;` is at column 12 (1-based).
        // LSP equivalent: start={0, 11}, end={0, 12} (1-character span).
        $analyzer = self::buildAnalyzer();
        $result = $analyzer->analyzeFile('<?php $x = ;');

        self::assertCount(1, $result->diagnostics);
        $d = $result->diagnostics[0];
        self::assertSame(0, $d->startLine);
        self::assertSame(11, $d->startCharacter, '1-based-to-0-based shift must hold');
        self::assertSame(12, $d->endCharacter, 'inclusive 1-based end + no shift = half-open 0-based end');
    }

    // --- undefined-name diagnostic (fix 3/5) ---------------------------

    public function testFlagsLowercaseUndefinedBarewordConstant(): void
    {
        // Real prod typo: `$x ?? nul` (should have been `null`).
        // The analyzer surfaces this as a Warning so the user sees
        // it before runtime, where PHP 8+ throws a fatal Error.
        $result = self::buildAnalyzer()->analyzeFile("<?php\n\$x = 1 ?? nul;");
        self::assertCount(1, $result->diagnostics);
        $d = $result->diagnostics[0];
        self::assertSame(DiagnosticCode::UndefinedName, $d->code);
        self::assertSame(DiagnosticSeverity::Warning, $d->severity);
        self::assertStringContainsString('nul', $d->message);
    }

    public function testDoesNotFlagPseudoConstants(): void
    {
        // `null`, `true`, `false` are PHP pseudo-constants -- silent.
        $result = self::buildAnalyzer()->analyzeFile(
            "<?php\n\$a = null;\n\$b = true;\n\$c = false;",
        );
        self::assertSame([], $result->diagnostics);
    }

    public function testDoesNotFlagUppercaseUserDefinedConstants(): void
    {
        // UPPER_SNAKE_CASE is the user-defined-constant convention.  The
        // LSP doesn't yet track those across the workspace, so flagging
        // them would false-positive on every define('FOO', ...) site.
        // Keep them silent.
        $result = self::buildAnalyzer()->analyzeFile(
            "<?php\necho PHP_EOL, MY_CONST, M_PI;",
        );
        $codes = array_map(fn ($d) => $d->code, $result->diagnostics);
        self::assertNotContains(DiagnosticCode::UndefinedName, $codes);
    }

    public function testDoesNotFlagQualifiedNames(): void
    {
        // \App\Foo style FQNs need namespace + workspace resolution we
        // don't have yet; punt rather than false-positive.
        $result = self::buildAnalyzer()->analyzeFile("<?php\nuse App\\Foo;\necho \\App\\Foo;");
        $codes = array_map(fn ($d) => $d->code, $result->diagnostics);
        self::assertNotContains(DiagnosticCode::UndefinedName, $codes);
    }

    public function testFlagsEachOccurrenceSeparately(): void
    {
        // Two distinct undefined names on different lines emit two
        // diagnostics so the editor underlines each.
        $result = self::buildAnalyzer()->analyzeFile(
            "<?php\n\$a = nul;\n\$b = oops;",
        );
        $undef = array_values(array_filter(
            $result->diagnostics,
            fn ($d) => $d->code === DiagnosticCode::UndefinedName,
        ));
        self::assertCount(2, $undef);
    }

    private static function buildAnalyzer(): Analyzer
    {
        return new Analyzer(new XphpSourceParser((new ParserFactory())->createForHostVersion()));
    }
}
