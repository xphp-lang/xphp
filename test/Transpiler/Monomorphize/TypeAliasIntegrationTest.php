<?php

declare(strict_types=1);

namespace XPHP\Transpiler\Monomorphize;

use PhpParser\ParserFactory;
use PhpParser\PrettyPrinter\Standard as StandardPrinter;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use XPHP\Diagnostics\DiagnosticCollector;
use XPHP\FileSystem\FileFinder\NativeFileFinder;
use XPHP\FileSystem\FileReader\NativeFileReader;
use XPHP\FileSystem\FileWriter\NativeFileWriter;
use XPHP\TestSupport\CompiledFixture;

/**
 * End-to-end coverage for type aliases (`type Name[<A, B>] = SingleHead;`, WI-01): a declared alias
 * is a compile-time substitution — it is expanded into its body before specialization and has no
 * runtime existence. Covers a generic alias, a non-generic (plain-class) alias, and a
 * concrete-instantiation alias that references another alias; that the emitted program runs; that the
 * alias name is absent from the output; and that a cyclic or arity-mismatched alias fails loudly.
 */
final class TypeAliasIntegrationTest extends TestCase
{
    private string $work;

    protected function setUp(): void
    {
        $this->work = sys_get_temp_dir() . '/xphp-alias-' . uniqid('', true);
        mkdir($this->work, 0o755, true);
    }

    protected function tearDown(): void
    {
        self::rrmdir($this->work);
    }

    #[RunInSeparateProcess]
    public function testTypeAliasesExpandAndRunAtRuntime(): void
    {
        // The non-negotiable gate: execute the emitted output. That the program runs and returns the
        // right classes proves each alias expanded to its body and dispatched to real specializations.
        $fixture = CompiledFixture::compile(
            __DIR__ . '/../../fixture/compile/type_aliases/source',
            'aliases',
        );
        try {
            $fixture->registerAutoload('App\\Aliases');
            $runtime = require __DIR__ . '/../../fixture/compile/type_aliases/verify/runtime.php';
            $runtime($fixture);
        } finally {
            $fixture->cleanup();
        }
    }

    #[RunInSeparateProcess]
    public function testIntersectionAndDnfBodiesExpandAndRunAtRuntime(): void
    {
        // Execute the emitted output: an intersection body (`A&B`) and a DNF body (`(A&B)|C`) lower to
        // real PHP type nodes. That the program loads (a malformed node would fatal at class-load) and
        // the native type check accepts the conforming objects proves the emission is valid.
        $fixture = CompiledFixture::compile(
            __DIR__ . '/../../fixture/compile/intersection_alias/source',
            'inter',
        );
        try {
            $fixture->registerAutoload('App\\Inter');
            $runtime = require __DIR__ . '/../../fixture/compile/intersection_alias/verify/runtime.php';
            $runtime($fixture);
        } finally {
            $fixture->cleanup();
        }
    }

    public function testGenericAliasExpandsToItsBodySpecialization(): void
    {
        $use = self::read($this->compile([
            'Lib.xphp' => self::LIB,
            'Use.xphp' => "<?php\ndeclare(strict_types=1);\nnamespace App;\ntype Pair<A, B> = Dict<A, Bag<B>>;\nfunction f(): Pair<int, User> { return new Pair::<int, User>(1, new Bag::<User>(new User())); }\n",
        ]), 'Use.php');

        // Pair<int, User> → Dict<int, Bag<User>>: the emitted type is the Dict specialization…
        self::assertStringContainsString('Generated\\App\\Dict\\T_', $use);
        // …and the alias name is gone entirely (no `Pair`, no residual turbofish).
        self::assertStringNotContainsString('Pair', $use);
        self::assertStringNotContainsString('::<', $use);
    }

    public function testNonGenericAliasExpandsToItsTargetClass(): void
    {
        $use = self::read($this->compile([
            'Lib.xphp' => self::LIB,
            'Use.xphp' => "<?php\ndeclare(strict_types=1);\nnamespace App;\ntype UserId = Ident;\nfunction f(): UserId { return new UserId(); }\n",
        ]), 'Use.php');

        // UserId → the plain class Ident, resolved fully-qualified; the alias name is absent.
        self::assertStringContainsString('App\\Ident', $use);
        self::assertStringNotContainsString('UserId', $use);
    }

    public function testConcreteInstantiationAliasReferencingAnotherAliasExpandsFully(): void
    {
        $use = self::read($this->compile([
            'Lib.xphp' => self::LIB,
            'Use.xphp' => "<?php\ndeclare(strict_types=1);\nnamespace App;\ntype Pair<A, B> = Dict<A, Bag<B>>;\ntype UserMap = Pair<int, User>;\nfunction f(): UserMap { return new UserMap(1, new Bag::<User>(new User())); }\n",
        ]), 'Use.php');

        // UserMap → Pair<int, User> → Dict<int, Bag<User>>: fully expanded, no alias name remains.
        self::assertStringContainsString('Generated\\App\\Dict\\T_', $use);
        self::assertStringNotContainsString('UserMap', $use);
        self::assertStringNotContainsString('Pair', $use);
    }

    public function testAliasInGenericArgumentPositionExpands(): void
    {
        // An alias used as a generic ARGUMENT of a non-alias type (`Bag<Elem>`) must expand too —
        // expansion recurses into arguments, not just the head.
        $use = self::read($this->compile([
            'Lib.xphp' => self::LIB,
            'Use.xphp' => "<?php\ndeclare(strict_types=1);\nnamespace App;\ntype Elem = User;\nfunction m(): Bag<Elem> { return new Bag::<Elem>(new User()); }\n",
        ]), 'Use.php');

        // Bag<Elem> → Bag<User>: the Bag specialization holds User; no `Elem` remains.
        self::assertStringContainsString('Generated\\App\\Bag\\T_', $use);
        self::assertStringNotContainsString('Elem', $use);
    }

    public function testNonAliasTypeInAnAliasFileIsLeftUnchanged(): void
    {
        // The alias table is consulted for every type-position name, but a non-alias class type must
        // pass through byte-for-byte — expansion rebuilds a node only when something actually changed.
        $use = self::read($this->compile([
            'Lib.xphp' => self::LIB,
            'Use.xphp' => "<?php\ndeclare(strict_types=1);\nnamespace App;\ntype UserId = Ident;\nfunction k(User \$u): Bag<User> { return new Bag::<User>(\$u); }\n",
        ]), 'Use.php');

        // `User` (a real class, not an alias) is emitted exactly as written — not rewritten/qualified.
        self::assertStringContainsString('function k(User $u)', $use);
    }

