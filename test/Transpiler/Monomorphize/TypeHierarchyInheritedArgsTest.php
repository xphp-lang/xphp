<?php

declare(strict_types=1);

namespace XPHP\Transpiler\Monomorphize;

use PhpParser\ParserFactory;
use PHPUnit\Framework\TestCase;

/**
 * Coverage for {@see TypeHierarchy::resolveInheritedArgs} — threading a receiver's concrete type
 * arguments up the parameterized `extends`/`implements` chain to a method's declaring class. The
 * graph tests drive the constructor directly (precise control); the last test parses real `.xphp`
 * via {@see XphpSourceParser} to pin the capture wiring and the head/arg FQN-resolver agreement.
 */
final class TypeHierarchyInheritedArgsTest extends TestCase
{
    private static function tp(string $name): TypeRef
    {
        return new TypeRef($name, isTypeParam: true);
    }

    /** @param list<TypeRef> $args */
    private static function ref(string $name, array $args = []): TypeRef
    {
        return new TypeRef($name, $args);
    }

    /**
     * @param array<string, list<TypeRef>> $superTypeArgs
     * @param array<string, list<string>> $typeParamNames
     */
    private static function hierarchy(array $superTypeArgs, array $typeParamNames): TypeHierarchy
    {
        return new TypeHierarchy([], $superTypeArgs, $typeParamNames);
    }

    /**
     * @param list<TypeRef>|null $args
     * @return list<string>|null
     */
    private static function canon(?array $args): ?array
    {
        return $args === null ? null : array_map(static fn (TypeRef $a): string => $a->canonical(), $args);
    }

    public function testDirectHitReturnsSubArgsUnchanged(): void
    {
        $h = self::hierarchy([], ['App\\Box' => ['E']]);

        self::assertSame(
            ['App\\Product'],
            self::canon($h->resolveInheritedArgs('App\\Box', [self::ref('App\\Product')], 'App\\Box')),
        );
    }

    public function testSingleHopGroundsTheClauseArg(): void
    {
        // ArrayList<E> implements Collection<E>
        $h = self::hierarchy(
            ['App\\ArrayList' => [self::ref('App\\Collection', [self::tp('E')])], 'App\\Collection' => []],
            ['App\\ArrayList' => ['E'], 'App\\Collection' => ['E']],
        );

        self::assertSame(
            ['App\\Product'],
            self::canon($h->resolveInheritedArgs('App\\ArrayList', [self::ref('App\\Product')], 'App\\Collection')),
        );
    }

    public function testMultiHopChainThreadsThrough(): void
    {
        // ArrayList<E> -> OrderedCollection<E> -> Collection<E>
        $h = self::hierarchy(
            [
                'App\\ArrayList' => [self::ref('App\\OrderedCollection', [self::tp('E')])],
                'App\\OrderedCollection' => [self::ref('App\\Collection', [self::tp('E')])],
                'App\\Collection' => [],
            ],
            ['App\\ArrayList' => ['E'], 'App\\OrderedCollection' => ['E'], 'App\\Collection' => ['E']],
        );

        self::assertSame(
            ['App\\Product'],
            self::canon($h->resolveInheritedArgs('App\\ArrayList', [self::ref('App\\Product')], 'App\\Collection')),
        );
    }

    public function testReorderedMultiArgClauseIsPositionallyZipped(): void
    {
        // Rev<A,B> implements Pair<B,A> — params must zip positionally, swapped at the clause.
        $h = self::hierarchy(
            ['App\\Rev' => [self::ref('App\\Pair', [self::tp('B'), self::tp('A')])], 'App\\Pair' => []],
            ['App\\Rev' => ['A', 'B'], 'App\\Pair' => ['K', 'V']],
        );

        self::assertSame(
            ['App\\Y', 'App\\X'],
            self::canon($h->resolveInheritedArgs('App\\Rev', [self::ref('App\\X'), self::ref('App\\Y')], 'App\\Pair')),
        );
    }

    public function testNestedClauseArgGroundsThroughRecursion(): void
    {
        // Wrap<E> implements Holder<Box<E>> — the type-param is nested inside the clause arg.
        $h = self::hierarchy(
            ['App\\Wrap' => [self::ref('App\\Holder', [self::ref('App\\Box', [self::tp('E')])])], 'App\\Holder' => []],
            ['App\\Wrap' => ['E'], 'App\\Holder' => ['T']],
        );

        self::assertSame(
            ['App\\Box<App\\Product>'],
            self::canon($h->resolveInheritedArgs('App\\Wrap', [self::ref('App\\Product')], 'App\\Holder')),
        );
    }

