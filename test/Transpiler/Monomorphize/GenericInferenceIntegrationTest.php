<?php

declare(strict_types=1);

namespace XPHP\Transpiler\Monomorphize;

use PhpParser\ParserFactory;
use PhpParser\PrettyPrinter\Standard as StandardPrinter;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use XPHP\Diagnostics\Diagnostic;
use XPHP\Diagnostics\DiagnosticCollector;
use XPHP\FileSystem\FileFinder\NativeFileFinder;
use XPHP\FileSystem\FileReader\NativeFileReader;
use XPHP\FileSystem\FileWriter\NativeFileWriter;
use XPHP\TestSupport\CompiledFixture;

/**
 * End-to-end coverage for type-argument inference (the optional turbofish): a bare generic call
 * whose type arguments are determined by its ordinary arguments is inferred and dispatched exactly
 * as an explicit `::<>` turbofish would be. Covers free-function, static-method, and instance-method
 * calls; that inference records the same instantiation an explicit turbofish records; that check and
 * compile agree; and that a call whose arguments do NOT determine the type still falls back to the
 * `xphp.missing_type_argument` error rather than silently emitting a broken call.
 */
final class GenericInferenceIntegrationTest extends TestCase
{
    private string $work;

    protected function setUp(): void
    {
        $this->work = sys_get_temp_dir() . '/xphp-inference-' . uniqid('', true);
        mkdir($this->work, 0o755, true);
    }

    protected function tearDown(): void
    {
        self::rrmdir($this->work);
    }

    private const LIB = <<<'PHP'
    <?php
    declare(strict_types=1);
    namespace App;
    class Plastic {}
    function identity<T>(T $x): T { return $x; }
    final class Factory { public static function make<T>(T $x): T { return $x; } }
    final class Bag { public function put<U>(U $x): U { return $x; } }
    PHP;

    #[RunInSeparateProcess]
    public function testInferredCallArgumentsRunAtRuntime(): void
    {
        // The non-negotiable gate: execute the emitted output. Every call site is bare; that the
        // program runs and returns the right values proves the turbofish-less calls inferred their
        // type arguments and dispatched to real specializations.
        $fixture = CompiledFixture::compile(
            __DIR__ . '/../../fixture/compile/inferred_call_arguments/source',
            'inference',
        );
        try {
            $fixture->registerAutoload('App');
            $runtime = require __DIR__ . '/../../fixture/compile/inferred_call_arguments/verify/runtime.php';
            $runtime($fixture);
        } finally {
            $fixture->cleanup();
        }
    }

    public function testFreeFunctionInfersFromLiteral(): void
    {
        $dist = $this->compile([
            'Lib.xphp' => self::LIB,
            'Use.xphp' => "<?php\ndeclare(strict_types=1);\nnamespace App;\n\$r = identity(5);\n",
        ]);
        // The bare call was specialized (mangled `identity_T_<…>`), not left as a bare `identity`.
        self::assertStringContainsString('identity_T_', self::read($dist, 'Use.php'));
    }

    public function testStaticMethodInfersFromLiteral(): void
    {
        $dist = $this->compile([
            'Lib.xphp' => self::LIB,
            'Use.xphp' => "<?php\ndeclare(strict_types=1);\nnamespace App;\n\$r = Factory::make('hi');\n",
        ]);
        self::assertStringContainsString('make_', self::read($dist, 'Use.php'));
    }

    public function testInstanceMethodInfersFromLiteral(): void
    {
        $dist = $this->compile([
            'Lib.xphp' => self::LIB,
            'Use.xphp' => "<?php\ndeclare(strict_types=1);\nnamespace App;\n\$b = new Bag();\n\$r = \$b->put(9);\n",
        ]);
        self::assertStringContainsString('put_', self::read($dist, 'Use.php'));
    }