    public function testAliasIsKeyedByItsDeclaringNamespace(): void
    {
        // An alias declared in the SECOND namespace must key under that namespace — the byte-span
        // attribution must not fall through to an earlier namespace (the `&&` containment check).
        $out = self::read($this->compile([
            'Multi.xphp' => "<?php\ndeclare(strict_types=1);\n"
                . "namespace A { class Thing {} }\n"
                . "namespace B { class Other {} type Ref = Other; function g(): Ref { return new Ref(); } }\n",
        ]), 'Multi.php');

        // Ref (declared in B) expands to B\Other; the alias name is gone.
        self::assertStringContainsString('B\\Other', $out);
        self::assertStringNotContainsString('Ref', $out);
    }

    public function testAliasBodyResolutionDoesNotLeakTypeParameters(): void
    {
        // Resolving a generic alias's body pushes its type parameters; they must be popped afterward,
        // so a later alias whose body references a same-named real class is not mis-resolved as a
        // (leaked) type parameter. `First<A>` is resolved first, then `Second = A` must resolve `A`
        // to the class \App\A, not to a leaked type parameter.
        $use = self::read($this->compile([
            'Lib.xphp' => self::LIB,
            'Use.xphp' => "<?php\ndeclare(strict_types=1);\nnamespace App;\nclass A {}\ntype First<A> = Bag<A>;\ntype Second = A;\nfunction useFirst(): First<int> { return new Bag::<int>(1); }\nfunction useSecond(): Second { return new A(); }\n",
        ]), 'Use.php');

        // Second → A resolves to the class \App\A (a leaked type param would emit a bare `\A`).
        self::assertStringContainsString('App\\A', $use);
    }

    public function testAliasInGlobalNamespaceBlock(): void
    {
        // A `namespace { ... }` block has no name; the alias keys under the global namespace.
        $out = self::read($this->compile([
            'G.xphp' => "<?php\ndeclare(strict_types=1);\nnamespace { class Ident {} type UserId = Ident; function f(): UserId { return new UserId(); } }\n",
        ]), 'G.php');

        self::assertStringNotContainsString('UserId', $out);
        self::assertStringContainsString('Ident', $out);
    }

    public function testUnionAndNullableBodiesExpandInWholeSlots(): void
    {
        // A union body expands into a param/property/return/class-const slot as a real `int|string`;
        // a nullable body as `?\App\Ident`; a three-member union incl. null stays a `UnionType` (not
        // `?int`); and a single-head alias transitively resolving to a union expands too.
        $use = self::read($this->compile([
            'Lib.xphp' => self::LIB,
            'Use.xphp' => "<?php\ndeclare(strict_types=1);\nnamespace App;\ntype Num = int|string;\ntype MaybeIdent = ?Ident;\ntype Tri = int|string|null;\ntype Aliased = Num;\nclass Svc {\n public Num \$a;\n const Num LIMIT = 1;\n public function f(Num \$n): MaybeIdent { return null; }\n public function g(Aliased \$x): Tri { return \$x; }\n}\n",
        ]), 'Use.php');

        self::assertStringContainsString('public int|string $a', $use);
        self::assertStringContainsString('const int|string LIMIT', $use);
        self::assertStringContainsString('function f(int|string $n): ?\\App\\Ident', $use);
        self::assertStringContainsString('function g(int|string $x): int|string|null', $use);
    }

    public function testIntersectionAndDnfBodiesExpandInWholeSlots(): void
    {
        // An intersection body expands into a slot as a real `A&B`; a DNF body as `(A&B)|C`; and a
        // nested intersection alias flattens by `&`-associativity into `A&B&C` — all as the WHOLE type
        // of a param / property / return slot.
        $use = self::read($this->compile([
            'Use.xphp' => "<?php\ndeclare(strict_types=1);\nnamespace App;\ninterface A {} interface B {} interface C {}\ntype Both = A & B;\ntype Dnf = (A & B) | C;\ntype Inner = A & B;\ntype Outer = Inner & C;\ntype FqBoth = A & \\DateTimeInterface;\nclass Svc {\n public Both \$b;\n public FqBoth \$fq;\n public function f(Dnf \$d): Outer { return \$d; }\n}\n",
        ]), 'Use.php');

        self::assertStringContainsString('public \\App\\A&\\App\\B $b', $use);
        self::assertStringContainsString('function f((\\App\\A&\\App\\B)|\\App\\C $d): \\App\\A&\\App\\B&\\App\\C', $use);
        // A fully-qualified member keeps its leading `\` (global `\DateTimeInterface`, NOT the relative
        // `\App\DateTimeInterface`), so the fq encoding of an intersection leaf is exercised.
        self::assertStringContainsString('public \\App\\A&\\DateTimeInterface $fq', $use);
    }

    public function testCompoundNeedsDistributionIsRejectedInBothModes(): void
    {
        // A union nested inside an intersection would require distribution ((A|B)&C → (A&C)|(B&C)),
        // which the alias machinery rejects loudly rather than distribute — both when written directly
        // and when a union alias lands inside an intersection at expansion. Never a silent miscompile.
        // The full messages are asserted (each differs) so a reworded / reordered diagnostic is caught.
        $header = "<?php\ndeclare(strict_types=1);\nnamespace App;\ninterface A {} interface B {} interface C {}\n";

        $directly = ['C.xphp' => $header . "type Bad = (A | B) & C;\nfunction f(Bad \$x): int { return 1; }\n"];
        $directlyMessage = 'has a body that would require distribution: a union nested inside an '
            . 'intersection (`(A|B)&C`) is not supported. Rewrite it in disjunctive normal form '
            . '(`(A&C)|(B&C)`), or introduce a named type for the union.';
        self::assertRejected($this->check($directly), XphpSourceParser::CODE_ALIAS_COMPOUND_NEEDS_DISTRIBUTION, $directlyMessage);
        $this->assertCompileThrows($directly, $directlyMessage);

        $expansion = ['C.xphp' => $header . "type U = A | B;\ntype Bad = U & C;\nfunction f(Bad \$x): int { return 1; }\n"];
        $expansionMessage = 'has a body that would require distribution: a member of an intersection '
            . 'expands to a union. Rewrite it in disjunctive normal form, or introduce a named type '
            . 'for the union.';
        self::assertRejected($this->check($expansion), XphpSourceParser::CODE_ALIAS_COMPOUND_NEEDS_DISTRIBUTION, $expansionMessage);
        $this->assertCompileThrows($expansion, $expansionMessage);
    }