    public function testTypeParamSubArgsPropagateUnchanged(): void
    {
        // The `$this`-receiver shape: subArgs are identity type-params, so the grounding stays a
        // type-param (WI-4 then drops the bound leniently).
        $h = self::hierarchy(
            ['App\\ArrayList' => [self::ref('App\\Collection', [self::tp('E')])], 'App\\Collection' => []],
            ['App\\ArrayList' => ['E'], 'App\\Collection' => ['E']],
        );

        self::assertSame(
            ['E'],
            self::canon($h->resolveInheritedArgs('App\\ArrayList', [self::tp('E')], 'App\\Collection')),
        );
    }

    public function testDiamondWithAgreeingArgsResolvesOnce(): void
    {
        // A<E> -> B<E>, C<E>; B<E> -> D<E>, C<E> -> D<E>. Both paths agree → single grounding.
        $h = self::hierarchy(
            [
                'App\\A' => [self::ref('App\\B', [self::tp('E')]), self::ref('App\\C', [self::tp('E')])],
                'App\\B' => [self::ref('App\\D', [self::tp('E')])],
                'App\\C' => [self::ref('App\\D', [self::tp('E')])],
                'App\\D' => [],
            ],
            ['App\\A' => ['E'], 'App\\B' => ['E'], 'App\\C' => ['E'], 'App\\D' => ['E']],
        );

        self::assertSame(
            ['App\\Product'],
            self::canon($h->resolveInheritedArgs('App\\A', [self::ref('App\\Product')], 'App\\D')),
        );
    }

    public function testDiamondWithConflictingArgsIsAmbiguousAndReturnsNull(): void
    {
        // A<E> -> B<E>, C<Box<E>>; B<T> -> D<T>, C<T> -> D<T>. The two paths ground D to Product vs
        // Box<Product> — a conflict the resolver must report as null (not pick one arbitrarily).
        $h = self::hierarchy(
            [
                'App\\A' => [
                    self::ref('App\\B', [self::tp('E')]),
                    self::ref('App\\C', [self::ref('App\\Box', [self::tp('E')])]),
                ],
                'App\\B' => [self::ref('App\\D', [self::tp('T')])],
                'App\\C' => [self::ref('App\\D', [self::tp('T')])],
                'App\\D' => [],
            ],
            ['App\\A' => ['E'], 'App\\B' => ['T'], 'App\\C' => ['T'], 'App\\D' => ['T']],
        );

        self::assertNull($h->resolveInheritedArgs('App\\A', [self::ref('App\\Product')], 'App\\D'));
    }

    public function testMultiArgClauseUsingOnlyOneParamThreadsTheRightArg(): void
    {
        // ImmutableMap<K,V> implements Collection<V> — only the index-1 param flows up (the
        // `Map<K,+V>::containsValue<U:V>` shape). The dropped K must not leak into the grounding.
        $h = self::hierarchy(
            ['App\\ImmutableMap' => [self::ref('App\\Collection', [self::tp('V')])], 'App\\Collection' => []],
            ['App\\ImmutableMap' => ['K', 'V'], 'App\\Collection' => ['E']],
        );

        self::assertSame(
            ['App\\Product'],
            self::canon($h->resolveInheritedArgs('App\\ImmutableMap', [self::ref('App\\Str'), self::ref('App\\Product')], 'App\\Collection')),
        );
    }

    public function testClauseReferencingAnOutOfScopeParamPropagatesItUnchanged(): void
    {
        // A clause arg naming a type-param absent from the class's own params is left untouched by
        // substitution, so it grounds to a stray type-param (which WI-4 then drops leniently).
        $h = self::hierarchy(
            ['App\\Odd' => [self::ref('App\\Collection', [self::tp('F')])], 'App\\Collection' => []],
            ['App\\Odd' => ['E'], 'App\\Collection' => ['E']],
        );

        self::assertSame(
            ['F'],
            self::canon($h->resolveInheritedArgs('App\\Odd', [self::ref('App\\Product')], 'App\\Collection')),
        );
    }

