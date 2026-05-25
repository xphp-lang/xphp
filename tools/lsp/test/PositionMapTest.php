<?php

declare(strict_types=1);

namespace XPHP\Lsp\Test;

use OutOfBoundsException;
use PHPUnit\Framework\TestCase;
use XPHP\Lsp\PositionMap;

final class PositionMapTest extends TestCase
{
    // =====================================================================
    // offsetToPosition — happy paths
    // =====================================================================

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

    public function testOffsetAtNewlineByteResolvesToOwnLine(): void
    {
        // The "\n" byte at offset 5 belongs to line 0 — line 1 doesn't start
        // until the byte AFTER the terminator. Locks binarySearchLine's
        // `<=` direction: with `<` we'd incorrectly bucket the \n into line 1.
        $map = new PositionMap("hello\nworld");
        self::assertSame([0, 5], $map->offsetToPosition(5));
    }

    public function testNegativeOffsetThrows(): void
    {
        $map = new PositionMap("abc");
        $this->expectException(OutOfBoundsException::class);
        $map->offsetToPosition(-1);
    }

    // =====================================================================
    // offsetToPosition — multi-line + multi-byte UTF-8
    // =====================================================================

    public function testOffsetCountsUtf8CharactersNotBytes(): void
    {
        // "héllo" — é is 2 bytes (0xC3 0xA9). The 'l' after é is byte 3, char 2.
        $map = new PositionMap("h\xC3\xA9llo");
        self::assertSame([0, 2], $map->offsetToPosition(3));
    }

    public function testThreeByteCharacterCountedAsOneColumn(): void
    {
        // "a€b" — € is U+20AC encoded as 0xE2 0x82 0xAC (3 bytes). 'b' is byte 4, char 2.
        $map = new PositionMap("a\xE2\x82\xACb");
        self::assertSame([0, 2], $map->offsetToPosition(4));
    }

    public function testFourByteCharacterCountedAsSurrogatePair(): void
    {
        // "a😀b" — 😀 is U+1F600 encoded as 0xF0 0x9F 0x98 0x80 (4 bytes
        // in UTF-8 = 2 UTF-16 code units, since it lives in the
        // supplementary plane).  'b' lives at byte 5; its LSP column is
        // 3 (1 for 'a' + 2 for the surrogate pair).
        $map = new PositionMap("a\xF0\x9F\x98\x80b");
        self::assertSame([0, 3], $map->offsetToPosition(5));
    }

    public function testOffsetOnEmptyDocumentMapsToZeroZero(): void
    {
        // Locks the constructor's `for ($i = 0; $i < $length; $i++)` for the
        // length=0 case — we still get exactly one line offset [0], so byte 0
        // maps to line 0, character 0.
        $map = new PositionMap("");
        self::assertSame([0, 0], $map->offsetToPosition(0));
    }

    public function testOffsetPastLastNewlineMapsToFinalLineWithEmptyCharacter(): void
    {
        // "a\n" — after the constructor: lineOffsets = [0, 2]. There's no line
        // text after the trailing \n; offset 2 maps to {line: 1, character: 0}.
        // Locks the constructor's `$i + 1` (vs `$i`) and the `$source[$i] === "\n"`
        // detection.
        $map = new PositionMap("a\n");
        self::assertSame([1, 0], $map->offsetToPosition(2));
    }

    // =====================================================================
    // positionToOffset — happy paths
    // =====================================================================

    public function testPositionZeroZeroResolvesToOffsetZero(): void
    {
        $map = new PositionMap("hello\nworld");
        self::assertSame(0, $map->positionToOffset(0, 0));
    }

    public function testPositionAtSecondLineStartResolvesToByteAfterNewline(): void
    {
        $map = new PositionMap("hello\nworld");
        self::assertSame(6, $map->positionToOffset(1, 0));
    }

    public function testPositionMidLineResolvesToCharacterOffset(): void
    {
        $map = new PositionMap("hello\nworld");
        self::assertSame(8, $map->positionToOffset(1, 2));
    }