    public function testScalarInIntersectionIsRejectedInBothModes(): void
    {
        // An intersection with a scalar / built-in member is a PHP load-time fatal (`int&B`), so it is
        // rejected — including when the scalar arrives only via a type-parameter substitution
        // (`Pair<int>`), which is why the check runs at emit time, after substitution.
        $header = "<?php\ndeclare(strict_types=1);\nnamespace App;\ninterface A {} interface Countable2 {}\n";
        foreach ([
            'literal-scalar' => "type Bad = int & A;\nfunction f(Bad \$x): int { return 1; }",
            'substituted-scalar' => "type Pair<T> = T & Countable2;\nfunction f(Pair<int> \$x): int { return 1; }",
        ] as $body) {
            $files = ['C.xphp' => $header . $body . "\n"];
            $message = 'is a scalar or built-in type; only class-like types can be intersected.';
            self::assertRejected($this->check($files), XphpSourceParser::CODE_ALIAS_SCALAR_IN_INTERSECTION, $message);
            $this->assertCompileThrows($files, $message);
        }
    }

    public function testIntersectionAliasBoundIsAllOf(): void
    {
        // An intersection alias used as a type-parameter bound is all-of (`T : A&B`): an argument
        // implementing both members is accepted, one implementing only a single member is rejected —
        // not flattened to an any-of union bound (which would unsoundly accept the single-member type).
        $header = "<?php\ndeclare(strict_types=1);\nnamespace App;\ninterface A {} interface B {}\ntype Both = A & B;\nclass Box<T : Both> { public function __construct(public T \$v) {} }\nclass AB implements A, B {}\nclass OnlyA implements A {}\n";
        $ok = ['C.xphp' => $header . "function f(): int { \$b = new Box::<AB>(new AB()); return 1; }\n"];
        self::assertFalse($this->check($ok)->hasErrors(), 'an argument implementing every member of the intersection bound compiles');

        $bad = ['C.xphp' => $header . "function f(): int { \$b = new Box::<OnlyA>(new OnlyA()); return 1; }\n"];
        self::assertRejected($this->check($bad), 'xphp.bound_violation', 'does not satisfy');
        $this->assertCompileThrowsRuntime($bad, 'does not satisfy');
    }

    public function testCompoundAliasInNonSlotPositionsAreRejectedInBothModes(): void
    {
        // A union alias is representable only as the WHOLE type of a param / property / return /
        // class-const slot; as a generic argument, in a `new`, in `extends`, or nested inside another
        // nullable/union at the use site it rejects loudly (in both modes).
        $needle = 'the whole type of a parameter, property, return, or class-constant slot';
        foreach ([
            'generic-arg' => "class Bag<T> {}\ntype Num = int|string;\nfunction f(): Bag<Num> { return new Bag::<Num>(); }",
            'new' => "type Num = int|string;\nfunction f(): int { \$x = new Num(); return 1; }",
            'extends' => "type Num = int|string;\nclass C extends Num {}",
            'nested-in-nullable' => "type Num = int|string;\nfunction f(?Num \$x): int { return 1; }",
            'nested-in-union' => "class Extra {}\ntype Num = int|string;\nfunction f(Num|Extra \$x): int { return 1; }",
            'intersection-generic-arg' => "class Bag<T> {}\ninterface A {} interface B {}\ntype Both = A & B;\nfunction f(Bag<Both> \$x): int { return 1; }",
            'intersection-nested' => "interface A {} interface B {} class Extra {}\ntype Both = A & B;\nfunction f(Both|Extra \$x): int { return 1; }",
        ] as $body) {
            $files = ['C.xphp' => "<?php\ndeclare(strict_types=1);\nnamespace App;\n{$body}\n"];
            self::assertRejected($this->check($files), XphpSourceParser::CODE_ALIAS_COMPOUND_IN_NON_SLOT, $needle);
            $this->assertCompileThrows($files, $needle);
        }
    }

    public function testNullableFollowedByUnionIsDeclinedAsUnsupported(): void
    {
        // `?A|B` is illegal PHP (`?` cannot precede a union); the body is declined (not mis-read as
        // `?A`), so the declaration is an unsupported-body error rather than a wrong acceptance.
        $files = ['C.xphp' => "<?php\ndeclare(strict_types=1);\nnamespace App;\nclass A {} class B {}\ntype Bad = ?A|B;\nfunction f(): Bad { return new A(); }\n"];
        self::assertRejected($this->check($files), XphpSourceParser::CODE_ALIAS_UNSUPPORTED_BODY, 'unsupported body');
        $this->assertCompileThrows($files, 'unsupported body');
    }

    public function testAnAliasIsFileLocalAndNotVisibleInAnotherFile(): void
    {
        // A type alias is file-local, like a `use` alias — an alias declared in one file is NOT
        // visible in another. A non-generic use is left unexpanded (a bare reference the later
        // PHP/PHPStan pass would flag); a generic use surfaces loudly as an undefined template.
        $nonGeneric = $this->compile([
            'Types.xphp' => "<?php\ndeclare(strict_types=1);\nnamespace App;\nclass Ident {}\ntype UserId = Ident;\n",
            'Consumer.xphp' => "<?php\ndeclare(strict_types=1);\nnamespace App;\nfunction f(): UserId { return new Ident(); }\n",
        ]);
        // `UserId` is not an alias in Consumer.xphp, so it is left as-is, NOT expanded to `\App\Ident`.
        $consumer = self::read($nonGeneric, 'Consumer.php');
        self::assertStringContainsString('function f(): UserId', $consumer);
        self::assertStringNotContainsString('App\\Ident', $consumer);

        $generic = [
            'Types.xphp' => "<?php\ndeclare(strict_types=1);\nnamespace App;\nclass Dict<K, V> { public function __construct(public K \$k, public V \$v) {} }\ntype Pair<A, B> = Dict<A, B>;\n",
            'Consumer.xphp' => "<?php\ndeclare(strict_types=1);\nnamespace App;\nfunction f(): Pair<int, string> { return new Dict::<int, string>(1, 'x'); }\n",
        ];
        self::assertRejected($this->check($generic), 'xphp.undefined_template', 'App\\Pair');
    }

