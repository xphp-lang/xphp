<?php

declare(strict_types=1);

namespace XPHP\Lsp\Test\Resolver;

use PHPUnit\Framework\TestCase;
use XPHP\Lsp\Resolver\PhpCompletionContext;

final class PhpCompletionContextTest extends TestCase
{
    public function testDetectsMemberAccessImmediatelyAfterArrow(): void
    {
        $source = '$obj->';
        $hit = PhpCompletionContext::detect($source, strlen($source));
        self::assertSame(['kind' => 'member', 'receiverEnd' => 4, 'prefix' => ''], $hit);
    }

    public function testDetectsMemberAccessWithPrefix(): void
    {
        $source = '$obj->sho';
        $hit = PhpCompletionContext::detect($source, strlen($source));
        self::assertSame(['kind' => 'member', 'receiverEnd' => 4, 'prefix' => 'sho'], $hit);
    }

    public function testDetectsStaticAccessImmediatelyAfterColons(): void
    {
        $source = 'User::';
        $hit = PhpCompletionContext::detect($source, strlen($source));
        self::assertSame(['kind' => 'static', 'receiverEnd' => 4, 'prefix' => ''], $hit);
    }

    public function testDetectsStaticAccessWithPrefix(): void
    {
        $source = 'User::frO';
        $hit = PhpCompletionContext::detect($source, strlen($source));
        self::assertSame(['kind' => 'static', 'receiverEnd' => 4, 'prefix' => 'frO'], $hit);
    }

    public function testRejectsOutsideMemberOrStaticContext(): void
    {
        self::assertNull(PhpCompletionContext::detect('$x = 1;', 6));
        self::assertNull(PhpCompletionContext::detect('echo "hi";', 5));
    }

    public function testReturnsNullForOffsetPastEndOfSource(): void
    {
        self::assertNull(PhpCompletionContext::detect('abc', 99));
    }

    public function testSuppressesCompletionInsideSingleQuotedString(): void
    {
        // Phase 3 polish: cursor between the quotes of '$user->' must
        // not fire member completion -- the whole thing is a string
        // literal.
        $source = "<?php\n\$x = '\$user->';\n";
        $offset = strpos($source, "->'") + 2; // cursor between `->` and `'`
        self::assertNull(PhpCompletionContext::detect($source, $offset));
    }

    public function testSuppressesCompletionInsideLineComment(): void
    {
        $source = "<?php\n// note: \$user->name will become...\n";
        $offset = strpos($source, '->name') + 2;
        self::assertNull(PhpCompletionContext::detect($source, $offset));
    }

    public function testSuppressesCompletionInsideDocblock(): void
    {
        $source = "<?php\n/**\n * \$user->name\n */\nclass Foo {}\n";
        $offset = strpos($source, '->name') + 2;
        self::assertNull(PhpCompletionContext::detect($source, $offset));
    }

    public function testStillFiresCompletionInsideDoubleQuotedStringInterpolation(): void
    {
        // Inside `"$user->name"` the tokenizer splits `$user->name` into
        // real code tokens (T_VARIABLE / T_OBJECT_OPERATOR / T_STRING),
        // not literal text -- completion must still fire there.
        $source = "<?php\n\$x = \"hello \$user->\";\n";
        $offset = strpos($source, '->"') + 2; // cursor after `->`
        $hit = PhpCompletionContext::detect($source, $offset);
        self::assertSame('member', $hit['kind'] ?? null, 'interpolated `->` should still classify as member');
    }

    public function testReturnsNullForNegativeOffset(): void
    {
        self::assertNull(PhpCompletionContext::detect('abc', -1));
    }

    public function testHandlesIdentifierOnlyAfterArrow(): void
    {
        // Cursor mid-identifier following `->`; the partial identifier
        // becomes the prefix.
        $source = '$obj->getNa';
        $hit = PhpCompletionContext::detect($source, strlen($source));
        self::assertSame(['kind' => 'member', 'receiverEnd' => 4, 'prefix' => 'getNa'], $hit);
    }

    public function testRejectsLoneColonNotPrecededByAnotherColon(): void
    {
        // Single `:` is not `::` — could be ternary, label, etc.
        // Returns null (no completion context).
        $source = '$x :';
        self::assertNull(PhpCompletionContext::detect($source, strlen($source)));
    }

    public function testRejectsAngleBracketBeingMistakenForArrow(): void
    {
        // `=>` looks like `->` if you only check the last two bytes.
        // The detector must reject this case (a `=>` arrow is part of
        // array literals / arrow functions, not a member access).
        $source = '$arr =>';
        self::assertNull(PhpCompletionContext::detect($source, strlen($source)));
    }

    public function testDetectsVariableContextAfterDollar(): void
    {
        // Cursor right after `$repo` -- variable completion candidate
        // shape (`$re|`, `$repo|`).
        $source = '$repo';
        self::assertSame(
            ['kind' => 'variable', 'prefix' => 'repo'],
            PhpCompletionContext::detect($source, strlen($source)),
        );
    }

    public function testDetectsVariableContextWithEmptyPrefixRightAfterDollar(): void
    {
        // Cursor immediately after `$` (no identifier chars yet) -- still
        // variable context, empty prefix.
        $source = 'echo $';
        self::assertSame(
            ['kind' => 'variable', 'prefix' => ''],
            PhpCompletionContext::detect($source, strlen($source)),
        );
    }

    public function testDetectsNewKeywordContext(): void
    {
        // After `new ` -- class-only completion.
        $source = '$x = new Us';
        self::assertSame(
            ['kind' => 'new', 'prefix' => 'Us'],
            PhpCompletionContext::detect($source, strlen($source)),
        );
    }

    public function testDetectsNewWithMultipleSpaces(): void
    {
        // Whitespace after `new` is collapsed -- still recognised as a
        // `new` keyword context (PHP grammar allows arbitrary whitespace
        // between `new` and the class name).
        $source = "\$x = new\n    Us";
        $hit = PhpCompletionContext::detect($source, strlen($source));
        self::assertSame('new', $hit['kind'] ?? null);
        self::assertSame('Us', $hit['prefix'] ?? null);
    }

    public function testNewKeywordEmbeddedInIdentifierIsNotMatched(): void
    {
        // `mynew Foo` -- the `new` is part of the prior identifier, not
        // the keyword.  Must classify as `expression`, not `new`.
        $source = 'mynew Fo';
        $hit = PhpCompletionContext::detect($source, strlen($source));
        self::assertSame('expression', $hit['kind'] ?? null);
        self::assertSame('Fo', $hit['prefix'] ?? null);
    }

    public function testDetectsExpressionContextForBareIdentifier(): void
    {
        // Cursor on a bare identifier with no preceding operator -- could
        // be a function or class reference (both are suggested at this
        // shape).
        $source = 'echo str';
        self::assertSame(
            ['kind' => 'expression', 'prefix' => 'str'],
            PhpCompletionContext::detect($source, strlen($source)),
        );
    }

    public function testNumberLiteralIsNotAnIdentifier(): void
    {
        // PHP identifiers can't start with a digit; `$x = 1;` with cursor
        // mid-number-literal mustn't classify as `expression` with
        // prefix `1`.  Returns null so no completion fires.
        $source = '$x = 1';
        self::assertNull(PhpCompletionContext::detect($source, strlen($source)));
    }

    public function testRejectsCursorAfterSemicolon(): void
    {
        // Just after `;` and a newline -- no identifier prefix; null.
        $source = "\$x = 1;\n";
        self::assertNull(PhpCompletionContext::detect($source, strlen($source)));
    }
}
