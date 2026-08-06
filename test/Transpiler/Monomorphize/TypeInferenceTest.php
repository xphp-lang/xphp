<?php

declare(strict_types=1);

namespace XPHP\Transpiler\Monomorphize;

use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\NullableType;
use PhpParser\Node\Param;
use PhpParser\Node\UnionType;
use PHPUnit\Framework\TestCase;

/**
 * Unit coverage for the pure inference core — the unifier, the driver, and paramTypeRef. The
 * monomorphizer integration (flow-typed arguments, both compile/check modes, runtime execution)
 * is exercised by the fixture-based integration tests added with the call/new inference passes;
 * here we pin the algorithm in isolation, where every branch is directly mutation-testable.
 */
final class TypeInferenceTest extends TestCase
{
    private const BOX = 'App\\Box';
    private const COLLECTION = 'App\\Collection';
    private const ARRAY_LIST = 'App\\ArrayList';

    // ---- infer(): the headline shapes --------------------------------------------------------

    public function testInfersSingleTypeParamFromScalarArgument(): void
    {
        // identity<T>(T $x) called with an int → [int]
        $result = $this->infer()->infer(
            [new TypeParam('T')],
            [self::param('x', new Name('T'))],
            [self::arg('a')],
            self::typer(['a' => self::int()]),
        );
        self::assertSame('int', self::canonicals($result));
    }

    public function testMultiOccurrenceConsistentBindingSucceeds(): void
    {
        // pair<T>(T $a, T $b) with (int, int) → [int]
        $result = $this->infer()->infer(
            [new TypeParam('T')],
            [self::param('a', new Name('T')), self::param('b', new Name('T'))],
            [self::arg('x'), self::arg('y')],
            self::typer(['x' => self::int(), 'y' => self::int()]),
        );
        self::assertSame('int', self::canonicals($result));
    }

    public function testMultiOccurrenceConflictFallsBack(): void
    {
        // pair<T>(T $a, T $b) with (int, string) → conflict → null
        $result = $this->infer()->infer(
            [new TypeParam('T')],
            [self::param('a', new Name('T')), self::param('b', new Name('T'))],
            [self::arg('x'), self::arg('y')],
            self::typer(['x' => self::int(), 'y' => self::string()]),
        );
        self::assertNull($result);
    }

    public function testInfersThroughMatchingParametricHead(): void
    {
        // unwrap<T>(Box<T> $b) with Box<int> → [int]
        $result = $this->infer()->infer(
            [new TypeParam('T')],
            [self::param('b', self::generic(self::BOX, [self::typeParamRef('T')]))],
            [self::arg('x')],
            self::typer(['x' => self::box(self::int())]),
        );
        self::assertSame('int', self::canonicals($result));
    }

    public function testInfersThroughNestedParametricHeads(): void
    {
        // f<T>(Box<Box<T>> $b) with Box<Box<int>> → [int]
        $result = $this->infer()->infer(
            [new TypeParam('T')],
            [self::param('b', self::generic(self::BOX, [
                new TypeRef(self::BOX, [self::typeParamRef('T')]),
            ]))],
            [self::arg('x')],
            self::typer(['x' => self::box(self::box(self::int()))]),
        );
        self::assertSame('int', self::canonicals($result));
    }

    public function testInfersThroughSubtypeSupertypeChain(): void
    {
        // take<T>(Collection<T> $c) with an ArrayList<int> argument → [int]
        $result = $this->infer($this->arrayListImplementsCollection())->infer(
            [new TypeParam('T')],
            [self::param('c', self::generic(self::COLLECTION, [self::typeParamRef('T')]))],
            [self::arg('x')],
            self::typer(['x' => new TypeRef(self::ARRAY_LIST, [self::int()])]),
        );
        self::assertSame('int', self::canonicals($result));
    }

