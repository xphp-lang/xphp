<?php

declare(strict_types=1);

namespace XPHP\Lsp\Test;

use PHPUnit\Framework\TestCase;
use XPHP\Lsp\PositionMap;

final class PositionMapTest extends TestCase
{
    public function testOffsetZeroIsLineZeroCharacterZero(): void
    {
        $map = new PositionMap("hello\nworld");
        self::assertSame([0, 0], $map->offsetToPosition(0));
    }

    public function testOffsetAtSecondLineStart(): void
    {
        $map = new PositionMap("hello\nworld");
        // 'w' is at byte offset 6 (after "hello\n").
        self::assertSame([1, 0], $map->offsetToPosition(6));
    }

    public function testOffsetMidLine(): void
    {
        $map = new PositionMap("hello\nworld");
        // 'r' is byte 8 ('w'=6, 'o'=7, 'r'=8); character 2 on line 1.
        self::assertSame([1, 2], $map->offsetToPosition(8));
    }

    public function testFullLineRangeFromNikicReturnsZeroBasedLineAndLength(): void
    {
        $map = new PositionMap("abc\ndefgh\nij");
        // nikic line 2 → LSP line 1 ("defgh", 5 chars).
        self::assertSame([1, 0, 1, 5], $map->fullLineRangeFromNikic(2));
    }

    public function testFullLineRangeForLastLineWithoutTrailingNewline(): void
    {
        $map = new PositionMap("a\nbc");
        // nikic line 2 → LSP line 1 ("bc", 2 chars).
        self::assertSame([1, 0, 1, 2], $map->fullLineRangeFromNikic(2));
    }

    public function testLspLineFromNikicClampsAtZero(): void
    {
        // Defensive: nikic shouldn't emit line 0, but a defensive clamp prevents a
        // negative line from sneaking into the wire format.
        self::assertSame(0, PositionMap::lspLineFromNikic(0));
        self::assertSame(0, PositionMap::lspLineFromNikic(1));
        self::assertSame(4, PositionMap::lspLineFromNikic(5));
    }
}