    public function testRedeclaringAnAliasPerFileSharesItAsFileLocal(): void
    {
        // The share-a-vocabulary pattern under file-local scoping: declare the alias in each file that
        // uses it (a zero-cost substitution). The target class is a normal cross-file class reference.
        $dist = $this->compile([
            'Types.xphp' => "<?php\ndeclare(strict_types=1);\nnamespace App;\nclass Ident {}\ntype UserId = Ident;\n",
            'Consumer.xphp' => "<?php\ndeclare(strict_types=1);\nnamespace App;\ntype UserId = Ident;\nfunction f(): UserId { return new UserId(); }\n",
        ]);

        // Consumer.xphp's own `type UserId = Ident;` expands here to the shared class \App\Ident.
        self::assertStringContainsString('function f(): \\App\\Ident', self::read($dist, 'Consumer.php'));
    }

    public function testCyclicAliasIsRejectedInBothModes(): void
    {
        // A directly-or-transitively self-referential alias would expand without bound; it is
        // rejected loudly — `check` collects the diagnostic (never a silent pass), `compile` throws.
        $files = [
            'C.xphp' => "<?php\ndeclare(strict_types=1);\nnamespace App;\ntype A<T> = B<T>;\ntype B<T> = A<T>;\nclass Box<T> { public function __construct(public T \$v) {} }\nfunction f(): A<int> { return new Box::<int>(1); }\n",
        ];
        self::assertRejected($this->check($files), XphpSourceParser::CODE_ALIAS_CYCLE, 'in terms of itself');
        $this->assertCompileThrows($files, 'in terms of itself');
    }

    public function testCycleThroughAGenericArgumentIsRejectedNotACrash(): void
    {
        // The cycle passes through the generic ARGUMENT of a non-alias class (`Bag<A<T>>`), not the
        // head. Argument expansion must carry the same visited chain as the body, so this is a clean
        // xphp.alias_cycle in both modes rather than unbounded recursion / a stack overflow.
        $files = [
            'C.xphp' => "<?php\ndeclare(strict_types=1);\nnamespace App;\nclass Bag<T> { public function __construct(public T \$i) {} }\ntype A<T> = Bag<A<T>>;\nfunction f(): A<int> { throw new \\Exception(); }\n",
        ];
        self::assertRejected($this->check($files), XphpSourceParser::CODE_ALIAS_CYCLE, 'in terms of itself');
        $this->assertCompileThrows($files, 'in terms of itself');
    }

    public function testAliasArityMismatchIsRejectedInBothModes(): void
    {
        $files = [
            'C.xphp' => "<?php\ndeclare(strict_types=1);\nnamespace App;\ntype P<A, B> = Dict<A, B>;\nclass Dict<K, V> { public function __construct(public K \$k, public V \$v) {} }\nfunction f(): P<int> { return new Dict::<int, int>(1, 2); }\n",
        ];
        self::assertRejected($this->check($files), XphpSourceParser::CODE_ALIAS_ARITY, 'expects 2 type argument(s), 1 given');
        $this->assertCompileThrows($files, 'expects 2 type argument(s), 1 given');
    }

    public function testAliasParameterDefaultReferencingAnEarlierParameterIsFilled(): void
    {
        // `type P<A, B = A>` used as `P<int>` fills the omitted B with A (= int), so it specializes to
        // the SAME Dict as the explicit `P<int, int>`, and NOT the same as `P<int, string>`. The
        // specialization hash is non-deterministic, but equality between two emitted FQNs is exact.
        $use = self::read($this->compile([
            'Lib.xphp' => self::LIB,
            'Use.xphp' => "<?php\ndeclare(strict_types=1);\nnamespace App;\ntype P<A, B = A> = Dict<A, B>;\nclass C {\n public function omitted(): P<int> { return new Dict::<int, int>(1, 2); }\n public function explicitSame(): P<int, int> { return new Dict::<int, int>(1, 2); }\n public function explicitDiff(): P<int, string> { return new Dict::<int, string>(1, 'x'); }\n}\n",
        ]), 'Use.php');

        self::assertSame(self::specFqn($use, 'explicitSame'), self::specFqn($use, 'omitted'), 'P<int> fills B = A = int, matching P<int, int>');
        self::assertNotSame(self::specFqn($use, 'explicitDiff'), self::specFqn($use, 'omitted'), 'P<int> is not P<int, string>');
    }

    public function testAliasParameterConcreteDefaultIsFilled(): void
    {
        // A concrete (non-param-referencing) default: `type Q<A, B = string>` used as `Q<int>` fills B
        // with string, matching the explicit `Q<int, string>` and differing from `Q<int, int>`.
        $use = self::read($this->compile([
            'Lib.xphp' => self::LIB,
            'Use.xphp' => "<?php\ndeclare(strict_types=1);\nnamespace App;\ntype Q<A, B = string> = Dict<A, B>;\nclass C {\n public function omitted(): Q<int> { return new Dict::<int, string>(1, 'x'); }\n public function explicitSame(): Q<int, string> { return new Dict::<int, string>(1, 'x'); }\n public function explicitDiff(): Q<int, int> { return new Dict::<int, int>(1, 2); }\n}\n",
        ]), 'Use.php');

        self::assertSame(self::specFqn($use, 'explicitSame'), self::specFqn($use, 'omitted'), 'Q<int> fills B = string, matching Q<int, string>');
        self::assertNotSame(self::specFqn($use, 'explicitDiff'), self::specFqn($use, 'omitted'), 'Q<int> is not Q<int, int>');
    }

    public function testAliasDefaultChainFillsTransitively(): void
    {
        // A default may reference an earlier param that is itself defaulted: `P<A, B = A, C = B>` used
        // as `P<int>` fills B = A = int, then C = B = int — the same specialization as `P<int, int, int>`.
        $use = self::read($this->compile([
            'Lib.xphp' => self::LIB,
            'Use.xphp' => "<?php\ndeclare(strict_types=1);\nnamespace App;\nclass Trip<X, Y, Z> { public function __construct(public X \$x, public Y \$y, public Z \$z) {} }\ntype P<A, B = A, C = B> = Trip<A, B, C>;\nclass C {\n public function omitted(): P<int> { return new Trip::<int, int, int>(1, 2, 3); }\n public function explicitFull(): P<int, int, int> { return new Trip::<int, int, int>(1, 2, 3); }\n}\n",
        ]), 'Use.php');

        self::assertSame(self::specFqn($use, 'explicitFull'), self::specFqn($use, 'omitted'), 'P<int> fills B = A = int then C = B = int');
    }