    public function testAmbiguousSupertypePathFallsBack(): void
    {
        // A type that grounds Collection to two different args along two paths → null.
        $hierarchy = new TypeHierarchy(
            ['App\\Weird' => [self::COLLECTION]],
            ['App\\Weird' => [
                new TypeRef(self::COLLECTION, [self::int()]),
                new TypeRef(self::COLLECTION, [self::string()]),
            ]],
            ['App\\Weird' => [], self::COLLECTION => ['E']],
        );
        $result = $this->infer($hierarchy)->infer(
            [new TypeParam('T')],
            [self::param('c', self::generic(self::COLLECTION, [self::typeParamRef('T')]))],
            [self::arg('x')],
            self::typer(['x' => new TypeRef('App\\Weird', [])]),
        );
        self::assertNull($result);
    }

    public function testUnrelatedParametricHeadFallsBack(): void
    {
        // Collection<T> parameter, an argument whose type reaches no Collection supertype → null.
        $result = $this->infer()->infer(
            [new TypeParam('T')],
            [self::param('c', self::generic(self::COLLECTION, [self::typeParamRef('T')]))],
            [self::arg('x')],
            self::typer(['x' => new TypeRef('App\\Unrelated', [self::int()])]),
        );
        self::assertNull($result);
    }

    public function testArityMismatchOnParametricHeadFallsBack(): void
    {
        // Pair<A,B> parameter head, a two-arg argument against a one-arg parameter shape → null.
        $result = $this->infer()->infer(
            [new TypeParam('T')],
            [self::param('p', self::generic('App\\Pair', [self::typeParamRef('T')]))],
            [self::arg('x')],
            self::typer(['x' => new TypeRef('App\\Pair', [self::int(), self::string()])]),
        );
        self::assertNull($result);
    }

    public function testDefaultedTrailingParamIsOmittedFromInferredPrefix(): void
    {
        // pair<A, B = A>(A $a) with an int → [int]; B is left for padding to default.
        $result = $this->infer()->infer(
            [new TypeParam('A'), new TypeParam('B', null, self::typeParamRef('A'))],
            [self::param('a', new Name('A'))],
            [self::arg('x')],
            self::typer(['x' => self::int()]),
        );
        self::assertSame('int', self::canonicals($result));
    }

    public function testHoleBetweenBoundParamsFallsBack(): void
    {
        // two<A, B>(B $b): only the second type param is witnessed → not a clean prefix → null.
        $result = $this->infer()->infer(
            [new TypeParam('A'), new TypeParam('B')],
            [self::param('b', new Name('B'))],
            [self::arg('x')],
            self::typer(['x' => self::int()]),
        );
        self::assertNull($result);
    }

    public function testUnwitnessedRequiredParamFallsBack(): void
    {
        // two<A, B>(A $a): B is required but has no argument witness → null.
        $result = $this->infer()->infer(
            [new TypeParam('A'), new TypeParam('B')],
            [self::param('a', new Name('A'))],
            [self::arg('x')],
            self::typer(['x' => self::int()]),
        );
        self::assertNull($result);
    }

    public function testUnknownArgumentTypeLeavesParamUnconstrained(): void
    {
        // identity<T>(T $x) with an argument the typer cannot type → null.
        $result = $this->infer()->infer(
            [new TypeParam('T')],
            [self::param('x', new Name('T'))],
            [self::arg('a')],
            self::typer([]),
        );
        self::assertNull($result);
    }

    public function testAbstractArgumentAgainstTypeParamFallsBack(): void
    {
        // A non-concrete argument type cannot bind a type parameter → null.
        $result = $this->infer()->infer(
            [new TypeParam('T')],
            [self::param('x', new Name('T'))],
            [self::arg('a')],
            self::typer(['a' => self::typeParamRef('U')]),
        );
        self::assertNull($result);
    }

