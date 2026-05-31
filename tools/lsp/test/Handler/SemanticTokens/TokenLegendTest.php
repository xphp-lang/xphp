<?php

declare(strict_types=1);

namespace XPHP\Lsp\Test\Handler\SemanticTokens;

use PHPUnit\Framework\TestCase;
use XPHP\Lsp\Handler\SemanticTokens\TokenLegend;

final class TokenLegendTest extends TestCase
{
    public function testKnownTokenTypeReturnsItsIndex(): void
    {
        self::assertSame(0, TokenLegend::typeIndex(TokenLegend::TOKEN_TYPES[0]));
        self::assertSame(
            count(TokenLegend::TOKEN_TYPES) - 1,
            TokenLegend::typeIndex(TokenLegend::TOKEN_TYPES[count(TokenLegend::TOKEN_TYPES) - 1]),
        );
    }

    public function testUnknownTokenTypeReturnsMinusOne(): void
    {
        self::assertSame(-1, TokenLegend::typeIndex('not-in-legend'));
    }

    public function testTypeParameterIsInLegend(): void
    {
        // Load-bearing for Slice 3 -- xphp `T` references emit `typeParameter`.
        self::assertGreaterThanOrEqual(0, TokenLegend::typeIndex('typeParameter'));
    }

    public function testEmptyModifierListEncodesAsZero(): void
    {
        self::assertSame(0, TokenLegend::modifierBits([]));
    }

    public function testSingleKnownModifierSetsOneBit(): void
    {
        $bits = TokenLegend::modifierBits(['static']);
        $expected = 1 << array_search('static', TokenLegend::TOKEN_MODIFIERS, true);
        self::assertSame($expected, $bits);
    }

    public function testMultipleModifiersAreOred(): void
    {
        $bits = TokenLegend::modifierBits(['static', 'readonly']);
        $expected = (1 << array_search('static', TokenLegend::TOKEN_MODIFIERS, true))
            | (1 << array_search('readonly', TokenLegend::TOKEN_MODIFIERS, true));
        self::assertSame($expected, $bits);
    }

    public function testUnknownModifierIsSilentlyDropped(): void
    {
        $bits = TokenLegend::modifierBits(['static', 'not-in-legend']);
        $expected = 1 << array_search('static', TokenLegend::TOKEN_MODIFIERS, true);
        self::assertSame($expected, $bits);
    }

    public function testLegendsAreNonEmpty(): void
    {
        // Empty legends would cause the client to refuse the
        // semanticTokensProvider capability.
        self::assertNotEmpty(TokenLegend::TOKEN_TYPES);
        self::assertNotEmpty(TokenLegend::TOKEN_MODIFIERS);
    }
}