    public function testInferenceProducesTheSameSpecializationAsATurbofish(): void
    {
        // The inferred call and the explicit-turbofish call must dispatch to the byte-identical
        // mangled specialization — inference just writes the turbofish for you.
        $inferred = self::read($this->compile([
            'Lib.xphp' => self::LIB,
            'Use.xphp' => "<?php\ndeclare(strict_types=1);\nnamespace App;\n\$r = identity(5);\n",
        ]), 'Use.php');
        $explicit = self::read($this->compile([
            'Lib.xphp' => self::LIB,
            'Use.xphp' => "<?php\ndeclare(strict_types=1);\nnamespace App;\n\$r = identity::<int>(5);\n",
        ]), 'Use.php');

        self::assertSame(1, preg_match('/identity_T_\w+/', $inferred, $inferredMatch));
        self::assertSame(1, preg_match('/identity_T_\w+/', $explicit, $explicitMatch));
        self::assertSame($explicitMatch[0], $inferredMatch[0]);
    }

    public function testCheckAcceptsAnInferableCall(): void
    {
        $collector = $this->check([
            'Lib.xphp' => self::LIB,
            'Use.xphp' => "<?php\ndeclare(strict_types=1);\nnamespace App;\n\$r = identity(5);\n",
        ]);
        self::assertSame([], $collector->all(), 'an inferable bare call must not be flagged');
    }

    public function testExplicitTurbofishStillCompiles(): void
    {
        // No regression: an explicit turbofish is unchanged by inference.
        $dist = $this->compile([
            'Lib.xphp' => self::LIB,
            'Use.xphp' => "<?php\ndeclare(strict_types=1);\nnamespace App;\n\$r = identity::<int>(5);\n",
        ]);
        self::assertStringContainsString('identity_T_', self::read($dist, 'Use.php'));
    }

    public function testFirstClassCallableIsNotInferred(): void
    {
        // `identity(...)` creates a Closure; it must not be inferred or flagged.
        $collector = $this->check([
            'Lib.xphp' => self::LIB,
            'Use.xphp' => "<?php\ndeclare(strict_types=1);\nnamespace App;\n\$cb = identity(...);\n",
        ]);
        self::assertSame([], $collector->all(), 'a first-class-callable must not be inferred or flagged');
    }

    public function testConflictingArgumentsFallBackToErrorInCheck(): void
    {
        // pair<T>(T $a, T $b) called (int, string): T is witnessed as two types → no inference →
        // today's missing-type-argument error.
        $collector = $this->check([
            'Lib.xphp' => "<?php\ndeclare(strict_types=1);\nnamespace App;\nfunction pair<T>(T \$a, T \$b): T { return \$a; }\n",
            'Use.xphp' => "<?php\ndeclare(strict_types=1);\nnamespace App;\n\$r = pair(1, 'x');\n",
        ]);
        $codes = array_map(static fn (Diagnostic $d): string => $d->code, $collector->all());
        self::assertContains(Registry::CODE_MISSING_TYPE_ARGUMENT, $codes);
    }

    public function testConflictingArgumentsFailCompile(): void
    {
        // Parity: the same conflict throws in compile mode.
        $this->expectException(RuntimeException::class);
        $this->compile([
            'Lib.xphp' => "<?php\ndeclare(strict_types=1);\nnamespace App;\nfunction pair<T>(T \$a, T \$b): T { return \$a; }\n",
            'Use.xphp' => "<?php\ndeclare(strict_types=1);\nnamespace App;\n\$r = pair(1, 'x');\n",
        ]);
    }

    // --- soundness: a call argument typed by an in-scope type parameter must not infer -----------

    public function testCallArgTypedByEnclosingFunctionTypeParamFallsBackInCheck(): void
    {
        // A bare call whose argument is typed by the ENCLOSING function's type parameter must not
        // infer — that type is abstract here. Inferring would emit `identity::<U>`, a call to the
        // non-existent class `U`. It falls back to the missing-type-argument error instead.
        $collector = $this->check([
            'Lib.xphp' => "<?php\ndeclare(strict_types=1);\nnamespace App;\nfunction identity<T>(T \$x): T { return \$x; }\nfunction outer<U>(U \$x): U { \$r = identity(\$x); return \$x; }\n",
            'Use.xphp' => "<?php\ndeclare(strict_types=1);\nnamespace App;\n\$r = outer::<int>(5);\n",
        ]);
        $codes = array_map(static fn (Diagnostic $d): string => $d->code, $collector->all());
        self::assertContains(Registry::CODE_MISSING_TYPE_ARGUMENT, $codes);
    }