    public function testUnionTypedParamIsSkipped(): void
    {
        // f<T>(int|string $u, T $x): the union parameter is a shape inference does not model, so it
        // is skipped (short-circuit) and T is still inferred from the second argument → [int].
        $result = $this->infer()->infer(
            [new TypeParam('T')],
            [
                self::param('u', new UnionType([new Identifier('int'), new Identifier('string')])),
                self::param('x', new Name('T')),
            ],
            [self::arg('a'), self::arg('b')],
            self::typer(['a' => self::int(), 'b' => self::int()]),
        );
        self::assertSame('int', self::canonicals($result));
    }

    public function testEarlierUnknownArgumentDoesNotBlockLaterBinding(): void
    {
        // pair<T>(T $a, T $b) with (unknown, int): the first argument's type is unknown, but the
        // second still witnesses T → [int]. (Distinguishes "skip this pair" from "stop pairing".)
        $result = $this->infer()->infer(
            [new TypeParam('T')],
            [self::param('a', new Name('T')), self::param('b', new Name('T'))],
            [self::arg('x'), self::arg('y')],
            self::typer(['y' => self::int()]),
        );
        self::assertSame('int', self::canonicals($result));
    }

    public function testConcreteGenericParamImposesNoConstraint(): void
    {
        // f<T>(Box<int> $fixed, T $x) with (an unrelated-typed arg, int): the concrete Box<int>
        // parameter mentions no type parameter, so it is skipped rather than unified against the
        // unrelated argument (which would spuriously fail) → [int].
        $result = $this->infer()->infer(
            [new TypeParam('T')],
            [
                self::param('fixed', self::generic(self::BOX, [self::int()])),
                self::param('x', new Name('T')),
            ],
            [self::arg('a'), self::arg('b')],
            self::typer(['a' => new TypeRef('App\\Unrelated', [self::int()]), 'b' => self::int()]),
        );
        self::assertSame('int', self::canonicals($result));
    }

    public function testHoleAfterDefaultedParamFallsBack(): void
    {
        // pair<A = int, B = int>(B $b): only the second (defaulted) param is witnessed while the
        // first is not — a hole that would misalign the prefix, so inference falls back → null.
        $result = $this->infer()->infer(
            [new TypeParam('A', null, self::int()), new TypeParam('B', null, self::int())],
            [self::param('b', new Name('B'))],
            [self::arg('x')],
            self::typer(['x' => self::string()]),
        );
        self::assertNull($result);
    }

    public function testPlainConcreteParamImposesNoConstraint(): void
    {
        // f<T>(int $n, T $x) with (int, string) → [string]; the int parameter constrains nothing.
        $result = $this->infer()->infer(
            [new TypeParam('T')],
            [self::param('n', new Identifier('int')), self::param('x', new Name('T'))],
            [self::arg('a'), self::arg('b')],
            self::typer(['a' => self::int(), 'b' => self::string()]),
        );
        self::assertSame('string', self::canonicals($result));
    }

    public function testNonGenericCalleeInfersNothing(): void
    {
        self::assertNull($this->infer()->infer([], [], [], self::typer([])));
    }

    public function testAllDefaultsTemplateWithNoTypeParamParamsStaysBare(): void
    {
        // cache<T = int>() — no parameter mentions T, so nothing is inferred; the site stays bare
        // and the existing all-defaults path is untouched.
        $result = $this->infer()->infer(
            [new TypeParam('T', null, self::int())],
            [],
            [],
            self::typer([]),
        );
        self::assertNull($result);
    }

    // ---- argument pairing ---------------------------------------------------------------------

    public function testNamedArgumentsBindByParameterName(): void
    {
        // kv<K, V>(K $k, V $v) called v: string, k: int (out of order) → [int, string]
        $result = $this->infer()->infer(
            [new TypeParam('K'), new TypeParam('V')],
            [self::param('k', new Name('K')), self::param('v', new Name('V'))],
            [self::namedArg('v', 'sv'), self::namedArg('k', 'sk')],
            self::typer(['sv' => self::string(), 'sk' => self::int()]),
        );
        self::assertSame('int,string', self::canonicals($result));
    }

