<?php

declare(strict_types=1);

namespace XPHP\Lsp\Test\Handler;

use PHPUnit\Framework\TestCase;
use XPHP\Lsp\Handler\TypeArgPositionDetector;

final class TypeArgPositionDetectorTest extends TestCase
{
    public function testDetectsImmediatelyAfterOpenBracket(): void
    {
        $source = 'new Box<';
        $hit = TypeArgPositionDetector::detect($source, strlen($source));
        self::assertSame(['prefix' => '', 'containerName' => 'Box', 'slot' => 0], $hit);
    }

    public function testDetectsWithPartialIdentifierPrefix(): void
    {
        $source = 'new Box<Pla';
        $hit = TypeArgPositionDetector::detect($source, strlen($source));
        self::assertSame(['prefix' => 'Pla', 'containerName' => 'Box', 'slot' => 0], $hit);
    }

    public function testDetectsAfterCommaInMultiArgList(): void
    {
        $source = 'new Pair<Foo, ';
        $hit = TypeArgPositionDetector::detect($source, strlen($source));
        // Slot 1 -- cursor sits after the first comma at depth 0.
        self::assertSame(['prefix' => '', 'containerName' => 'Pair', 'slot' => 1], $hit);
    }

    public function testDetectsInsideNestedGenericsAtSameDepth(): void
    {
        $source = 'new Box<List<int>, ';
        $hit = TypeArgPositionDetector::detect($source, strlen($source));
        // Outermost generic is `Box`; the comma inside `List<...>` doesn't
        // count toward Box's slot because it sits at depth 1.
        self::assertSame(['prefix' => '', 'containerName' => 'Box', 'slot' => 1], $hit);
    }

    public function testRejectsLessThanOperator(): void
    {
        $source = 'if ($a < ';
        $hit = TypeArgPositionDetector::detect($source, strlen($source));
        self::assertNull($hit);
    }

    public function testRejectsOutsideAnyTypeArgClause(): void
    {
        $source = '$x = new Box(';
        $hit = TypeArgPositionDetector::detect($source, strlen($source));
        self::assertNull($hit);
    }

    public function testRejectsAfterClosingBracket(): void
    {
        $source = 'new Box<Plastic> ';
        $hit = TypeArgPositionDetector::detect($source, strlen($source));
        self::assertNull($hit, 'cursor past the closing `>` is no longer in type-arg context');
    }

    public function testAcceptsFqnStylePrefix(): void
    {
        // Backslashes are part of the identifier so an FQN prefix matches as one token.
        $source = 'new Box<App\\Mo';
        $hit = TypeArgPositionDetector::detect($source, strlen($source));
        self::assertNotNull($hit);
        self::assertSame('App\\Mo', $hit['prefix']);
    }

    public function testHandlesNestedDepthCorrectly(): void
    {
        // Inside the INNER `<…>`, prefix is the partial identifier just typed.
        $source = 'new Box<List<Pla';
        $hit = TypeArgPositionDetector::detect($source, strlen($source));
        // Container is the inner `List`; slot 0 inside it.
        self::assertSame(['prefix' => 'Pla', 'containerName' => 'List', 'slot' => 0], $hit);
    }

    public function testOffsetPastSourceLengthReturnsNull(): void
    {
        $hit = TypeArgPositionDetector::detect('abc', 99);
        self::assertNull($hit);
    }

    public function testCursorAtOffsetZeroReturnsNull(): void
    {
        // Probes the `$prefixStart > 0` guard at the boundary. With `>= 0`,
        // we'd read source[-1] and crash. With `> 0`, the loop doesn't execute
        // and prefixStart stays at 0; the backwards walk then has $i = -1
        // immediately, exits at `$i >= 0`, returns null.
        $hit = TypeArgPositionDetector::detect('Box<int>', 0);
        self::assertNull($hit);
    }

    public function testCursorAfterTabSeparatorAcceptsTypeArgContext(): void
    {
        // Locks the `$byte === "\t"` check in isInterArgByte. Each char of the
        // chain on line 96 is its own Identical mutation; testing each
        // independently is the only way to kill them.
        $source = "new Box<Foo,\t";
        $hit = TypeArgPositionDetector::detect($source, strlen($source));
        self::assertSame(['prefix' => '', 'containerName' => 'Box', 'slot' => 1], $hit);
    }

    public function testCursorAfterNewlineSeparatorAcceptsTypeArgContext(): void
    {
        // Locks the `$byte === "\n"` check.
        $source = "new Box<Foo,\n";
        $hit = TypeArgPositionDetector::detect($source, strlen($source));
        self::assertSame(['prefix' => '', 'containerName' => 'Box', 'slot' => 1], $hit);
    }