    public function testAliasArityRangeMessageAppearsOnlyWithDefaults(): void
    {
        // With a default present the valid arity is a RANGE (required..total); too many args reports
        // the "between R and N" form. (A no-default alias keeps the exact "expects N" form — pinned by
        // testAliasArityMismatchIsRejectedInBothModes.)
        $files = [
            'C.xphp' => "<?php\ndeclare(strict_types=1);\nnamespace App;\ntype P<A, B = A> = Dict<A, B>;\nclass Dict<K, V> { public function __construct(public K \$k, public V \$v) {} }\nfunction f(): P<int, int, int> { return new Dict::<int, int>(1, 2); }\n",
        ];
        self::assertRejected($this->check($files), XphpSourceParser::CODE_ALIAS_ARITY, 'expects between 1 and 2 type argument(s), 3 given');
        $this->assertCompileThrows($files, 'expects between 1 and 2 type argument(s), 3 given');
    }

    public function testAliasParameterBoundViolationIsRejectedInBothModes(): void
    {
        // A ground argument that does not satisfy an alias parameter's bound is a loud error, routed
        // through the SAME check a class instantiation uses — an identical `xphp.bound_violation` with
        // the "type alias" label. `check` collects it; `compile` throws (a RuntimeException, not the
        // parse exception, since the check runs post-hierarchy).
        $files = [
            'C.xphp' => "<?php\ndeclare(strict_types=1);\nnamespace App;\ninterface Named {}\nclass Bag<T> { public function __construct(public T \$i) {} }\ntype B<T : Named> = Bag<T>;\nfunction f(): B<int> { return new Bag::<int>(1); }\n",
        ];
        $collector = $this->check($files);
        self::assertRejected($collector, Registry::CODE_BOUND_VIOLATION, 'Generic bound violated while instantiating type alias `App\\B`');
        // The diagnostic points at the USE-site file — a captured obligation carries a real location.
        $violations = array_values(array_filter($collector->all(), static fn ($d): bool => $d->code === Registry::CODE_BOUND_VIOLATION));
        self::assertStringEndsWith('C.xphp', $violations[0]->location?->file ?? '');
        $this->assertCompileThrowsRuntime($files, '"int" does not extend/implement "App\\Named"');
    }

    public function testAliasParameterBoundSatisfiedByAGroundArgumentCompiles(): void
    {
        // A ground argument that satisfies the bound compiles cleanly — no false positive.
        $use = self::read($this->compile([
            'C.xphp' => "<?php\ndeclare(strict_types=1);\nnamespace App;\ninterface Named {}\nclass Widget implements Named {}\nclass Bag<T> { public function __construct(public T \$i) {} }\ntype B<T : Named> = Bag<T>;\nclass C { public function f(): B<Widget> { return new Bag::<Widget>(new Widget()); } }\n",
        ]), 'C.php');

        self::assertStringContainsString('\\XPHP\\Generated\\App\\Bag\\', self::specFqn($use, 'f'));
    }

    public function testAliasParameterBoundOnATopLevelTypeParameterArgumentIsSkipped(): void
    {
        // `B<X>` inside `class G<X>`: the argument's top level is a type parameter, absent from the
        // hierarchy, so checking it would spuriously reject. It is skipped (the alias erases to
        // `Bag<X>`, whose own bounds — none here — still apply when G specializes). No false positive.
        $dist = $this->compile([
            'C.xphp' => "<?php\ndeclare(strict_types=1);\nnamespace App;\ninterface Named {}\nclass Bag<T> { public function __construct(public T \$i) {} }\ntype B<T : Named> = Bag<T>;\nclass G<X> { public function __construct(public X \$x) {} public function f(): B<X> { return new Bag::<X>(\$this->x); } }\nclass H { public function make(): G<int> { return new G::<int>(1); } }\n",
        ]);

        self::assertStringContainsString('class', self::read($dist, 'C.php'));
    }

    public function testAliasParameterBoundChecksAConcreteHeadOverATypeParameterInnerArgument(): void
    {
        // `B<Coll<X>>` inside `class G<X>`: the argument's TOP LEVEL is the concrete `Coll` (not a type
        // parameter), so it is checked even though its inner arg is a type parameter — bounds erase
        // generic arguments. `Coll` does not implement `Named`, so this is a violation, not a skip.
        $files = [
            'C.xphp' => "<?php\ndeclare(strict_types=1);\nnamespace App;\ninterface Named {}\nclass Coll<E> {}\nclass Bag<T> { public function __construct(public T \$i) {} }\ntype B<T : Named> = Bag<T>;\nclass G<X> { public function f(): B<Coll<X>> { throw new \\Exception(); } }\nclass H { public function make(): G<int> { return new G::<int>(); } }\n",
        ];
        self::assertRejected($this->check($files), Registry::CODE_BOUND_VIOLATION, 'does not extend/implement "App\\Named"');
    }

    public function testAliasParameterBoundWithAnUnknownGroundClassIsRejected(): void
    {
        // A ground class the hierarchy was not built from (e.g. a vendor class) is an UNKNOWN verdict,
        // which — exactly as for class generics — is rejected (the compiler cannot prove the bound), so
        // no knowably-unprovable specialization is emitted silently.
        $files = [
            'C.xphp' => "<?php\ndeclare(strict_types=1);\nnamespace App;\ninterface Named {}\nclass Bag<T> { public function __construct(public T \$i) {} }\ntype B<T : Named> = Bag<T>;\nfunction f(): B<\\DateTime> { return new Bag::<\\DateTime>(new \\DateTime()); }\n",
        ];
        self::assertRejected($this->check($files), Registry::CODE_BOUND_VIOLATION, 'is not in the source set the hierarchy was built from');
    }