    public function testCallArgTypedByEnclosingFunctionTypeParamFailsCompile(): void
    {
        // Parity: compile throws the same error rather than emitting the broken specialization.
        $this->expectException(RuntimeException::class);
        $this->compile([
            'Lib.xphp' => "<?php\ndeclare(strict_types=1);\nnamespace App;\nfunction identity<T>(T \$x): T { return \$x; }\nfunction outer<U>(U \$x): U { \$r = identity(\$x); return \$x; }\n",
            'Use.xphp' => "<?php\ndeclare(strict_types=1);\nnamespace App;\n\$r = outer::<int>(5);\n",
        ]);
    }

    public function testCallArgTypedByMethodTypeParamFallsBack(): void
    {
        // Same, but the argument is typed by the enclosing METHOD's type parameter.
        $collector = $this->check([
            'Lib.xphp' => "<?php\ndeclare(strict_types=1);\nnamespace App;\nfunction identity<T>(T \$x): T { return \$x; }\nfinal class Foo { public function m<W>(W \$x): W { \$r = identity(\$x); return \$x; } }\n",
            'Use.xphp' => "<?php\ndeclare(strict_types=1);\nnamespace App;\n\$noop = 1;\n",
        ]);
        $codes = array_map(static fn (Diagnostic $d): string => $d->code, $collector->all());
        self::assertContains(Registry::CODE_MISSING_TYPE_ARGUMENT, $codes);
    }

    public function testCallArgTypedByEnclosingClassTypeParamFallsBack(): void
    {
        // Same, but the argument is typed by the enclosing CLASS's type parameter.
        $collector = $this->check([
            'Lib.xphp' => "<?php\ndeclare(strict_types=1);\nnamespace App;\nfunction identity<T>(T \$x): T { return \$x; }\nfinal class C<E> { public function f(E \$e): void { \$r = identity(\$e); } }\n",
            'Use.xphp' => "<?php\ndeclare(strict_types=1);\nnamespace App;\n\$noop = 1;\n",
        ]);
        $codes = array_map(static fn (Diagnostic $d): string => $d->code, $collector->all());
        self::assertContains(Registry::CODE_MISSING_TYPE_ARGUMENT, $codes);
    }

    public function testInstanceCallArgTypedByClassTypeParamFallsBack(): void
    {
        // The instance-method seam: `$this->prop`-less bare instance-generic call whose argument is
        // typed by the class type parameter must not infer either.
        $collector = $this->check([
            'Lib.xphp' => "<?php\ndeclare(strict_types=1);\nnamespace App;\nfinal class Sink { public function take<T>(T \$x): T { return \$x; } }\nfinal class C<E> { public function f(E \$e): void { \$s = new Sink(); \$r = \$s->take(\$e); } }\n",
            'Use.xphp' => "<?php\ndeclare(strict_types=1);\nnamespace App;\n\$noop = 1;\n",
        ]);
        $codes = array_map(static fn (Diagnostic $d): string => $d->code, $collector->all());
        self::assertContains(Registry::CODE_MISSING_TYPE_ARGUMENT, $codes);
    }

    public function testExplicitTurbofishGroundedByEnclosingParamStillWorks(): void
    {
        // The correct alternative — an explicit turbofish grounded by the enclosing type parameter —
        // still grounds per specialization (unchanged by inference): `outer::<int>` emits a call to
        // a concrete `identity_T_<int>`, never a reference to the abstract `U`.
        $dist = $this->compile([
            'Lib.xphp' => "<?php\ndeclare(strict_types=1);\nnamespace App;\nfunction identity<T>(T \$x): T { return \$x; }\nfunction outer<U>(U \$x): U { \$r = identity::<U>(\$x); return \$x; }\n",
            'Use.xphp' => "<?php\ndeclare(strict_types=1);\nnamespace App;\n\$r = outer::<int>(5);\n",
        ]);
        $lib = self::read($dist, 'Lib.php');
        self::assertStringContainsString('identity_T_', $lib);
        self::assertStringNotContainsString('\\App\\U', $lib, 'no reference to the abstract type parameter U');
    }

