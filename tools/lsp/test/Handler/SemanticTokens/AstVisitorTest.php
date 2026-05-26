<?php

declare(strict_types=1);

namespace XPHP\Lsp\Test\Handler\SemanticTokens;

use PhpParser\ParserFactory;
use PHPUnit\Framework\TestCase;
use XPHP\Lsp\Handler\SemanticTokens\AstVisitor;
use XPHP\Lsp\Handler\SemanticTokens\TokenSpec;
use XPHP\Lsp\PositionMap;
use XPHP\Transpiler\Monomorphize\XphpSourceParser;

/**
 * Tests the token classification table for {@see AstVisitor}.
 *
 * Each test feeds a snippet and asserts that the visitor emits a
 * TokenSpec covering the expected substring at the expected
 * classification.  Position assertions use byte offsets via
 * {@see findByOffset}; classification assertions use
 * {@see assertTokenAt}.
 */
final class AstVisitorTest extends TestCase
{
    // --- Pass 1: tokens ---------------------------------------------------

    public function testKeywordsAreClassified(): void
    {
        $source = <<<'XPHP'
        <?php
        namespace App;
        class Foo {
            public function bar(): void { return; }
        }
        XPHP;
        $specs = $this->collect($source);

        $this->assertTokenSubstring($specs, $source, 'namespace', 'keyword');
        $this->assertTokenSubstring($specs, $source, 'class', 'keyword');
        $this->assertTokenSubstring($specs, $source, 'public', 'keyword');
        $this->assertTokenSubstring($specs, $source, 'function', 'keyword');
        $this->assertTokenSubstring($specs, $source, 'return', 'keyword');
    }

    public function testVariablesAreClassified(): void
    {
        $source = "<?php\n\$name = 1;";
        $specs = $this->collect($source);
        $this->assertTokenSubstring($specs, $source, '$name', 'variable');
    }

    public function testNumbersAreClassified(): void
    {
        $source = "<?php\n\$x = 42;\n\$y = 3.14;";
        $specs = $this->collect($source);
        $this->assertTokenSubstring($specs, $source, '42', 'number');
        $this->assertTokenSubstring($specs, $source, '3.14', 'number');
    }

    public function testSingleQuotedStringIsClassifiedAsString(): void
    {
        $source = "<?php\n\$x = 'hello';";
        $specs = $this->collect($source);
        $this->assertTokenSubstring($specs, $source, "'hello'", 'string');
    }

    public function testLineCommentIsClassifiedAsComment(): void
    {
        $source = "<?php\n// a comment\n\$x = 1;";
        $specs = $this->collect($source);
        $this->assertTokenSubstring($specs, $source, '// a comment', 'comment');
    }

    public function testBlockCommentIsClassifiedAsComment(): void
    {
        $source = "<?php\n/* block */\n\$x = 1;";
        $specs = $this->collect($source);
        $this->assertTokenSubstring($specs, $source, '/* block */', 'comment');
    }

    public function testDocCommentIsClassifiedAsComment(): void
    {
        $source = "<?php\n/** doc */\nclass X {}";
        $specs = $this->collect($source);
        $this->assertTokenSubstring($specs, $source, '/** doc */', 'comment');
    }

    // --- Pass 2: AST -------------------------------------------------------

    public function testClassNameIsClassified(): void
    {
        $source = "<?php\nnamespace App;\nclass User {}";
        $specs = $this->collect($source);
        $this->assertTokenSubstring($specs, $source, 'User', 'class');
    }

    public function testInterfaceNameIsClassifiedAsInterface(): void
    {
        $source = "<?php\nnamespace App;\ninterface Greeter {}";
        $specs = $this->collect($source);
        $this->assertTokenSubstring($specs, $source, 'Greeter', 'interface');
    }

    public function testEnumNameIsClassifiedAsEnum(): void
    {
        $source = "<?php\nnamespace App;\nenum Status { case Active; }";
        $specs = $this->collect($source);
        $this->assertTokenSubstring($specs, $source, 'Status', 'enum');
    }

    public function testMethodNameIsClassifiedAsMethod(): void
    {
        $source = <<<'XPHP'
        <?php
        namespace App;
        class Foo { public function bar(): void {} }
        XPHP;
        $specs = $this->collect($source);
        $this->assertTokenSubstring($specs, $source, 'bar', 'method');
    }

