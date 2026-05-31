<?php

declare(strict_types=1);

namespace XPHP\Lsp\Test\Resolver;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use XPHP\Lsp\Resolver\TypeUnionSplitter;

/**
 * Pins {@see TypeUnionSplitter::split} -- the Cycle K parser that
 * decomposes worse-reflection's `Type::__toString()` output into
 * the list of constituent class FQNs the LSP can navigate to.
 */
final class TypeUnionSplitterTest extends TestCase
{
    /**
     * @return iterable<string,array{0:string,1:list<list<string>>}>
     */
    public static function cases(): iterable
    {
        // (input, expected-split)
        yield 'plain class' => ['App\\Models\\User', [['App\\Models\\User']]];
        yield 'leading backslash stripped' => ['\\App\\Models\\User', [['App\\Models\\User']]];
        yield 'nullable single' => ['?App\\Models\\User', [['App\\Models\\User']]];
        yield 'two-arm union' => ['A|B', [['A'], ['B']]];
        yield 'plain intersection' => ['A&B', [['A', 'B']]];
        yield 'grouped intersection union' => ['(A&B)|C', [['A', 'B'], ['C']]];
        yield 'two grouped intersections' => ['(A&B)|(C&D)', [['A', 'B'], ['C', 'D']]];
        yield 'union with null dropped' => ['A|null', [['A']]];
        yield 'union with mixed null' => ['(A&B)|(C&D)|null', [['A', 'B'], ['C', 'D']]];
        yield 'nullable union' => ['?A|B', [['A'], ['B']]];

        // Pure-junk inputs: return an empty union (no class FQNs).
        yield 'empty string' => ['', []];
        yield 'missing sentinel' => ['<missing>', []];
        yield 'integer literal' => ['0', []];
        yield 'string literal' => ["'foo'", []];
        yield 'all-scalar union' => ['int|string', []];
        yield 'all-null' => ['null', []];
        yield 'self/parent dropped' => ['self|parent', []];
        yield 'bool keyword dropped' => ['true|false', []];

        // Dedup: same FQN repeated in an intersection collapses to
        // one entry.
        yield 'dedup within intersection' => ['A&A&B', [['A', 'B']]];

        // Single-class with leading nullable + leading backslash
        // both stripped.
        yield 'nullable leading backslash' => ['?\\App\\User', [['App\\User']]];
    }

    /**
     * @param list<list<string>> $expected
     */
    #[DataProvider('cases')]
    public function testSplitProducesExpectedDecomposition(string $input, array $expected): void
    {
        self::assertSame($expected, TypeUnionSplitter::split($input), $input);
    }

    public function testNestedParenUnwrap(): void
    {
        // Defensive: `((A&B))` collapses to `[['A','B']]`.  Doesn't
        // appear in worse-reflection output today, but the
        // recursive paren-unwrap guards against future emissions.
        self::assertSame([['A', 'B']], TypeUnionSplitter::split('((A&B))'));
    }
}