    // --- `new` inference -----------------------------------------------------------------------

    private const BOX = <<<'PHP'
    <?php
    declare(strict_types=1);
    namespace App;
    class Plastic {}
    final class Box<T> { public function __construct(private T $value) {} public function get(): T { return $this->value; } }
    PHP;

    #[RunInSeparateProcess]
    public function testInferredNewArgumentsRunAtRuntime(): void
    {
        $fixture = CompiledFixture::compile(
            __DIR__ . '/../../fixture/compile/inferred_new_arguments/source',
            'new-inference',
        );
        try {
            $fixture->registerAutoload('App');
            $runtime = require __DIR__ . '/../../fixture/compile/inferred_new_arguments/verify/runtime.php';
            $runtime($fixture);
        } finally {
            $fixture->cleanup();
        }
    }

    public function testBareNewInfersFromLiteral(): void
    {
        $dist = $this->compile([
            'Box.xphp' => self::BOX,
            'Use.xphp' => "<?php\ndeclare(strict_types=1);\nnamespace App;\n\$b = new Box(5);\n",
        ]);
        // The bare `new` dispatched to a specialized Box class (not the stripped `interface Box`).
        self::assertStringContainsString('Generated\\App\\Box\\T_', self::read($dist, 'Use.php'));
    }

    public function testNestedGenericNewInfersSameSpecializationAsTurbofish(): void
    {
        // `new Box(new Box(5))` must infer `Box<Box<int>>` — the SAME specialization the explicit
        // turbofish selects — not `Box<Box>` read off an un-annotated inner node. Regression for the
        // bottom-up (leaveNode) annotation order.
        $box = "<?php\ndeclare(strict_types=1);\nnamespace App;\nfinal class Box<T> { public function __construct(private T \$v) {} public function get(): T { return \$this->v; } }\n";
        $inferred = self::read($this->compile([
            'Box.xphp' => $box,
            'Use.xphp' => "<?php\ndeclare(strict_types=1);\nnamespace App;\n\$b = new Box(new Box(5));\n",
        ]), 'Use.php');
        $explicit = self::read($this->compile([
            'Box.xphp' => $box,
            'Use.xphp' => "<?php\ndeclare(strict_types=1);\nnamespace App;\n\$b = new Box::<Box<int>>(new Box::<int>(5));\n",
        ]), 'Use.php');
        self::assertSame(2, preg_match_all('/Box\\\\T_\w+/', $explicit, $e));
        self::assertSame(2, preg_match_all('/Box\\\\T_\w+/', $inferred, $i));
        // The inferred outer + inner specializations are exactly the two the turbofish produces.
        self::assertSame($e[0], $i[0], 'inferred nested new selects the same specializations as the turbofish');
        self::assertCount(2, array_unique($i[0]), 'outer and inner are distinct specializations');
    }

    public function testCallInfersFromAClassReturningCallArgument(): void
    {
        // A call argument that is itself a call returning a determinable class is an inference source
        // for the call path (it reuses the receiver flow engine): `identity($f->make())` infers
        // T=Plastic. (The `new` pass is more conservative and would not.)
        $dist = $this->compile([
            'Lib.xphp' => "<?php\ndeclare(strict_types=1);\nnamespace App;\nclass Plastic {}\nfunction identity<T>(T \$x): T { return \$x; }\nfinal class Factory { public function make(): Plastic { return new Plastic(); } }\nfinal class R { public function go(Factory \$f): Plastic { return identity(\$f->make()); } }\n",
            'Use.xphp' => "<?php\ndeclare(strict_types=1);\nnamespace App;\n\$noop = 1;\n",
        ]);
        self::assertStringContainsString('identity_T_', self::read($dist, 'Lib.php'));
    }

