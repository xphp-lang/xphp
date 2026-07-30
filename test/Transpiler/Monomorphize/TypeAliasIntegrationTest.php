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

    public function testAliasesAreVisibleAcrossFilesInTheSameBuild(): void
    {
        // Whole-program alias table: an alias declared in one file is usable in another (union and
        // plain-class bodies both).
        $dist = $this->compile([
            'Types.xphp' => "<?php\ndeclare(strict_types=1);\nnamespace App;\ntype Num = int|string;\ntype UserId = Ident;\nclass Ident {}\n",
            'Consumer.xphp' => "<?php\ndeclare(strict_types=1);\nnamespace App;\nclass Consumer {\n public function f(Num \$n): UserId { return new UserId(); }\n}\n",
        ]);
        $consumer = self::read($dist, 'Consumer.php');

        self::assertStringContainsString('function f(int|string $n): \\App\\Ident', $consumer);
    }

    public function testAMalformedAliasFileDoesNotCrashTheWholeProgramPrePass(): void
    {
        // The pre-pass that builds the whole-program table skips a file whose own aliases are
        // malformed (a duplicate) rather than crashing the build; `check` still collects that file's
        // diagnostic (raised for real when the file is parsed), and a valid alias elsewhere compiles.
        $files = [
            'Bad.xphp' => "<?php\ndeclare(strict_types=1);\nnamespace App;\ntype Dup = int;\ntype Dup = string;\nfunction bad(): int { return 1; }\n",
            'Good.xphp' => "<?php\ndeclare(strict_types=1);\nnamespace App;\ntype Num = int|string;\nfunction good(Num \$n): int { return 1; }\n",
        ];
        self::assertRejected($this->check($files), XphpSourceParser::CODE_ALIAS_DUPLICATE, 'declared more than once');
    }

    public function testAliasFileWithASyntaxErrorIsCollectedNotCrashed(): void
    {
        // The pre-pass re-parses an alias-bearing file to collect its aliases; a nikic SYNTAX error
        // there (a PhpParserError, not an xphp RuntimeException) must be caught/skipped too, so check
        // collects it for real rather than crashing the whole-program alias collection.
        $files = [
            'Broken.xphp' => "<?php\ndeclare(strict_types=1);\nnamespace App;\ntype Num = int;\nclass C { public function f(: int {} }\n",
        ];
        self::assertTrue($this->check($files)->hasErrors(), 'a syntax error in an alias file is collected, not crashed');
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

    public function testAliasArityMismatchIsRejectedInBothModes(): void
    {
        $files = [
            'C.xphp' => "<?php\ndeclare(strict_types=1);\nnamespace App;\ntype P<A, B> = Dict<A, B>;\nclass Dict<K, V> { public function __construct(public K \$k, public V \$v) {} }\nfunction f(): P<int> { return new Dict::<int, int>(1, 2); }\n",
        ];
        self::assertRejected($this->check($files), XphpSourceParser::CODE_ALIAS_ARITY, 'expects 2 type argument(s), 1 given');
        $this->assertCompileThrows($files, 'expects 2 type argument(s), 1 given');
    }

    public function testUnsupportedAliasBodyIsRejectedInBothModes(): void
    {
        // An intersection (and DNF / closure) body is recognized (stripped) but rejected with a clear
        // diagnostic — not a raw PHP parse error. (Union and nullable bodies ARE supported — see the
        // union tests.) The full message is asserted so a reworded or truncated diagnostic is caught.
        $files = [
            'C.xphp' => "<?php\ndeclare(strict_types=1);\nnamespace App;\nclass A {} class B {}\ntype Both = A & B;\nfunction f(): Both { return new A(); }\n",
        ];
        $message = 'single class or generic type (unions, intersections, nullables, and closure '
            . 'signatures are not supported). Use a bare type';
        self::assertRejected($this->check($files), XphpSourceParser::CODE_ALIAS_UNSUPPORTED_BODY, $message);
        $this->assertCompileThrows($files, $message);
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
