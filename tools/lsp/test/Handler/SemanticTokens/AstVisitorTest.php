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

    public function testReservedWordIdentifiersAreClassifiedAsKeywords(): void
    {
        // PHP tokenizes null / true / false / void / int / etc as
        // T_STRING (bareword identifiers), not as T_* keyword constants.
        // The visitor's identifier-recognition path emits these as
        // `keyword` regardless.
        $source = "<?php\n\$a = null;\n\$b = true;\n\$c = false;\n\$d = NULL;";
        $specs = $this->collect($source);
        $this->assertTokenSubstring($specs, $source, 'null', 'keyword');
        $this->assertTokenSubstring($specs, $source, 'true', 'keyword');
        $this->assertTokenSubstring($specs, $source, 'false', 'keyword');
        $this->assertTokenSubstring($specs, $source, 'NULL', 'keyword');
    }

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

    public function testParameterEmitsExactlyOneParameterSpec(): void
    {
        // Single spec at `$name`, type `parameter` -- NOT two specs
        // (variable + parameter) at the same span.  The reclassify-map
        // path tells the token pass to emit `parameter` instead of
        // `variable` at the param's offset.
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
        self::assertCount(
            1,
            $atName,
            'expected exactly one spec at `$name`; got ' . count($atName)
                . ' (' . implode(',', array_map(fn (TokenSpec $s) => $s->type, $atName)) . ')',
        );
        self::assertSame('parameter', $atName[0]->type);
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

    // --- Slice 3: xphp generic-syntax classifications --------------------

    public function testClassDeclarationTypeParamPaintsAsTypeParameter(): void
    {
        // Form 1: class Box<T> -- T inside <...> is typeParameter.
        $source = "<?php\nclass Box<T> {}";
        $specs = $this->collect($source);
        $this->assertTokenSubstring($specs, $source, 'T', 'typeParameter');
    }

    public function testBoundTypeParamPaintsAsTypeParameter(): void
    {
        // Form 2: class StringableBox<T: \Stringable> -- T is typeParameter;
        // Stringable is a class reference inside the clause (also painted
        // as typeParameter under our broad inside-clause rule for now).
        // The FQN `\Stringable` comes back as a single T_NAME_FULLY_QUALIFIED
        // token from PHP 8.0+'s tokenizer, so the emitted span includes
        // the leading backslash.
        $source = "<?php\nclass StringableBox<T: \\Stringable> {}";
        $specs = $this->collect($source);
        $this->assertTokenSubstring($specs, $source, 'T', 'typeParameter');
        $this->assertTokenSubstring($specs, $source, '\Stringable', 'typeParameter');
    }

    public function testTypeArgClausePaintsInsideBoxOfPlastic(): void
    {
        // Form 6: new Box<Plastic>() -- `Plastic` inside <...> is typeParameter.
        $source = "<?php\n\$b = new Box<Plastic>();";
        $specs = $this->collect($source);
        $this->assertTokenSubstring($specs, $source, 'Plastic', 'typeParameter');
    }

    public function testNestedTypeArgClause(): void
    {
        // Nested: Box<Lst<T>> -- both `Lst` and `T` are typeParameter.
        $source = "<?php\n\$b = new Box<Lst<T>>();";
        $specs = $this->collect($source);
        $this->assertTokenSubstring($specs, $source, 'Lst', 'typeParameter');
        $this->assertTokenSubstring($specs, $source, 'T', 'typeParameter');
    }

    public function testMultipleTypeArgsSeparatedByComma(): void
    {
        // Form 9: Pair<K, V> -- both K and V are typeParameter.
        $source = "<?php\nclass Pair<K, V> {}";
        $specs = $this->collect($source);
        $this->assertTokenSubstring($specs, $source, 'K', 'typeParameter');
        $this->assertTokenSubstring($specs, $source, 'V', 'typeParameter');
    }

    public function testLessThanComparisonIsNotMisclassified(): void
    {
        // Counter-example: $a < $b -- the `<` opens nothing because the
        // previous token is T_VARIABLE, not T_STRING.
        $source = "<?php\nif (\$a < \$b) { return 0; }";
        $specs = $this->collect($source);
        // No typeParameter spec anywhere.
        $typeParamSpecs = array_filter($specs, fn (TokenSpec $s) => $s->type === 'typeParameter');
        self::assertEmpty($typeParamSpecs, 'comparison `$a < $b` produced typeParameter spec');
    }

    public function testNumberComparisonIsNotMisclassified(): void
    {
        $source = "<?php\nif (\$x < 5) { return 0; }";
        $specs = $this->collect($source);
        $typeParamSpecs = array_filter($specs, fn (TokenSpec $s) => $s->type === 'typeParameter');
        self::assertEmpty($typeParamSpecs);
    }

    public function testLowercaseFunctionCallComparisonIsNotMisclassified(): void
    {
        // The lookahead-uppercase heuristic rejects `count(` (lowercase
        // first char) so `< count(` doesn't open a clause.
        $source = "<?php\nif (\$size < count(\$items)) { return 0; }";
        $specs = $this->collect($source);
        $typeParamSpecs = array_filter($specs, fn (TokenSpec $s) => $s->type === 'typeParameter');
        self::assertEmpty($typeParamSpecs);
    }

    public function testReifiedNewTPaintsAsTypeParameter(): void
    {
        // Form 10: `new T(...)` inside a class body whose template has T.
        // The AST's ATTR_GENERIC_PARAMS on the enclosing ClassLike puts T
        // in scope; the Name node 'T' inside `new T()` re-classifies.
        $source = <<<'XPHP'
        <?php
        namespace App;
        class Reified<T> {
            public function make(): T { return new T(); }
        }
        XPHP;
        $specs = $this->collect($source);

        // Multiple `T` references in source.  Assert at least one
        // typeParameter at `T` (the `new T()` position).
        $tSpecs = array_filter(
            $specs,
            fn (TokenSpec $s) => self::substring($source, $s) === 'T' && $s->type === 'typeParameter',
        );
        self::assertNotEmpty($tSpecs, 'expected at least one typeParameter at `T` in reified body');
    }

    public function testReifiedTClassPaintsAsTypeParameter(): void
    {
        // Form 11: `T::class` inside a generic body.
        $source = <<<'XPHP'
        <?php
        namespace App;
        class Reified<T> {
            public function name(): string { return T::class; }
        }
        XPHP;
        $specs = $this->collect($source);

        // The Name 'T' before ::class must be typeParameter.  There may
        // be multiple T's (the return type, the T::class one); assert at
        // least one.
        $tSpecs = array_filter(
            $specs,
            fn (TokenSpec $s) => self::substring($source, $s) === 'T' && $s->type === 'typeParameter',
        );
        self::assertNotEmpty($tSpecs);
    }

    public function testInstanceofTPaintsAsTypeParameter(): void
    {
        // Form 12: `instanceof T`.
        $source = <<<'XPHP'
        <?php
        namespace App;
        class Reified<T> {
            public function check(mixed $x): bool { return $x instanceof T; }
        }
        XPHP;
        $specs = $this->collect($source);
        $tSpecs = array_filter(
            $specs,
            fn (TokenSpec $s) => self::substring($source, $s) === 'T' && $s->type === 'typeParameter',
        );
        self::assertNotEmpty($tSpecs);
    }

    public function testReifiedTOutsideGenericBodyIsNotMisclassified(): void
    {
        // Counter-example: `new Foo()` in a non-generic class -- the
        // single-letter heuristic by itself would match `Foo`, but the
        // scope-stack-based detection requires Foo to be in
        // ATTR_GENERIC_PARAMS of an enclosing class.  Plain ClassLike
        // without ATTR_GENERIC_PARAMS doesn't push T into scope, so
        // `new Foo()` stays unclassified by the reified-T path.
        $source = <<<'XPHP'
        <?php
        namespace App;
        class Plain {
            public function go(): void { $x = new Foo(); }
        }
        XPHP;
        $specs = $this->collect($source);
        $fooSpecs = array_filter(
            $specs,
            fn (TokenSpec $s) => self::substring($source, $s) === 'Foo' && $s->type === 'typeParameter',
        );
        self::assertEmpty($fooSpecs, '`Foo` outside a generic body must not be classified as typeParameter');
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