    public function testUnreachableTargetReturnsNull(): void
    {
        $h = self::hierarchy(
            ['App\\ArrayList' => [self::ref('App\\Collection', [self::tp('E')])], 'App\\Collection' => []],
            ['App\\ArrayList' => ['E'], 'App\\Collection' => ['E']],
        );

        self::assertNull($h->resolveInheritedArgs('App\\ArrayList', [self::ref('App\\Product')], 'App\\Stringable'));
    }

    public function testArityMismatchAtAHopReturnsNull(): void
    {
        // ArrayList declares one param but the caller supplies two args — an unbridgeable gap.
        $h = self::hierarchy(
            ['App\\ArrayList' => [self::ref('App\\Collection', [self::tp('E')])], 'App\\Collection' => []],
            ['App\\ArrayList' => ['E'], 'App\\Collection' => ['E']],
        );

        self::assertNull(
            $h->resolveInheritedArgs('App\\ArrayList', [self::ref('App\\Product'), self::ref('App\\Extra')], 'App\\Collection'),
        );
    }

    public function testRegularCycleTerminatesWithNull(): void
    {
        // A<E> implements B<E>, B<E> implements A<E> — the per-path visited set breaks the cycle.
        $h = self::hierarchy(
            [
                'App\\A' => [self::ref('App\\B', [self::tp('E')])],
                'App\\B' => [self::ref('App\\A', [self::tp('E')])],
            ],
            ['App\\A' => ['E'], 'App\\B' => ['E']],
        );

        self::assertNull($h->resolveInheritedArgs('App\\A', [self::ref('App\\Product')], 'App\\Unreachable'));
    }

    public function testExpansiveRecursionTerminatesWithNull(): void
    {
        // Loop<T> implements Loop<Box<T>> — a non-regular (ever-growing) recursion. The on-path guard
        // stops it on the second visit to Loop, so it returns promptly rather than looping forever.
        $h = self::hierarchy(
            ['App\\Loop' => [self::ref('App\\Loop', [self::ref('App\\Box', [self::tp('T')])])]],
            ['App\\Loop' => ['T']],
        );

        self::assertNull($h->resolveInheritedArgs('App\\Loop', [self::ref('int')], 'App\\Unreachable'));
    }

    public function testLeadingBackslashOnSubAndSuperFqnIsNormalized(): void
    {
        // Callers may pass fully-qualified (leading-`\`) names; the resolver normalizes both ends.
        $h = self::hierarchy(
            ['App\\ArrayList' => [self::ref('App\\Collection', [self::tp('E')])], 'App\\Collection' => []],
            ['App\\ArrayList' => ['E'], 'App\\Collection' => ['E']],
        );

        self::assertSame(
            ['App\\Product'],
            self::canon($h->resolveInheritedArgs('\\App\\ArrayList', [self::ref('App\\Product')], '\\App\\Collection')),
        );
    }

    public function testLeadingBackslashOnAClauseNameIsNormalized(): void
    {
        // A supertype clause whose head is fully-qualified still keys onto the target.
        $h = self::hierarchy(
            ['App\\ArrayList' => [self::ref('\\App\\Collection', [self::tp('E')])], 'App\\Collection' => []],
            ['App\\ArrayList' => ['E'], 'App\\Collection' => ['E']],
        );

        self::assertSame(
            ['App\\Product'],
            self::canon($h->resolveInheritedArgs('App\\ArrayList', [self::ref('App\\Product')], 'App\\Collection')),
        );
    }

    public function testCaptureFromRealParserGroundsAnAliasedSupertypeArg(): void
    {
        // Real xphp parse: the head FQN (App\Collection, via collectFromAst::resolveName) and the
        // clause arg FQN (App\Models\Product, resolved by the parser from the `use` alias) come from
        // two independent resolvers; a successful grounding proves they agree.
        $src = <<<'PHP'
        <?php
        namespace App;

        use App\Models\Product;

        interface Collection<E> {}
        class ArrayList<E> implements Collection<Product> {}
        PHP;

        $parser = new XphpSourceParser((new ParserFactory())->createForHostVersion());
        $ast = $parser->parse($src);
        $h = TypeHierarchy::fromAstPerFile(['/x.xphp' => $ast]);

        // ArrayList passes the concrete `Product` (not its own E) to Collection, so the grounding is
        // Product regardless of the receiver's E argument.
        self::assertSame(
            ['App\\Models\\Product'],
            self::canon($h->resolveInheritedArgs('App\\ArrayList', [self::ref('App\\Whatever')], 'App\\Collection')),
        );
    }
}
