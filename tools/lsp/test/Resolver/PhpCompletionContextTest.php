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
}