    public function testUnknownNamedArgumentIsDropped(): void
    {
        // A named argument matching no parameter is ignored; T stays unwitnessed → null.
        $result = $this->infer()->infer(
            [new TypeParam('T')],
            [self::param('x', new Name('T'))],
            [self::namedArg('nope', 'a')],
            self::typer(['a' => self::int()]),
        );
        self::assertNull($result);
    }

    public function testSpreadStopsPositionalPairing(): void
    {
        // f<A, B>(A $a, B $b): a spread in first position leaves both params unwitnessed → null.
        $spread = new Arg(new Variable('rest'), unpack: true);
        $result = $this->infer()->infer(
            [new TypeParam('A'), new TypeParam('B')],
            [self::param('a', new Name('A')), self::param('b', new Name('B'))],
            [$spread, self::arg('x')],
            self::typer(['x' => self::int()]),
        );
        self::assertNull($result);
    }

    public function testVariadicParamAbsorbsOverflowConsistently(): void
    {
        // all<T>(T ...$xs) with (int, int) → [int]
        $result = $this->infer()->infer(
            [new TypeParam('T')],
            [self::variadicParam('xs', new Name('T'))],
            [self::arg('a'), self::arg('b')],
            self::typer(['a' => self::int(), 'b' => self::int()]),
        );
        self::assertSame('int', self::canonicals($result));
    }

    public function testVariadicParamConflictFallsBack(): void
    {
        // all<T>(T ...$xs) with (int, string) → conflict via the shared variadic slot → null
        $result = $this->infer()->infer(
            [new TypeParam('T')],
            [self::variadicParam('xs', new Name('T'))],
            [self::arg('a'), self::arg('b')],
            self::typer(['a' => self::int(), 'b' => self::string()]),
        );
        self::assertNull($result);
    }

    public function testExcessPositionalArgWithoutVariadicIsDropped(): void
    {
        // one<T>(T $a) called with a second positional arg: the overflow has no parameter and is
        // dropped; inference still succeeds from the first argument → [int].
        $result = $this->infer()->infer(
            [new TypeParam('T')],
            [self::param('a', new Name('T'))],
            [self::arg('x'), self::arg('y')],
            self::typer(['x' => self::int(), 'y' => self::string()]),
        );
        self::assertSame('int', self::canonicals($result));
    }

    public function testFirstClassCallablePlaceholderIsSkipped(): void
    {
        // identity(...) — the placeholder carries no value, so nothing is inferred → null.
        $result = $this->infer()->infer(
            [new TypeParam('T')],
            [self::param('x', new Name('T'))],
            [new Node\VariadicPlaceholder()],
            self::typer([]),
        );
        self::assertNull($result);
    }

    // ---- unify(): direct micro-coverage -------------------------------------------------------

    public function testUnifyBindsTypeParamLeaf(): void
    {
        $bindings = [];
        self::assertTrue($this->infer()->unify(self::typeParamRef('T'), self::int(), $bindings));
        self::assertSame('int', $bindings['T']->canonical());
    }

    public function testUnifyRejectsNonConcreteArgument(): void
    {
        $bindings = [];
        self::assertFalse(
            $this->infer()->unify(self::typeParamRef('T'), self::typeParamRef('U'), $bindings),
        );
        self::assertSame([], $bindings);
    }

    public function testUnifyRejectsConflictingRebinding(): void
    {
        $bindings = ['T' => self::int()];
        self::assertFalse($this->infer()->unify(self::typeParamRef('T'), self::string(), $bindings));
    }

    public function testUnifyPlainConcreteLeafSucceedsWithoutBinding(): void
    {
        $bindings = [];
        self::assertTrue($this->infer()->unify(self::int(), self::string(), $bindings));
        self::assertSame([], $bindings);
    }

    public function testUnifyRejectsUnrelatedParametricHead(): void
    {
        // Box<T> against Collection<int>: the argument's head neither matches nor reaches Box.
        $bindings = [];
        self::assertFalse($this->infer()->unify(
            new TypeRef(self::BOX, [self::typeParamRef('T')]),
            new TypeRef(self::COLLECTION, [self::int()]),
            $bindings,
        ));
    }