    public function testAliasParameterSiblingReferencingBoundIsGroundedAndChecked(): void
    {
        // A bound referencing an earlier sibling parameter (`<A, T : A>`) is grounded against the
        // supplied args before checking — `Pair<Named, int>` fails (int is not Named) while
        // `Pair<Named, Widget>` passes (Widget implements Named).
        $bad = [
            'C.xphp' => "<?php\ndeclare(strict_types=1);\nnamespace App;\ninterface Named {}\nclass Two<A, B> {}\ntype Pair<A, T : A> = Two<A, T>;\nfunction f(): Pair<Named, int> { throw new \\Exception(); }\n",
        ];
        self::assertRejected($this->check($bad), Registry::CODE_BOUND_VIOLATION, 'does not extend/implement "App\\Named"');

        $good = self::read($this->compile([
            'C.xphp' => "<?php\ndeclare(strict_types=1);\nnamespace App;\ninterface Named {}\nclass Widget implements Named {}\nclass Two<A, B> {}\ntype Pair<A, T : A> = Two<A, T>;\nclass C { public function f(): Pair<Named, Widget> { throw new \\Exception(); } }\n",
        ]), 'C.php');
        self::assertStringContainsString('\\XPHP\\Generated\\App\\Two\\', self::specFqn($good, 'f'));
    }

    public function testAliasParameterBoundIsReportedPerGroundUseSite(): void
    {
        // Obligations are per use site (no FQN de-dup like the Registry): two ground violating uses of
        // the same bounded alias yield two collected diagnostics in check mode.
        $files = [
            'C.xphp' => "<?php\ndeclare(strict_types=1);\nnamespace App;\ninterface Named {}\nclass Bag<T> { public function __construct(public T \$i) {} }\ntype B<T : Named> = Bag<T>;\nfunction f(): B<int> { return new Bag::<int>(1); }\nfunction g(): B<string> { return new Bag::<string>('x'); }\n",
        ];
        $violations = array_filter($this->check($files)->all(), static fn ($d): bool => $d->code === Registry::CODE_BOUND_VIOLATION);
        self::assertCount(2, $violations);
    }

    public function testABadDefaultOnAnUnusedAliasIsNotChecked(): void
    {
        // Obligations are captured only where an alias is USED; an alias declared with a default that
        // would violate its own bound but never instantiated emits nothing (unlike a class template,
        // which is checked at declaration). Documented divergence, not a bug: an unused alias is inert.
        $files = [
            'C.xphp' => "<?php\ndeclare(strict_types=1);\nnamespace App;\ninterface Named {}\nclass Bag<T> { public function __construct(public T \$i) {} }\ntype B<T : Named = int> = Bag<T>;\nclass C { public function unrelated(): int { return 1; } }\n",
        ];
        // No diagnostic of any kind — in particular no bound violation for the (never instantiated)
        // bad default — and the unrelated code still compiles.
        self::assertFalse($this->check($files)->hasErrors(), 'an unused alias with a bad default is inert');
        self::assertStringContainsString('function unrelated(): int', self::read($this->compile($files), 'C.php'));
    }

    public function testAValidBoundedUseIsNotFalselyReportedWhenTheSameFileAbortsParsing(): void
    {
        // A file that aborts mid-parse (here on an arity error) is dropped from the hierarchy. A VALID
        // bounded-alias use earlier in the same file must NOT then be checked against that missing
        // hierarchy — its obligation is discarded with the file, so no spurious bound violation for a
        // type ("not in the source set") that is in fact declared right there.
        $files = [
            'A.xphp' => "<?php\ndeclare(strict_types=1);\nnamespace App;\ninterface Named {}\nclass Widget implements Named {}\nclass Bag<T> { public function __construct(public T \$i) {} }\nclass Dict<K, V> { public function __construct(public K \$k, public V \$v) {} }\ntype B<T : Named> = Bag<T>;\ntype P<A, B> = Dict<A, B>;\nfunction ok(): B<Widget> { return new Bag::<Widget>(new Widget()); }\nfunction bad(): P<int> { return new Dict::<int, int>(1, 2); }\n",
        ];
        $codes = array_map(static fn ($d): string => $d->code, $this->check($files)->all());
        self::assertContains(XphpSourceParser::CODE_ALIAS_ARITY, $codes, 'the real arity error is still reported');
        self::assertNotContains(Registry::CODE_BOUND_VIOLATION, $codes, 'the valid bounded use must not be falsely flagged');
    }

    public function testAnAliasUsedAsAParameterBoundIsExpanded(): void
    {
        // `type Named = Face` used as a bound must check against Face, not a phantom `App\Named`. A
        // satisfying argument compiles; a violating one is rejected against the REAL type.
        $ok = [
            'C.xphp' => "<?php\ndeclare(strict_types=1);\nnamespace App;\ninterface Face {}\nclass Widget implements Face {}\nclass Bag<T> { public function __construct(public T \$i) {} }\ntype Named = Face;\ntype B<T : Named> = Bag<T>;\nfunction f(): B<Widget> { return new Bag::<Widget>(new Widget()); }\n",
        ];
        self::assertFalse($this->check($ok)->hasErrors(), 'a class satisfying the aliased bound compiles');

        $bad = [
            'C.xphp' => "<?php\ndeclare(strict_types=1);\nnamespace App;\ninterface Face {}\nclass Plain {}\nclass Bag<T> { public function __construct(public T \$i) {} }\ntype Named = Face;\ntype B<T : Named> = Bag<T>;\nfunction f(): B<Plain> { return new Bag::<Plain>(new Plain()); }\n",
        ];
        $collector = $this->check($bad);
        self::assertRejected($collector, Registry::CODE_BOUND_VIOLATION, 'does not extend/implement "App\\Face"');
        $messages = implode("\n", array_map(static fn ($d): string => $d->message, $collector->all()));
        self::assertStringNotContainsString('App\\Named', $messages, 'the bound must name the expanded type, not the alias');
    }