    public function testPositionPastEofClampsToDocumentLength(): void
    {
        // Locks the `$line >= count($this->lineOffsets)` guard. Without it the
        // method would dereference an out-of-bounds index. The clamp returns
        // strlen($source) (= 11 here).
        $map = new PositionMap("hello\nworld");
        self::assertSame(11, $map->positionToOffset(99, 0));
    }

    public function testPositionAtExactlyLineCountTriggersClamp(): void
    {
        // Probes the `>` vs `>=` boundary at exactly $line == count(lineOffsets).
        // "hello\nworld" → lineOffsets = [0, 6], count = 2. positionToOffset(2, *)
        // must clamp because there's no line 2 in the offset table.
        $map = new PositionMap("hello\nworld");
        self::assertSame(11, $map->positionToOffset(2, 0));
        self::assertSame(11, $map->positionToOffset(2, 99));
    }

    public function testPositionOnLastValidLineDoesNotTriggerClamp(): void
    {
        // The opposite side of the same boundary: $line == count - 1 must
        // NOT clamp; it walks the last line's content normally.
        $map = new PositionMap("hello\nworld");
        // Line 1 char 0 → byte 6 (the 'w' of "world"). If `>=` were `>`, we'd
        // clamp here and return 11 instead.
        self::assertSame(6, $map->positionToOffset(1, 0));
    }

    public function testPositionAtLastValidLineUsesLengthFallback(): void
    {
        // Locks the `$this->lineOffsets[$line + 1] ?? $length + 1` coalesce on
        // the LAST line. lineOffsets has 2 entries [0, 6]; positionToOffset(1, ..)
        // means $line + 1 = 2 which is out of range → the null-coalesce hits the
        // `$length + 1` fallback. We assert the fallback walks the last line's
        // text correctly (here `world` = 5 chars).
        $map = new PositionMap("hello\nworld");
        self::assertSame(11, $map->positionToOffset(1, 5));
    }

    public function testPositionAtIntermediateLineUsesNextLineOffset(): void
    {
        // Locks the non-fallback branch: $this->lineOffsets[$line + 1] returns
        // the actual next-line offset. Without `+1` here we'd reuse line 0's
        // offset and the character walk would go wrong.
        $map = new PositionMap("ab\ncd\nef");
        // Line 1 starts at byte 3. Character 2 of line 1 = byte 5 (the 'd').
        self::assertSame(5, $map->positionToOffset(1, 2));
    }

    public function testPositionCharacterPastEolClampsToLineTerminator(): void
    {
        // Locks the `$consumed < $character && $bytes < strlen($lineText)`
        // double-guard inside the walk loop. character=99 on a 5-char line
        // must stop at lineEnd (= byte 5 = the `\n`), not run past it.
        $map = new PositionMap("hello\nworld");
        self::assertSame(5, $map->positionToOffset(0, 99));
    }

    public function testPositionCharacterPastEolOnIntermediateLineClampsAtNewline(): void
    {
        // Three-line file so the clamped line is NOT the first one. Locks the
        // `$lineEnd - $lineStart` substr length: a `$lineEnd + $lineStart`
        // mutation would make lineText include subsequent lines and the walk
        // would overshoot the \n.
        $map = new PositionMap("ab\ncd\nef");
        // Line 1 starts at byte 3; line 1's \n is at byte 5. character=99
        // must clamp to 5.
        self::assertSame(5, $map->positionToOffset(1, 99));
    }

    public function testPositionCharacterPastEolOnLastLineClampsToLength(): void
    {
        // Last line has no terminator, so the clamp is at document length.
        $map = new PositionMap("hello\nworld");
        self::assertSame(11, $map->positionToOffset(1, 99));
    }