    public function testUnifyRejectsParametricHeadArityMismatch(): void
    {
        // Box<T> (one arg) against Box<int, string> (two): a direct-hit head with mismatched arity.
        $bindings = [];
        self::assertFalse($this->infer()->unify(
            new TypeRef(self::BOX, [self::typeParamRef('T')]),
            new TypeRef(self::BOX, [self::int(), self::string()]),
            $bindings,
        ));
    }

    // ---- argument pairing: spread and placeholder branches -----------------------------------

    public function testSpreadDisablesPositionalPairing(): void
    {
        // f<T>(T $a) with (...$rest, positional int): the spread makes the following positional
        // unsound to pair, so T is never witnessed → null.
        $spread = new Arg(new Variable('rest'), unpack: true);
        $result = $this->infer()->infer(
            [new TypeParam('T')],
            [self::param('a', new Name('T'))],
            [$spread, self::arg('x')],
            self::typer(['x' => self::int()]),
        );
        self::assertNull($result);
    }

    public function testNamedArgumentsAfterSpreadStillBind(): void
    {
        // f<T>(T $a, T $b) with (...$rest, a: int, b: int): a spread stops positional pairing but
        // named arguments after it still bind by name → [int].
        $spread = new Arg(new Variable('rest'), unpack: true);
        $result = $this->infer()->infer(
            [new TypeParam('T')],
            [self::param('a', new Name('T')), self::param('b', new Name('T'))],
            [$spread, self::namedArg('a', 'x'), self::namedArg('b', 'y')],
            self::typer(['x' => self::int(), 'y' => self::int()]),
        );
        self::assertSame('int', self::canonicals($result));
    }

    public function testPositionalAfterSpreadSkippedButLaterNamedBinds(): void
    {
        // f<T>(T $a, T $b) with (...$rest, positional junk, a: int, b: int): the post-spread
        // positional is skipped (not a hard stop), and the later named arguments still bind → [int].
        $spread = new Arg(new Variable('rest'), unpack: true);
        $result = $this->infer()->infer(
            [new TypeParam('T')],
            [self::param('a', new Name('T')), self::param('b', new Name('T'))],
            [$spread, self::arg('junk'), self::namedArg('a', 'x'), self::namedArg('b', 'y')],
            self::typer(['junk' => self::string(), 'x' => self::int(), 'y' => self::int()]),
        );
        self::assertSame('int', self::canonicals($result));
    }

    public function testPlaceholderBeforeArgumentDoesNotStopPairing(): void
    {
        // A first-class-callable placeholder is skipped (not a hard stop): a following ordinary
        // argument still pairs. (Defensive — valid PHP never mixes the two, but the pairing must
        // not break if it sees this shape.)
        $result = $this->infer()->infer(
            [new TypeParam('T')],
            [self::param('a', new Name('T'))],
            [new Node\VariadicPlaceholder(), self::arg('x')],
            self::typer(['x' => self::int()]),
        );
        self::assertSame('int', self::canonicals($result));
    }

    // ---- paramTypeRef() -----------------------------------------------------------------------

    public function testParamTypeRefMarksTypeParamLeaf(): void
    {
        $ref = TypeInference::paramTypeRef(new Name('T'), ['T' => true]);
        self::assertNotNull($ref);
        self::assertTrue($ref->isTypeParam);
        self::assertSame('T', $ref->name);
    }

    public function testParamTypeRefUnwrapsNullable(): void
    {
        $ref = TypeInference::paramTypeRef(new NullableType(new Name('T')), ['T' => true]);
        self::assertNotNull($ref);
        self::assertTrue($ref->isTypeParam);
    }