    public function testBareNewInfersFromNewArgument(): void
    {
        $collector = $this->check([
            'Box.xphp' => self::BOX,
            'Use.xphp' => "<?php\ndeclare(strict_types=1);\nnamespace App;\n\$b = new Box(new Plastic());\n",
        ]);
        self::assertSame([], $collector->all(), 'a `new` whose argument determines T must not be flagged');
    }

    public function testNonInferableBareNewStillErrorsInCheck(): void
    {
        // T appears only in a method return, so `new Box()` cannot infer it → still an error.
        $collector = $this->check([
            'Box.xphp' => "<?php\ndeclare(strict_types=1);\nnamespace App;\nfinal class Box<T> { public function get(): ?T { return null; } }\n",
            'Use.xphp' => "<?php\ndeclare(strict_types=1);\nnamespace App;\n\$b = new Box();\n",
        ]);
        $codes = array_map(static fn (Diagnostic $d): string => $d->code, $collector->all());
        self::assertContains(Registry::CODE_MISSING_TYPE_ARGUMENT, $codes);
    }

    public function testNonInferableBareNewThrowsInCompile(): void
    {
        // Parity with check.
        $this->expectException(RuntimeException::class);
        $this->compile([
            'Box.xphp' => "<?php\ndeclare(strict_types=1);\nnamespace App;\nfinal class Box<T> { public function get(): ?T { return null; } }\n",
            'Use.xphp' => "<?php\ndeclare(strict_types=1);\nnamespace App;\n\$b = new Box();\n",
        ]);
    }

    public function testParameterInferenceUsesTheRightScopeAfterANestedClosure(): void
    {
        // After a nested closure closes, `$op` must resolve against the OUTER function's scope —
        // i.e. the scope stack is popped on leave. `new Box($op)` infers Box<Plastic>; without the
        // pop it would read the closure's scope (which has $wp, not $op) and fail to infer.
        $dist = $this->compile([
            'Lib.xphp' => "<?php\ndeclare(strict_types=1);\nnamespace App;\nclass Plastic {}\nclass Widget {}\nfinal class Box<T> { public function __construct(private T \$v) {} public function get(): T { return \$this->v; } }\nfunction outer(Plastic \$op): Plastic { \$f = function (Widget \$wp): Widget { return \$wp; }; \$unused = \$f; \$b = new Box(\$op); return \$b->get(); }\n",
            'Use.xphp' => "<?php\ndeclare(strict_types=1);\nnamespace App;\n\$noop = 1;\n",
        ]);
        self::assertStringContainsString('Generated\\App\\Box\\T_', self::read($dist, 'Lib.php'));
    }

    public function testPropertyInferenceUsesTheRightClassAfterANestedClass(): void
    {
        // After a nested (anonymous) class closes, `$this->ap` must resolve against the OUTER class
        // A — i.e. the class stack is popped on leave. `new Box($this->ap)` infers Box<Plastic>;
        // without the pop it would scan the anon class (which has no `$ap`) and fail to infer.
        $dist = $this->compile([
            'Lib.xphp' => "<?php\ndeclare(strict_types=1);\nnamespace App;\nclass Plastic {}\nclass Widget {}\nfinal class Box<T> { public function __construct(private T \$v) {} public function get(): T { return \$this->v; } }\nfinal class A { private Plastic \$ap; public function __construct() { \$this->ap = new Plastic(); } public function m(): Plastic { \$inner = new class { private ?Widget \$wp = null; public function n(): ?Widget { return \$this->wp; } }; \$b = new Box(\$this->ap); return \$b->get(); } }\n",
            'Use.xphp' => "<?php\ndeclare(strict_types=1);\nnamespace App;\n\$noop = 1;\n",
        ]);
        self::assertStringContainsString('Generated\\App\\Box\\T_', self::read($dist, 'Lib.php'));
    }