    public function testPositionRoundTripsThroughOffsetToPosition(): void
    {
        $map = new PositionMap("abc\ndefgh\nij");
        foreach ([0, 1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11] as $byteOffset) {
            [$line, $character] = $map->offsetToPosition($byteOffset);
            self::assertSame($byteOffset, $map->positionToOffset($line, $character), "round-trip at byte {$byteOffset}");
        }
    }

    // =====================================================================
    // positionToOffset — multi-byte UTF-8
    // =====================================================================

    public function testPositionAtCharacterIndexInMultibyteLine(): void
    {
        // "h é l l o" — character 2 (the 'l' after é) lives at byte 3.
        $map = new PositionMap("h\xC3\xA9llo");
        self::assertSame(3, $map->positionToOffset(0, 2));
    }

    public function testPositionWalksFourByteCharactersAsSurrogatePairs(): void
    {
        // "😀a" — 😀 is a supplementary-plane codepoint = 2 UTF-16 code
        // units.  'a' lives at byte 4, LSP column 2.  Column 1 (mid
        // surrogate pair) clamps back to the start of 😀 -- consistent
        // with editors that don't allow cursor placement between the
        // high and low surrogate.
        $map = new PositionMap("\xF0\x9F\x98\x80a");
        self::assertSame(0, $map->positionToOffset(0, 1));
        self::assertSame(4, $map->positionToOffset(0, 2));
    }

    public function testInvalidUtf8LeadingByteFallsBackToOneByte(): void
    {
        // 0xFF is an invalid UTF-8 leading byte. The fallback in utf8CharLength
        // counts it as 1 byte / 1 character so the walk doesn't infinite-loop
        // or skip over the whole rest of the line.
        $map = new PositionMap("\xFFa");
        self::assertSame(1, $map->positionToOffset(0, 1));
        self::assertSame(2, $map->positionToOffset(0, 2));
    }

    public function testPositionWalksDiverseTwoByteSequences(): void
    {
        // Exercise the `($code & 0xE0) === 0xC0` branch with codepoints other
        // than é. Locks bitmask mutations that happen to be equivalent for
        // 0xC3 but diverge for other valid 2-byte leaders.
        //   ñ → 0xC3 0xB1   (leader has low bit 1)
        //   Ω → 0xCE 0xA9   (leader is 0xCE)
        //   ǳ → 0xC7 0xB3   (leader is 0xC7)
        $map = new PositionMap("\xC3\xB1\xCE\xA9\xC7\xB3z");
        // 3 multi-byte chars (2 bytes each) + 1 ASCII → 4 characters; 'z' at byte 6.
        self::assertSame(6, $map->positionToOffset(0, 3));
        self::assertSame(7, $map->positionToOffset(0, 4));
    }

    public function testPositionWalksDiverseThreeByteSequences(): void
    {
        // Exercise the `($code & 0xF0) === 0xE0` branch across multiple
        // three-byte leader bytes. 0xE0..0xEF all match; some bitmask
        // mutations work for one but not another.
        //    日 → 0xE6 0x97 0xA5   (leader 0xE6)
        //   ☃ → 0xE2 0x98 0x83    (leader 0xE2)
        //   ❤ → 0xE2 0x9D 0xA4    (leader 0xE2)
        $map = new PositionMap("\xE6\x97\xA5\xE2\x98\x83\xE2\x9D\xA4z");
        // 3 three-byte chars + 'z' → 4 chars; z at byte 9.
        self::assertSame(9, $map->positionToOffset(0, 3));
        self::assertSame(10, $map->positionToOffset(0, 4));
    }

    public function testPositionWalksDiverseFourByteSequences(): void
    {
        // Exercise the `($code & 0xF8) === 0xF0` branch. Four-byte leaders
        // are 0xF0..0xF4.  Each 4-byte UTF-8 char is 2 UTF-16 code units,
        // so the 3 chars + 'z' span 7 columns total (2+2+2+1).
        //   😀 → 0xF0 0x9F 0x98 0x80
        //   🥑 → 0xF0 0x9F 0xA5 0x91
        //   𐀀 → 0xF0 0x90 0x80 0x80   (linear-B; first SMP codepoint)
        $map = new PositionMap("\xF0\x9F\x98\x80\xF0\x9F\xA5\x91\xF0\x90\x80\x80z");
        // Column 6 = past all three 4-byte chars (start of 'z'); byte 12.
        self::assertSame(12, $map->positionToOffset(0, 6));
        // Column 7 = past 'z'; byte 13.
        self::assertSame(13, $map->positionToOffset(0, 7));
    }