    public function testAnAliasUsedAsAClassParameterBoundIsExpanded(): void
    {
        // The same expansion fixes the pre-existing class-parameter case (previously an
        // xphp.undeclared_type on the phantom alias name): a satisfying arg compiles, a violating one
        // is rejected against the real type.
        $ok = [
            'C.xphp' => "<?php\ndeclare(strict_types=1);\nnamespace App;\ninterface Face {}\nclass Widget implements Face {}\ntype Named = Face;\nclass Box<T : Named> { public function __construct(public T \$i) {} }\nfunction f(): Box<Widget> { return new Box::<Widget>(new Widget()); }\n",
        ];
        self::assertFalse($this->check($ok)->hasErrors(), 'a class satisfying the aliased class bound compiles');

        $bad = [
            'C.xphp' => "<?php\ndeclare(strict_types=1);\nnamespace App;\ninterface Face {}\nclass Plain {}\ntype Named = Face;\nclass Box<T : Named> { public function __construct(public T \$i) {} }\nfunction f(): Box<Plain> { return new Box::<Plain>(new Plain()); }\n",
        ];
        self::assertRejected($this->check($bad), Registry::CODE_BOUND_VIOLATION, 'does not extend/implement "App\\Face"');
    }

    public function testAMethodGenericAliasBoundIsExpanded(): void
    {
        // A generic METHOD's parameter bound also routes through the fix (the GenericMethodCompiler
        // path) — an aliased bound satisfied by the argument compiles.
        $ok = [
            'C.xphp' => "<?php\ndeclare(strict_types=1);\nnamespace App;\ninterface Face {}\nclass Widget implements Face {}\ntype Named = Face;\nclass C {\n public function m<T : Named>(T \$x): T { return \$x; }\n public function call(): void { \$this->m::<Widget>(new Widget()); }\n}\n",
        ];
        self::assertFalse($this->check($ok)->hasErrors(), 'a method-generic aliased bound satisfied by the argument compiles');
    }

    public function testAUnionAliasUsedAsABoundIsAnyOf(): void
    {
        // A union alias `type Either = X|Y` as a bound means "arg is X or Y" (BoundUnion any-of): an
        // argument implementing either passes; one implementing neither is rejected against the union.
        $ok = [
            'C.xphp' => "<?php\ndeclare(strict_types=1);\nnamespace App;\ninterface X {}\ninterface Y {}\nclass AX implements X {}\nclass Bag<T> { public function __construct(public T \$i) {} }\ntype Either = X|Y;\ntype B<T : Either> = Bag<T>;\nfunction f(): B<AX> { return new Bag::<AX>(new AX()); }\n",
        ];
        self::assertFalse($this->check($ok)->hasErrors(), 'an argument implementing one member of the union bound compiles');

        $bad = [
            'C.xphp' => "<?php\ndeclare(strict_types=1);\nnamespace App;\ninterface X {}\ninterface Y {}\nclass Neither {}\nclass Bag<T> { public function __construct(public T \$i) {} }\ntype Either = X|Y;\ntype B<T : Either> = Bag<T>;\nfunction f(): B<Neither> { return new Bag::<Neither>(new Neither()); }\n",
        ];
        self::assertRejected($this->check($bad), Registry::CODE_BOUND_VIOLATION, 'does not satisfy "App\\X | App\\Y"');
    }

    public function testAnAliasToAliasBoundResolvesTransitively(): void
    {
        // A bound naming an alias whose body is itself an alias resolves through to the real type.
        $ok = [
            'C.xphp' => "<?php\ndeclare(strict_types=1);\nnamespace App;\ninterface Face {}\nclass Widget implements Face {}\nclass Bag<T> { public function __construct(public T \$i) {} }\ntype Named = Face;\ntype Alias = Named;\ntype B<T : Alias> = Bag<T>;\nfunction f(): B<Widget> { return new Bag::<Widget>(new Widget()); }\n",
        ];
        self::assertFalse($this->check($ok)->hasErrors(), 'an alias-to-alias bound resolves to the real type');
    }

    public function testASelfReferentialGenericAliasBoundIsRejectedAsACycleNotACrash(): void
    {
        // A generic alias whose own parameter bound refers back to itself would recurse without bound
        // through resolveAliasParams; the in-flight guard turns it into a clean xphp.alias_cycle in
        // both modes rather than a stack overflow.
        $files = [
            'C.xphp' => "<?php\ndeclare(strict_types=1);\nnamespace App;\nclass User {}\nclass Bag<T> { public function __construct(public T \$i) {} }\ntype A<T : A = User> = Bag<T>;\nfunction f(): A<User> { return new Bag::<User>(new User()); }\n",
        ];
        self::assertRejected($this->check($files), XphpSourceParser::CODE_ALIAS_CYCLE, 'in terms of itself');
        $this->assertCompileThrows($files, 'in terms of itself');
    }

    public function testUnsupportedAliasBodyIsRejectedInBothModes(): void
    {
        // A closure-signature body is recognized (stripped) but rejected with a clear diagnostic — not
        // a raw PHP parse error. (Single-head, union, nullable, intersection, and DNF bodies ARE
        // supported — see the union / intersection tests.) The full message is asserted so a reworded
        // or truncated diagnostic is caught.
        $message = 'single class or generic type, a union, an intersection, a nullable, or a DNF '
            . '(closure-signature bodies are not supported). Use a bare type';
        foreach ([
            // A closure signature — recognized and declined (not surfaced as a bound error).
            'closure' => 'type Handler = Closure(int): int;',
            // A body the bound-expression reader cannot read (leads with `|`) — declined, not crashed.
            'malformed' => 'type Bad = |A;',
        ] as $body) {
            $files = ['C.xphp' => "<?php\ndeclare(strict_types=1);\nnamespace App;\ninterface A {}\n{$body}\nfunction f(): int { return 1; }\n"];
            self::assertRejected($this->check($files), XphpSourceParser::CODE_ALIAS_UNSUPPORTED_BODY, $message);
            $this->assertCompileThrows($files, $message);
        }
    }

    public function testACyclicIntersectionAliasIsRejectedAsACycleNotAnOverflow(): void
    {
        // A cycle THROUGH an intersection member — `type X = Y & A; type Y = X & B` — must be caught by
        // the accumulated visited-set as an xphp.alias_cycle, not recurse without bound. This pins the
        // visited threading in the multi-leaf (intersection) expansion path.
        $files = [
            'C.xphp' => "<?php\ndeclare(strict_types=1);\nnamespace App;\ninterface A {} interface B {}\ntype X = Y & A;\ntype Y = X & B;\nfunction f(X \$v): int { return 1; }\n",
        ];
        self::assertRejected($this->check($files), XphpSourceParser::CODE_ALIAS_CYCLE, 'in terms of itself');
        $this->assertCompileThrows($files, 'in terms of itself');
    }