    public function testReassignedParameterIsNotTrustedForNewInference(): void
    {
        // $p is reassigned before the `new`, so its declared type is no longer trustworthy: the
        // pass must NOT infer Box<Plastic> from the stale declaration (the value is now an int).
        // It falls back to requiring an explicit turbofish instead of emitting an unsound type.
        $collector = $this->check([
            'Box.xphp' => self::BOX,
            'Use.xphp' => "<?php\ndeclare(strict_types=1);\nnamespace App;\nfunction f(Plastic \$p): void { \$p = 5; \$b = new Box(\$p); }\n",
        ]);
        $codes = array_map(static fn (Diagnostic $d): string => $d->code, $collector->all());
        self::assertContains(Registry::CODE_MISSING_TYPE_ARGUMENT, $codes);
    }

    public function testAllDefaultsBareNewStillSynthesizes(): void
    {
        // No regression: a bare `new` of an all-defaults template still pads from defaults; the
        // inference pass leaves it bare (nothing to infer) and the synthesis path handles it.
        $dist = $this->compile([
            'Cache.xphp' => "<?php\ndeclare(strict_types=1);\nnamespace App;\nfinal class Cache<T = int> { public function size(): int { return 0; } }\n",
            'Use.xphp' => "<?php\ndeclare(strict_types=1);\nnamespace App;\n\$c = new Cache();\n",
        ]);
        self::assertStringContainsString('Generated\\App\\Cache\\', self::read($dist, 'Use.php'));
    }

    public function testNewInfersThroughGroupImport(): void
    {
        // The `new` class name resolves through a group `use` — the pass must index group imports.
        $dist = $this->compile([
            'Box.xphp' => "<?php\ndeclare(strict_types=1);\nnamespace App\\Lib;\nfinal class Box<T> { public function __construct(private T \$v) {} }\n",
            'Use.xphp' => "<?php\ndeclare(strict_types=1);\nnamespace App;\nuse App\\Lib\\{Box};\n\$b = new Box(5);\n",
        ]);
        self::assertStringContainsString('Generated\\App\\Lib\\Box\\T_', self::read($dist, 'Use.php'));
    }

    public function testBareNewOfNonGenericClassIsUntouched(): void
    {
        // `new Plastic()` is not a generic template; the pass must skip it without error.
        $collector = $this->check([
            'Box.xphp' => self::BOX,
            'Use.xphp' => "<?php\ndeclare(strict_types=1);\nnamespace App;\n\$p = new Plastic();\n",
        ]);
        self::assertSame([], $collector->all());
    }

    public function testVariableVariableArgumentFallsBack(): void
    {
        // `new Box($$name)` — the argument is a variable-variable (its name is an expression, not a
        // string), so it can't be typed; inference falls back rather than mishandling the name.
        $collector = $this->check([
            'Box.xphp' => self::BOX,
            'Use.xphp' => "<?php\ndeclare(strict_types=1);\nnamespace App;\n\$name = 'x';\n\$b = new Box(\$\$name);\n",
        ]);
        $codes = array_map(static fn (Diagnostic $d): string => $d->code, $collector->all());
        self::assertContains(Registry::CODE_MISSING_TYPE_ARGUMENT, $codes);
    }

    public function testNewInfersFromPropertyDeclaredAfterAMethod(): void
    {
        // The property scan must skip non-property statements (a method here) and keep looking,
        // not stop at the first one.
        $collector = $this->check([
            'Box.xphp' => "<?php\ndeclare(strict_types=1);\nnamespace App;\nclass Plastic {}\nfinal class Box<T> { public function __construct(private T \$v) {} }\nfinal class H { public function boot(): void {} private Plastic \$p; public function f(): void { \$b = new Box(\$this->p); } }\n",
            'Use.xphp' => "<?php\ndeclare(strict_types=1);\nnamespace App;\n\$h = new H();\n",
        ]);
        self::assertSame([], $collector->all(), 'new Box($this->p) must infer even when p follows a method');
    }

