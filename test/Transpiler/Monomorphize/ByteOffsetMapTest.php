<?php

declare(strict_types=1);

namespace XPHP\Test\Transpiler\Monomorphize;

use PHPUnit\Framework\TestCase;
use XPHP\Transpiler\Monomorphize\ByteOffsetMap;

final class ByteOffsetMapTest extends TestCase
{
    public function testIdentityMapPassesOffsetsThrough(): void
    {
        $map = ByteOffsetMap::identity();
        self::assertSame(0, $map->toOriginal(0));
        self::assertSame(42, $map->toOriginal(42));
        self::assertSame(9999, $map->toOriginal(9999));
    }

    public function testSameLengthReplacementsDoNotShiftPositions(): void
    {
        // <T> -> "   " (3 chars -> 3 chars) is the common case for the
        // `<...>` clauses on class headers and instantiation sites.  These
        // contribute no segment to the map.
        $map = ByteOffsetMap::fromReplacements([
            [10, 3, '   '],
            [40, 3, '   '],
        ]);
        self::assertSame(5, $map->toOriginal(5));
        self::assertSame(20, $map->toOriginal(20));
        self::assertSame(99, $map->toOriginal(99));
    }

    public function testTBracketRewriteShiftsDownstreamPositionsBackward(): void
    {
        // T[] (3 bytes) -> array (5 bytes) at original offset 12.
        // Positions BEFORE the replacement are unaffected.
        // Positions AFTER the replacement (i.e. strippedPos >= 17) map
        // back via strippedPos - 2.
        $map = ByteOffsetMap::fromReplacements([
            [12, 3, 'array'],
        ]);
        // Before the replacement.
        self::assertSame(0, $map->toOriginal(0));
        self::assertSame(11, $map->toOriginal(11));
        // Inside the replacement -- snap to original start.
        self::assertSame(12, $map->toOriginal(12));
        self::assertSame(12, $map->toOriginal(14));
        self::assertSame(12, $map->toOriginal(16));
        // After the replacement -- shift back by 2.
        self::assertSame(15, $map->toOriginal(17));
        self::assertSame(16, $map->toOriginal(18));
        self::assertSame(50, $map->toOriginal(52));
    }

    public function testMultipleTBracketRewritesAccumulateDelta(): void
    {
        // Original source has two T[] replacements:
        //   ...T[] $a; T[] $b...
        //      ^12     ^25 (original offsets)
        // After stripping: ...array $a; array $b...
        // Each rewrite adds 2 chars in the stripped source.
        $map = ByteOffsetMap::fromReplacements([
            [12, 3, 'array'],
            [25, 3, 'array'],
        ]);

        // After first replacement only (stripped pos 17..27 maps to original 15..25).
        // Wait, original 25 == stripped 27 (25 + cumDelta 2 prior).
        self::assertSame(15, $map->toOriginal(17));
        self::assertSame(24, $map->toOriginal(26));

        // Inside second replacement (stripped 27..31 == original "T[]" at 25..27).
        self::assertSame(25, $map->toOriginal(27));
        self::assertSame(25, $map->toOriginal(30));

        // After second replacement -- delta = -4 (two T[]->array each -2).
        self::assertSame(28, $map->toOriginal(32));
        self::assertSame(96, $map->toOriginal(100));
    }

    public function testReplacementsAreSortedRegardlessOfInputOrder(): void
    {
        // Input may arrive in any order (XphpSourceParser accumulates
        // replacements during a forward scan, but the map builder must
        // not depend on that).
        $unsorted = ByteOffsetMap::fromReplacements([
            [25, 3, 'array'],
            [12, 3, 'array'],
        ]);
        $sorted = ByteOffsetMap::fromReplacements([
            [12, 3, 'array'],
            [25, 3, 'array'],
        ]);
        // Same stripped offset must map to the same original offset.
        foreach ([0, 11, 17, 26, 30, 100] as $p) {
            self::assertSame($sorted->toOriginal($p), $unsorted->toOriginal($p), "diverges at stripped pos {$p}");
        }
    }
}