    public function testPositionWalksOddLeaderThreeByteSequence(): void
    {
        // Locks mutations on the `($code & 0xF0) === 0xE0` line: with `0xF1` or
        // `0xEF` instead of `0xF0`, the mask result differs only for ODD
        // 3-byte leaders. ㈠ = U+3220 → 0xE3 0x88 0xA0 has an odd 0xE3 leader.
        $map = new PositionMap("\xE3\x88\xA0z");
        self::assertSame(3, $map->positionToOffset(0, 1));
        self::assertSame(4, $map->positionToOffset(0, 2));
    }

    public function testPositionWalksOddLeaderFourByteSequence(): void
    {
        // Locks mutations on the `($code & 0xF8) === 0xF0` line: with `0xF7`
        // or `0xF9` instead of `0xF8`, ODD 4-byte leaders mismatch. Synthetic
        // U+50000 → 0xF1 0x90 0x80 0x80 (4-byte sequence with odd leader).
        // Surrogate-pair encoding: column 2 = past the 4-byte char.
        $map = new PositionMap("\xF1\x90\x80\x80z");
        self::assertSame(4, $map->positionToOffset(0, 2));
        self::assertSame(5, $map->positionToOffset(0, 3));
    }

    public function testOffsetToPositionEncodesSupplementaryCharsAsSurrogatePair(): void
    {
        // Phase 3 polish: end-to-end UTF-16 encoding for offsetToPosition.
        // Emoji + ASCII: each emoji is one UTF-8 4-byte sequence and
        // contributes 2 UTF-16 code units (surrogate pair).
        $map = new PositionMap("// 🚀 launch");
        // '🚀' starts at byte 3, ends at byte 7; 'launch' starts at byte 8.
        // LSP columns: '/'=0,'/'=1,' '=2,'🚀'=3-4 (surrogate pair),' '=5,'l'=6
        self::assertSame([0, 6], $map->offsetToPosition(8));
    }

    public function testPositionMixedAsciiAndMultibyteSequence(): void
    {
        // Final mixed run to ensure all four branches of utf8CharLength get
        // hit in the same call. "a€😀b" — bytes a(1), €(3), 😀(4), b(1) = 9 bytes.
        // UTF-16 columns: a=1, €=1 (BMP), 😀=2 (surrogate pair), b=1 = 5 cols.
        $map = new PositionMap("a\xE2\x82\xAC\xF0\x9F\x98\x80b");
        self::assertSame(0, $map->positionToOffset(0, 0));
        self::assertSame(1, $map->positionToOffset(0, 1));   // past 'a'
        self::assertSame(4, $map->positionToOffset(0, 2));   // past €
        self::assertSame(8, $map->positionToOffset(0, 4));   // past 😀 (cols 2,3)
        self::assertSame(9, $map->positionToOffset(0, 5));   // past 'b'
    }

    // =====================================================================
    // fullLineRangeFromNikic
    // =====================================================================

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

    public function testFullLineRangeBeyondEofReturnsEmptyRange(): void
    {
        // Locks the `$line >= count($this->lineOffsets)` guard inside
        // fullLineRangeFromNikic. nikic line 99 → LSP line 98, well past EOF.
        // Result: empty range at line 98.
        $map = new PositionMap("a\nbc");
        self::assertSame([98, 0, 98, 0], $map->fullLineRangeFromNikic(99));
    }