    public function testNewFromUnionTypedPropertyFallsBack(): void
    {
        // A union-typed property is a shape paramTypeRef does not model (null), so the `new` falls
        // back rather than dereferencing a null type.
        $collector = $this->check([
            'Box.xphp' => "<?php\ndeclare(strict_types=1);\nnamespace App;\nfinal class Box<T> { public function __construct(private T \$v) {} }\nfinal class H { private int|string \$u = 1; public function f(): void { \$b = new Box(\$this->u); } }\n",
            'Use.xphp' => "<?php\ndeclare(strict_types=1);\nnamespace App;\n\$h = new H();\n",
        ]);
        $codes = array_map(static fn (Diagnostic $d): string => $d->code, $collector->all());
        self::assertContains(Registry::CODE_MISSING_TYPE_ARGUMENT, $codes);
    }

    public function testMethodGenericParameterIsNotTrustedForNewInference(): void
    {
        // Inside a generic method, a parameter typed by the METHOD type parameter is abstract, so
        // `new Box($x)` must not infer from it (that would fabricate a bogus class argument).
        $collector = $this->check([
            'Box.xphp' => "<?php\ndeclare(strict_types=1);\nnamespace App;\nfinal class Box<T> { public function __construct(private T \$v) {} }\nfinal class M { public function make<U>(U \$x): void { \$b = new Box(\$x); } }\n",
            'Use.xphp' => "<?php\ndeclare(strict_types=1);\nnamespace App;\n\$m = new M();\n",
        ]);
        $codes = array_map(static fn (Diagnostic $d): string => $d->code, $collector->all());
        self::assertContains(Registry::CODE_MISSING_TYPE_ARGUMENT, $codes);
    }

    public function testArrowFunctionPresentDoesNotBreakInference(): void
    {
        // An arrow function has no statement body (getStmts() is null); the reassignment scan must
        // tolerate that, and a bare `new Box(5)` alongside it still infers.
        $dist = $this->compile([
            'Box.xphp' => self::BOX,
            'Use.xphp' => "<?php\ndeclare(strict_types=1);\nnamespace App;\n\$f = fn(\$x) => \$x;\n\$b = new Box(5);\n",
        ]);
        self::assertStringContainsString('Generated\\App\\Box\\T_', self::read($dist, 'Use.php'));
    }

    public function testEveryReassignmentFormDropsTheParameter(): void
    {
        // Each reassignment form — `=`, `+=`, `=&`, `++x`, `x++`, `--x`, `x--` — must mark the
        // parameter untrusted, so every `new Box($x)` falls back: one missing-type-argument per
        // site. Pins the whole reassignment-detection predicate (and that ALL names are dropped,
        // not just the first).
        $body = '$a = 5; $b += 1; $ref = 1; $c =& $ref; ++$d; $e++; --$f; $g--; '
            . 'new Box($a); new Box($b); new Box($c); new Box($d); new Box($e); new Box($f); new Box($g);';
        $collector = $this->check([
            'Box.xphp' => self::BOX,
            'Use.xphp' => "<?php\ndeclare(strict_types=1);\nnamespace App;\nfunction f(Plastic \$a, Plastic \$b, Plastic \$c, Plastic \$d, Plastic \$e, Plastic \$f, Plastic \$g): void { {$body} }\n",
        ]);
        $codes = array_map(static fn (Diagnostic $d): string => $d->code, $collector->all());
        self::assertSame(array_fill(0, 7, Registry::CODE_MISSING_TYPE_ARGUMENT), $codes);
    }

    public function testReassignedParameterDoesNotBlockLaterTrustedParameter(): void
    {
        // The first parameter is reassigned (skipped), but the scan must CONTINUE to the second,
        // trustworthy parameter — not stop at the first.
        $dist = $this->compile([
            'Box.xphp' => self::BOX,
            'Use.xphp' => "<?php\ndeclare(strict_types=1);\nnamespace App;\nfunction f(Plastic \$bad, Plastic \$good): void { \$bad = 5; \$b = new Box(\$good); }\n",
        ]);
        self::assertStringContainsString('Generated\\App\\Box\\T_', self::read($dist, 'Use.php'));
    }