    public function testTopLevelFunctionNameIsClassifiedAsFunction(): void
    {
        $source = <<<'XPHP'
        <?php
        namespace App;
        function greet(): void {}
        XPHP;
        $specs = $this->collect($source);
        $this->assertTokenSubstring($specs, $source, 'greet', 'function');
    }

    public function testParameterIsRelassifiedFromVariableToParameter(): void
    {
        // Token-scan pass emits `variable` at `$name`; AST pass adds a
        // `parameter` spec at the same position.  Assert both are
        // present -- the client treats the later one (parameter) as
        // canonical.
        $source = <<<'XPHP'
        <?php
        namespace App;
        function greet(string $name): void {}
        XPHP;
        $specs = $this->collect($source);

        $atName = array_values(array_filter(
            $specs,
            fn (TokenSpec $s) => self::substring($source, $s) === '$name',
        ));
        self::assertNotEmpty($atName, 'expected at least one spec at `$name`');
        $types = array_map(static fn (TokenSpec $s) => $s->type, $atName);
        self::assertContains('parameter', $types, 'param re-classification did not fire');
    }

    // --- Edge cases --------------------------------------------------------

    public function testEmptyFileEmitsNoSpecs(): void
    {
        $source = '';
        $specs = $this->collect($source);
        self::assertSame([], $specs);
    }

    public function testSourceWithOnlyOpenTagDoesNotCrash(): void
    {
        $source = '<?php';
        $specs = $this->collect($source);
        // Just the open tag keyword.
        self::assertNotEmpty($specs);
    }

    public function testCommentBeforeClassDeclaration(): void
    {
        $source = <<<'XPHP'
        <?php
        // Header.
        class X {}
        XPHP;
        $specs = $this->collect($source);
        $this->assertTokenSubstring($specs, $source, '// Header.', 'comment');
        $this->assertTokenSubstring($specs, $source, 'class', 'keyword');
        $this->assertTokenSubstring($specs, $source, 'X', 'class');
    }

    public function testXphpAngleBracketStripDoesNotMisalignAstPositions(): void
    {
        // Box<T> -- nikic parses the STRIPPED source ("class Box {").
        // ByteOffsetMap must translate AST positions back to the
        // original buffer so `Box`'s emitted span lines up.
        $source = <<<'XPHP'
        <?php
        namespace App;
        class Box<T> {}
        XPHP;
        $specs = $this->collect($source);
        $this->assertTokenSubstring($specs, $source, 'Box', 'class');
    }

    // --- helpers -----------------------------------------------------------

    /**
     * @return list<TokenSpec>
     */
    private function collect(string $source): array
    {
        $parser = new XphpSourceParser((new ParserFactory())->createForHostVersion());
        try {
            [$ast, $byteOffsetMap] = $parser->parseWithMap($source);
        } catch (\Throwable $e) {
            $ast = [];
            $byteOffsetMap = \XPHP\Transpiler\Monomorphize\ByteOffsetMap::identity();
        }
        $visitor = new AstVisitor(
            new PositionMap($source),
            $byteOffsetMap,
            $source,
        );
        return $visitor->visit($ast ?? []);
    }

    /**
     * Find a TokenSpec whose source span exactly equals `$needle` and assert
     * its type matches `$expectedType`.
     *
     * @param list<TokenSpec> $specs
     */
    private function assertTokenSubstring(array $specs, string $source, string $needle, string $expectedType): void
    {
        foreach ($specs as $spec) {
            if (self::substring($source, $spec) === $needle && $spec->type === $expectedType) {
                self::assertTrue(true);
                return;
            }
        }
        $diag = array_map(
            static fn (TokenSpec $s): string => sprintf(
                "L%d C%d len=%d %s = %s",
                $s->line,
                $s->startChar,
                $s->length,
                $s->type,
                json_encode(self::substring($source, $s)),
            ),
            $specs,
        );
        self::fail("no `$expectedType` spec found at `$needle`; saw:\n  " . implode("\n  ", $diag));
    }

    private static function substring(string $source, TokenSpec $spec): string
    {
        // Convert (line, char) back to byte offset for substring lookup.
        // PositionMap can do this; we re-derive offsets via line scan to
        // keep this helper self-contained.
        $lines = explode("\n", $source);
        $byteOffset = 0;
        for ($i = 0; $i < $spec->line && $i < count($lines); $i++) {
            $byteOffset += strlen($lines[$i]) + 1; // +1 for the \n
        }
        $byteOffset += $spec->startChar;
        return substr($source, $byteOffset, $spec->length);
    }
}