    public function testCursorAfterCarriageReturnSeparatorAcceptsTypeArgContext(): void
    {
        // Locks the `$byte === "\r"` check.
        $source = "new Box<Foo,\r";
        $hit = TypeArgPositionDetector::detect($source, strlen($source));
        self::assertSame(['prefix' => '', 'containerName' => 'Box', 'slot' => 1], $hit);
    }

    public function testCursorAfterCommaWithoutSpaceAcceptsTypeArgContext(): void
    {
        // Locks the `$byte === ','` check.
        $source = "new Box<Foo,";
        $hit = TypeArgPositionDetector::detect($source, strlen($source));
        self::assertSame(['prefix' => '', 'containerName' => 'Box', 'slot' => 1], $hit);
    }

    public function testCursorAfterSpaceSeparatorAcceptsTypeArgContext(): void
    {
        // Locks the `$byte === ' '` check. (Already implicitly covered by
        // testDetectsAfterCommaInMultiArgList but isolated here to nail
        // the specific char mutation.)
        $source = "new Box<Foo, ";
        $hit = TypeArgPositionDetector::detect($source, strlen($source));
        self::assertSame(['prefix' => '', 'containerName' => 'Box', 'slot' => 1], $hit);
    }

    public function testNonSeparatorAndNonIdentifierByteBreaksContext(): void
    {
        // Inside `<…>`, encountering a byte that's neither identifier nor
        // separator (e.g. `(`, `)`, `;`) terminates the backwards walk →
        // returns null. Locks the `isInterArgByte($c) || isIdentifierByte($c)`
        // OR-chain on line 77 by feeding a byte that fails both predicates.
        $source = "new Box<Foo(";
        $hit = TypeArgPositionDetector::detect($source, strlen($source));
        self::assertNull($hit);
    }

    public function testOpenBracketAtOffsetOnePassesIdentifierCheckAtZero(): void
    {
        // Probes the `$j < 0` guard at the exact boundary. Source `A<`:
        // - prefixStart = 2 (no identifier bytes after cursor)
        // - walking back: $i = 1 = `<` at depth 0
        // - $j = 0, source[0] = 'A' is identifier → returns success
        // With mutation `$j <= 0`, we'd return null for the 'A' case.
        $source = 'A<';
        $hit = TypeArgPositionDetector::detect($source, strlen($source));
        self::assertSame(['prefix' => '', 'containerName' => 'A', 'slot' => 0], $hit);
    }

    public function testOpenBracketAtOffsetZeroFailsIdentifierCheck(): void
    {
        // Source `<` — no character before the `<`. $j = -1. Original returns
        // null. Locks the `$j < 0` guard against being weakened.
        $source = '<';
        $hit = TypeArgPositionDetector::detect($source, strlen($source));
        self::assertNull($hit);
    }

    public function testDetectsAfterDepthBalancedNestedGenerics(): void
    {
        // Confirms the depth counter handles closing `>` correctly. With the
        // `$depth++; $i--;` decrement removed via mutation, this case would
        // infinite-loop (and infection reports it as a timeout, not an
        // escape — still good signal).
        $source = 'new Box<Foo<Bar>, ';
        $hit = TypeArgPositionDetector::detect($source, strlen($source));
        self::assertSame(['prefix' => '', 'containerName' => 'Box', 'slot' => 1], $hit);
    }

    public function testIdentifierAtReturnsFullNameAtCursorInsideGenericClause(): void
    {
        // Cursor sits in the middle of `User` -- prefix `Us`, suffix `er`.
        $source = 'identity<User>(new User())';
        $offset = strpos($source, 'User') + 2; // mid-identifier
        self::assertSame('User', TypeArgPositionDetector::identifierAt($source, $offset));
    }

    public function testIdentifierAtReturnsNullOutsideGenericClause(): void
    {
        $source = '$x = new User();';
        $offset = strpos($source, 'User') + 1;
        self::assertNull(TypeArgPositionDetector::identifierAt($source, $offset));
    }

    public function testIdentifierAtReturnsNullOnWhitespaceInsideGenericClause(): void
    {
        // Cursor on the space between `<` and `User`.  No prefix to the
        // left, no identifier byte at the cursor -> null.
        $source = 'identity< User>(...)';
        $offset = strpos($source, '< ') + 1; // on the space
        self::assertNull(TypeArgPositionDetector::identifierAt($source, $offset));
    }

    public function testIdentifierAtReturnsFqnStyleNameWithBackslashes(): void
    {
        // Backslashes are identifier bytes per the detector's rule, so a
        // namespace-qualified type-arg comes through intact.
        $source = 'identity<App\\Models\\User>(...)';
        $offset = strpos($source, 'User') + 1;
        self::assertSame('App\\Models\\User', TypeArgPositionDetector::identifierAt($source, $offset));
    }
}