    public function testClassTypeParameterIsNotTrustedForNewInference(): void
    {
        // A parameter typed by the enclosing CLASS type parameter is abstract, so `new Box($e)`
        // must not infer from it.
        $collector = $this->check([
            'Box.xphp' => "<?php\ndeclare(strict_types=1);\nnamespace App;\nfinal class Box<T> { public function __construct(private T \$v) {} }\nfinal class C<E> { public function f(E \$e): void { \$b = new Box(\$e); } }\n",
            'Use.xphp' => "<?php\ndeclare(strict_types=1);\nnamespace App;\n\$noop = 1;\n",
        ]);
        $codes = array_map(static fn (Diagnostic $d): string => $d->code, $collector->all());
        self::assertContains(Registry::CODE_MISSING_TYPE_ARGUMENT, $codes);
    }

    public function testBodilessMethodDoesNotBreakTheReassignmentScan(): void
    {
        // An interface method has no body (getStmts() is null); the reassignment scan must tolerate
        // that. A bare `new Box(5)` in the same program still infers.
        $dist = $this->compile([
            'Box.xphp' => "<?php\ndeclare(strict_types=1);\nnamespace App;\ninterface I { public function m(): void; }\nfinal class Box<T> { public function __construct(private T \$v) {} }\n",
            'Use.xphp' => "<?php\ndeclare(strict_types=1);\nnamespace App;\n\$b = new Box(5);\n",
        ]);
        self::assertStringContainsString('Generated\\App\\Box\\T_', self::read($dist, 'Use.php'));
    }

    public function testMultipleTrustedParametersAreAllRetained(): void
    {
        // Two typed, non-reassigned parameters: BOTH must stay in the scope map (it isn't truncated
        // to one entry), so both `new Box(...)` infer.
        $collector = $this->check([
            'Box.xphp' => self::BOX,
            'Use.xphp' => "<?php\ndeclare(strict_types=1);\nnamespace App;\nfunction f(Plastic \$a, Plastic \$b): void { \$x = new Box(\$a); \$y = new Box(\$b); }\n",
        ]);
        self::assertSame([], $collector->all(), 'both new Box(...) infer from their trusted parameters');
    }

    public function testMultipleClassTypeParametersAreAllRecognized(): void
    {
        // A class with two type parameters: BOTH must be recognized as abstract (the name set isn't
        // truncated), so neither `new Box($a)` nor `new Box($b)` infers → two fallbacks.
        $collector = $this->check([
            'Box.xphp' => "<?php\ndeclare(strict_types=1);\nnamespace App;\nfinal class Box<T> { public function __construct(private T \$v) {} }\nfinal class P<A, B> { public function f(A \$a, B \$b): void { \$x = new Box(\$a); \$y = new Box(\$b); } }\n",
            'Use.xphp' => "<?php\ndeclare(strict_types=1);\nnamespace App;\n\$noop = 1;\n",
        ]);
        $codes = array_map(static fn (Diagnostic $d): string => $d->code, $collector->all());
        self::assertSame(
            [Registry::CODE_MISSING_TYPE_ARGUMENT, Registry::CODE_MISSING_TYPE_ARGUMENT],
            $codes,
        );
    }

    public function testConstructorNameMatchedCaseInsensitively(): void
    {
        // PHP method names are case-insensitive; a `__Construct` constructor must still be found.
        $collector = $this->check([
            'Box.xphp' => "<?php\ndeclare(strict_types=1);\nnamespace App;\nfinal class Box<T> { public function __Construct(private T \$v) {} }\n",
            'Use.xphp' => "<?php\ndeclare(strict_types=1);\nnamespace App;\n\$b = new Box(5);\n",
        ]);
        self::assertSame([], $collector->all(), 'a __Construct constructor is found case-insensitively, so T infers');
    }

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
