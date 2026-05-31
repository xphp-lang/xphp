<?php

declare(strict_types=1);

namespace XPHP\Lsp\Test\Handler\SemanticTokens;

use PHPUnit\Framework\TestCase;
use XPHP\Lsp\Handler\SemanticTokens\Encoder;
use XPHP\Lsp\Handler\SemanticTokens\TokenLegend;
use XPHP\Lsp\Handler\SemanticTokens\TokenSpec;

final class EncoderTest extends TestCase
{
    public function testEmptyInputProducesEmptyArray(): void
    {
        self::assertSame([], Encoder::encode([]));
    }

    public function testSingleTokenEmitsAbsolutePosition(): void
    {
        $data = Encoder::encode([
            new TokenSpec(line: 3, startChar: 7, length: 5, type: 'keyword'),
        ]);

        // First token's deltaLine is the absolute line and deltaStart is the
        // absolute column.  Length, type index, modifier bits follow.
        self::assertSame(
            [3, 7, 5, TokenLegend::typeIndex('keyword'), 0],
            $data,
        );
    }

    public function testTwoTokensOnSameLineUseColumnDelta(): void
    {
        $data = Encoder::encode([
            new TokenSpec(line: 0, startChar: 0, length: 5, type: 'keyword'),
            new TokenSpec(line: 0, startChar: 10, length: 3, type: 'variable'),
        ]);

        self::assertSame(
            [
                0, 0, 5, TokenLegend::typeIndex('keyword'), 0,
                0, 10, 3, TokenLegend::typeIndex('variable'), 0, // deltaStart = 10 (column delta on same line)
            ],
            $data,
        );
    }

    public function testTokensOnDifferentLinesUseAbsoluteColumnAfterDeltaLine(): void
    {
        $data = Encoder::encode([
            new TokenSpec(line: 0, startChar: 5, length: 2, type: 'keyword'),
            new TokenSpec(line: 2, startChar: 8, length: 4, type: 'string'),
        ]);

        self::assertSame(
            [
                0, 5, 2, TokenLegend::typeIndex('keyword'), 0,
                2, 8, 4, TokenLegend::typeIndex('string'), 0, // deltaLine=2, deltaStart=absolute 8 (not column delta)
            ],
            $data,
        );
    }

    public function testUnknownTokenTypeIsDropped(): void
    {
        $data = Encoder::encode([
            new TokenSpec(line: 0, startChar: 0, length: 5, type: 'keyword'),
            new TokenSpec(line: 0, startChar: 10, length: 3, type: 'not-in-legend'),
            new TokenSpec(line: 1, startChar: 2, length: 4, type: 'variable'),
        ]);

        // The middle spec must vanish and the next token's delta must skip
        // straight from the previous valid token (line 0, col 0) to the
        // valid third spec (line 1, col 2).
        self::assertSame(
            [
                0, 0, 5, TokenLegend::typeIndex('keyword'), 0,
                1, 2, 4, TokenLegend::typeIndex('variable'), 0,
            ],
            $data,
        );
    }

    public function testModifiersAreEncodedAsBitfield(): void
    {
        $data = Encoder::encode([
            new TokenSpec(
                line: 0,
                startChar: 0,
                length: 4,
                type: 'method',
                modifiers: ['static', 'declaration'],
            ),
        ]);

        $expectedBits = (1 << array_search('static', TokenLegend::TOKEN_MODIFIERS, true))
            | (1 << array_search('declaration', TokenLegend::TOKEN_MODIFIERS, true));
        self::assertSame(
            [0, 0, 4, TokenLegend::typeIndex('method'), $expectedBits],
            $data,
        );
    }

    public function testUnsortedInputIsReSortedBySourceOrder(): void
    {
        // Visitor emits tokens out of source order (e.g. a method body before
        // its own signature).  The encoder must put them back in order before
        // delta-encoding -- otherwise delta-line goes negative and clients
        // render garbage.
        $data = Encoder::encode([
            new TokenSpec(line: 5, startChar: 0, length: 3, type: 'keyword'),
            new TokenSpec(line: 1, startChar: 10, length: 4, type: 'string'),
        ]);

        // Sorted order: (1, 10), then (5, 0).  Result:
        //   deltaLine=1, deltaStart=10 (absolute, first emitted)
        //   deltaLine=4 (=5-1), deltaStart=0 (absolute, since deltaLine != 0)
        self::assertSame(
            [
                1, 10, 4, TokenLegend::typeIndex('string'), 0,
                4, 0, 3, TokenLegend::typeIndex('keyword'), 0,
            ],
            $data,
        );
    }

    public function testSameLineColumnDeltaIsRelativeToPreviousToken(): void
    {
        $data = Encoder::encode([
            new TokenSpec(line: 0, startChar: 0, length: 5, type: 'keyword'),
            new TokenSpec(line: 0, startChar: 6, length: 4, type: 'variable'),
            new TokenSpec(line: 0, startChar: 11, length: 3, type: 'method'),
        ]);

        // Token 2: deltaStart = 6 - 0 = 6.
        // Token 3: deltaStart = 11 - 6 = 5.
        self::assertSame(
            [
                0, 0, 5, TokenLegend::typeIndex('keyword'), 0,
                0, 6, 4, TokenLegend::typeIndex('variable'), 0,
                0, 5, 3, TokenLegend::typeIndex('method'), 0,
            ],
            $data,
        );
    }
}