    public function testNoSpaceAliasBodyExpands(): void
    {
        // `type Id=Ident;` (no spaces around `=`) is a valid single-head alias, not an unsupported
        // body — the body-start scan must land on `Ident`, not the `;`.
        $use = self::read($this->compile([
            'Lib.xphp' => self::LIB,
            'Use.xphp' => "<?php\ndeclare(strict_types=1);\nnamespace App;\ntype Id=Ident;\nfunction f(): Id { return new Id(); }\n",
        ]), 'Use.php');

        // Id → \App\Ident in both the return type and the `new`; a mis-scanned body-start would
        // instead reject `type Id=Ident;` as an unsupported body and never reach here.
        self::assertStringContainsString('function f(): \\App\\Ident', $use);
        self::assertStringContainsString('return new \\App\\Ident()', $use);
    }

    public function testAliasCollidingWithAClassIsRejectedInBothModes(): void
    {
        // An alias FQN that collides with a class of the same name must be a loud error, never a
        // silent shadow. The colliding class is declared after another class (so detection can't rely
        // on only the first declaration), in both a namespaced and a global file.
        $namespaced = [
            'C.xphp' => "<?php\ndeclare(strict_types=1);\nnamespace App;\nclass Other {}\nclass Box {}\ntype Box = Ident;\nclass Ident {}\n",
        ];
        self::assertRejected($this->check($namespaced), XphpSourceParser::CODE_ALIAS_CLASS_COLLISION, 'collides with a class');
        $this->assertCompileThrows($namespaced, 'collides with a class');

        // Global namespace (no `namespace` statement): the top-level class-declaration branch.
        $global = [
            'G.xphp' => "<?php\nclass Other {}\nclass Box {}\ntype Box = Ident;\nclass Ident {}\n",
        ];
        self::assertRejected($this->check($global), XphpSourceParser::CODE_ALIAS_CLASS_COLLISION, 'collides with a class');
        $this->assertCompileThrows($global, 'collides with a class');
    }

    public function testDuplicateAliasIsRejectedInBothModes(): void
    {
        $files = [
            'C.xphp' => "<?php\ndeclare(strict_types=1);\nnamespace App;\ntype Id = Ident;\ntype Id = Other;\nclass Ident {}\nclass Other {}\n",
        ];
        self::assertRejected($this->check($files), XphpSourceParser::CODE_ALIAS_DUPLICATE, 'declared more than once');
        $this->assertCompileThrows($files, 'declared more than once');
    }

    private static function assertRejected(DiagnosticCollector $collector, string $code, string $needle): void
    {
        self::assertTrue($collector->hasErrors(), 'check must collect the alias rejection, not silently pass');
        $codes = array_map(static fn ($d): string => $d->code, $collector->all());
        self::assertContains($code, $codes, 'check must report the dedicated alias diagnostic code');
        $messages = array_map(static fn ($d): string => $d->message, $collector->all());
        self::assertStringContainsString($needle, implode("\n", $messages));
    }

    /** @param array<string, string> $files */
    private function assertCompileThrows(array $files, string $needle): void
    {
        try {
            $this->compile($files);
            self::fail('compile must reject the alias loudly');
        } catch (XphpParseException $e) {
            self::assertStringContainsString($needle, $e->getMessage());
        }
    }

    /**
     * Like {@see assertCompileThrows}, but for a violation raised AFTER parsing (an alias parameter
     * bound, checked once the hierarchy exists) — which throws a plain RuntimeException, not the parse
     * exception.
     *
     * @param array<string, string> $files
     */
    private function assertCompileThrowsRuntime(array $files, string $needle): void
    {
        try {
            $this->compile($files);
            self::fail('compile must reject the alias bound violation loudly');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString($needle, $e->getMessage());
        }
    }

    private const LIB = <<<'PHP'
    <?php
    declare(strict_types=1);
    namespace App;
    class Ident {}
    class User {}
    class Bag<T> { public function __construct(public T $item) {} public function get(): T { return $this->item; } }
    class Dict<K, V> { public function __construct(public K $key, public V $value) {} public function value(): V { return $this->value; } }
    PHP;

    // --- helpers (kept local, matching the other Monomorphize integration tests) ---------------

    /** @param array<string, string> $files */
    private function compile(array $files): string
    {
        $src = $this->writeSources($files);
        $dist = $src . '/dist';
        $this->newCompiler()->compile($this->sourcesIn($src), $src, $dist, $src . '/.xphp-cache');
        return $dist;
    }

    /** @param array<string, string> $files */
    private function check(array $files): DiagnosticCollector
    {
        $src = $this->writeSources($files);
        return $this->newCompiler()->check($this->sourcesIn($src));
    }

    /** @param array<string, string> $files */
    private function writeSources(array $files): string
    {
        $src = $this->work . '/' . uniqid('src', true);
        mkdir($src, 0o755, true);
        foreach ($files as $name => $contents) {
            file_put_contents($src . '/' . $name, $contents);
        }
        return $src;
    }

    private function sourcesIn(string $src): \XPHP\FileSystem\FilepathArray
    {
        return (new NativeFileFinder())->find($src)
            ->filter(static fn (string $f): bool => str_ends_with($f, '.xphp'));
    }

    private function newCompiler(): Compiler
    {
        $printer = new StandardPrinter();
        $writer = new NativeFileWriter();
        return new Compiler(
            new NativeFileReader(),
            $writer,
            new XphpSourceParser((new ParserFactory())->createForHostVersion()),
            new Specializer(),
            new SpecializedClassGenerator($printer, $writer),
            $printer,
        );
    }

    private static function read(string $dir, string $file): string
    {
        $path = $dir . '/' . $file;
        return is_file($path) ? (file_get_contents($path) ?: '') : '';
    }

    /**
     * The emitted specialization FQN (`\XPHP\Generated\…`) a given method returns. The hash is
     * non-deterministic, so callers compare two of these for equality rather than asserting a literal.
     */
    private static function specFqn(string $emitted, string $method): string
    {
        self::assertSame(
            1,
            preg_match('/function ' . preg_quote($method, '/') . '\(\): (\\\\XPHP\\\\Generated\\\\[^\s{]+)/', $emitted, $m),
            "method {$method}() specialization not found in emitted source",
        );

        return $m[1];
    }

    private static function rrmdir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            is_dir($path) ? self::rrmdir($path) : unlink($path);
        }
        rmdir($dir);
    }
}