    public function testFullLineRangeAtExactlyLineCountBoundaryReturnsEmpty(): void
    {
        // Probes `>` vs `>=` at $line == count(lineOffsets). "a\nbc" →
        // lineOffsets [0, 2], count 2. nikic line 3 → LSP line 2 == count.
        // Must hit the empty-range branch.
        $map = new PositionMap("a\nbc");
        self::assertSame([2, 0, 2, 0], $map->fullLineRangeFromNikic(3));
    }

    public function testFullLineRangeOnLastLineUsesLengthFallback(): void
    {
        // Locks the `?? strlen($this->source) + 1` coalesce fallback. On the
        // last line $line + 1 is out of range, so we use the fallback. The
        // length is then subtracted by 1 to compute lineText. Wrong fallback
        // constant changes the rendered character count.
        $map = new PositionMap("a\nbcdef");
        // nikic line 2 = last line ("bcdef", 5 chars).
        self::assertSame([1, 0, 1, 5], $map->fullLineRangeFromNikic(2));
    }

    public function testFullLineRangeCountsMultibyteCharactersAsOneColumn(): void
    {
        // "héllo" on the only line → length is 5 characters (é counts as one).
        $map = new PositionMap("h\xC3\xA9llo");
        self::assertSame([0, 0, 0, 5], $map->fullLineRangeFromNikic(1));
    }

    public function testFullLineRangeForEmptyDocumentOnLineOne(): void
    {
        // Empty source → only line 0 exists at offset 0. nikic line 1 (= LSP
        // line 0) is the only line; length 0.
        $map = new PositionMap("");
        self::assertSame([0, 0, 0, 0], $map->fullLineRangeFromNikic(1));
    }

    public function testFullLineRangeCorrectlyCapsAtIntermediateNewline(): void
    {
        // "ab\ncd" — nikic line 1 must report 2 chars ("ab"), NOT include the \n.
        // Locks the `$nextLineStart - 1` (vs `$nextLineStart` or `$nextLineStart - 0`).
        $map = new PositionMap("ab\ncd");
        self::assertSame([0, 0, 0, 2], $map->fullLineRangeFromNikic(1));
    }

    // =====================================================================
    // lspLineFromNikic
    // =====================================================================

    public function testLspLineFromNikicClampsAtZero(): void
    {
        // Defensive: nikic shouldn't emit line 0, but a defensive clamp prevents a
        // negative line from sneaking into the wire format.
        self::assertSame(0, PositionMap::lspLineFromNikic(0));
        self::assertSame(0, PositionMap::lspLineFromNikic(1));
        self::assertSame(4, PositionMap::lspLineFromNikic(5));
    }

    public function testLspLineFromNikicClampsNegativeInput(): void
    {
        // Even more defensive — never go below 0 regardless of how broken the
        // upstream value is.
        self::assertSame(0, PositionMap::lspLineFromNikic(-5));
    }

    // =====================================================================
    // Constructor / line-offset detection
    // =====================================================================

    public function testSingleLineDocumentHasOneLineOffset(): void
    {
        // No newlines → exactly one line offset at 0. Offset 5 (mid-doc) still
        // resolves to line 0.
        $map = new PositionMap("abcdef");
        self::assertSame([0, 5], $map->offsetToPosition(5));
    }

    public function testTrailingNewlineProducesExtraLineOffset(): void
    {
        // "abc\n" should have lineOffsets [0, 4]. Byte 4 (just past EOF in
        // terms of content) maps to {line 1, character 0}.
        $map = new PositionMap("abc\n");
        self::assertSame([1, 0], $map->offsetToPosition(4));
    }

    public function testConsecutiveNewlinesProduceEmptyLines(): void
    {
        // "\n\n" — lineOffsets [0, 1, 2]. Byte 1 = line 1 char 0; byte 2 = line 2 char 0.
        $map = new PositionMap("\n\n");
        self::assertSame([0, 0], $map->offsetToPosition(0));
        self::assertSame([1, 0], $map->offsetToPosition(1));
        self::assertSame([2, 0], $map->offsetToPosition(2));
    }
}