    public function testParamTypeRefTreatsScalarAsConcrete(): void
    {
        $ref = TypeInference::paramTypeRef(new Identifier('INT'), ['T' => true]);
        self::assertNotNull($ref);
        self::assertFalse($ref->isTypeParam);
        self::assertTrue($ref->isScalar);
        self::assertSame('int', $ref->name);
    }

    public function testParamTypeRefReadsGenericArgsAndResolvedFqn(): void
    {
        $ref = TypeInference::paramTypeRef(self::generic(self::BOX, [self::typeParamRef('T')]), ['T' => true]);
        self::assertNotNull($ref);
        self::assertFalse($ref->isTypeParam);
        self::assertSame(self::BOX, $ref->name);
        self::assertSame('App\\Box<T>', $ref->canonical());
    }

    public function testParamTypeRefOnConcreteNameWithoutResolvedFqnFallsBackToSpelling(): void
    {
        $ref = TypeInference::paramTypeRef(new Name('Whatever'), ['T' => true]);
        self::assertNotNull($ref);
        self::assertSame('Whatever', $ref->name);
        self::assertSame([], $ref->args);
    }

    public function testParamTypeRefReturnsNullForUnmodeledShapes(): void
    {
        self::assertNull(TypeInference::paramTypeRef(null, ['T' => true]));
        self::assertNull(TypeInference::paramTypeRef(
            new UnionType([new Name('T'), new Identifier('int')]),
            ['T' => true],
        ));
    }

    // ---- helpers ------------------------------------------------------------------------------

    private function infer(?TypeHierarchy $hierarchy = null): TypeInference
    {
        return new TypeInference($hierarchy ?? new TypeHierarchy([]));
    }

    private function arrayListImplementsCollection(): TypeHierarchy
    {
        return new TypeHierarchy(
            [self::ARRAY_LIST => [self::COLLECTION]],
            [self::ARRAY_LIST => [new TypeRef(self::COLLECTION, [self::typeParamRef('E')])]],
            [self::ARRAY_LIST => ['E'], self::COLLECTION => ['E']],
        );
    }

    /** @param list<TypeRef>|null $result */
    private static function canonicals(?array $result): string
    {
        self::assertNotNull($result);
        return implode(',', array_map(static fn (TypeRef $r): string => $r->canonical(), $result));
    }

    private static function param(string $var, Node $type): Param
    {
        return new Param(new Variable($var), null, $type);
    }

    private static function variadicParam(string $var, Node $type): Param
    {
        return new Param(new Variable($var), null, $type, false, true);
    }

    private static function generic(string $shortResolved, array $args): Name
    {
        $name = new Name('Short');
        $name->setAttribute(XphpSourceParser::ATTR_GENERIC_ARGS, $args);
        $name->setAttribute(XphpSourceParser::ATTR_RESOLVED_FQN, $shortResolved);
        return $name;
    }

    private static function arg(string $var): Arg
    {
        return new Arg(new Variable($var));
    }

    private static function namedArg(string $paramName, string $var): Arg
    {
        return new Arg(new Variable($var), name: new Identifier($paramName));
    }

    /** @param array<string, TypeRef> $map variable name → its static type */
    private static function typer(array $map): ExpressionTyper
    {
        return new class ($map) implements ExpressionTyper {
            /** @param array<string, TypeRef> $map */
            public function __construct(private readonly array $map)
            {
            }

            public function typeOf(Expr $expr): ?TypeRef
            {
                if ($expr instanceof Variable && is_string($expr->name)) {
                    return $this->map[$expr->name] ?? null;
                }
                return null;
            }
        };
    }

    private static function int(): TypeRef
    {
        return new TypeRef('int', isScalar: true);
    }

    private static function string(): TypeRef
    {
        return new TypeRef('string', isScalar: true);
    }

    private static function typeParamRef(string $name): TypeRef
    {
        return new TypeRef($name, isTypeParam: true);
    }

    private static function box(TypeRef $inner): TypeRef
    {
        return new TypeRef(self::BOX, [$inner]);
    }
}
