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
use XPHP\TestSupport\CompiledFixture;
use XPHP\FileSystem\FileFinder\NativeFileFinder;
use XPHP\FileSystem\FileReader\NativeFileReader;
use XPHP\FileSystem\FileWriter\NativeFileWriter;

/**
 * End-to-end coverage for grounding a method-generic bound that references an enclosing class type
 * parameter (`<U : E>`) against the receiver's concrete type argument — the element-consuming
 * method shape on a covariant collection. Accept/reject/multi-arg pin that the bound is checked
 * against the *grounded* type (not the literal `E`). When the receiver's type argument genuinely
 * isn't determinable (and it isn't a `$this` self-call), the bound is unprovable and the build
 * fails with `xphp.bound_unprovable` — ground or fail, never a silent accept.
 */
final class EnclosingParamBoundIntegrationTest extends TestCase
{
    private string $work;

    protected function setUp(): void
    {
        $this->work = sys_get_temp_dir() . '/xphp-encbound-' . uniqid('', true);
        mkdir($this->work, 0o755, true);
    }

    protected function tearDown(): void
    {
        self::rrmdir($this->work);
    }

    /** Shared model: Banana extends Fruit extends Food. */
    private const MODELS = <<<'PHP'
    <?php
    declare(strict_types=1);
    namespace App;
    class Food {}
    class Fruit extends Food {}
    class Banana extends Fruit {}
    PHP;

    public function testDirectEnclosingParamBoundAcceptsSubtype(): void
    {
        $dist = $this->compile([
            'Models.xphp' => self::MODELS,
            'Box.xphp' => <<<'PHP'
            <?php
            declare(strict_types=1);
            namespace App;
            class Box<+E> {
                public function contains<U : E>(U $value): bool { return true; }
            }
            PHP,
            'Use.xphp' => <<<'PHP'
            <?php
            declare(strict_types=1);
            namespace App;
            $box = new Box::<Fruit>();
            $box->contains::<Banana>(new Banana());
            PHP,
        ]);

        // Compiled without a bound violation, and the call site was specialized (the method was
        // mangled, not left as a bare `contains`).
        self::assertStringContainsString('contains_', self::read($dist, 'Use.php'));
    }

    public function testInheritedEnclosingParamBoundAcceptsSubtype(): void
    {
        // `contains` is declared on a generic BASE; the receiver is a subclass. Grounding must
        // thread the receiver's `Fruit` through `extends Base<E>` to the base's `E`.
        $this->compile([
            'Models.xphp' => self::MODELS,
            'Base.xphp' => <<<'PHP'
            <?php
            declare(strict_types=1);
            namespace App;
            abstract class Base<+E> {
                public function contains<U : E>(U $value): bool { return true; }
            }
            PHP,
            'ArrayList.xphp' => <<<'PHP'
            <?php
            declare(strict_types=1);
            namespace App;
            class ArrayList<+E> extends Base<E> {}
            PHP,
            'Use.xphp' => <<<'PHP'
            <?php
            declare(strict_types=1);
            namespace App;
            $list = new ArrayList::<Fruit>();
            $list->contains::<Banana>(new Banana());
            PHP,
        ]);

        $this->addToAssertionCount(1); // reaching here = compiled with no bound violation.
    }

    public function testMultiArgEnclosingParamBoundGroundsTheRightParameter(): void
    {
        // `Pair<K, +V>::containsValue<U : V>` — grounding must pick V (index 1), not K. If it used
        // K (Food), `Banana <: Food` would also pass, so make K a type Banana is NOT a subtype of.
        $this->compile([
            'Models.xphp' => self::MODELS,
            'Key.xphp' => "<?php\ndeclare(strict_types=1);\nnamespace App;\nclass Key {}",
            'Pair.xphp' => <<<'PHP'
            <?php
            declare(strict_types=1);
            namespace App;
            class Pair<K, +V> {
                public function containsValue<U : V>(U $value): bool { return true; }
            }
            PHP,
            'Use.xphp' => <<<'PHP'
            <?php
            declare(strict_types=1);
            namespace App;
            $pair = new Pair::<Key, Fruit>();
            $pair->containsValue::<Banana>(new Banana());
            PHP,
        ]);

        $this->addToAssertionCount(1);
    }

    public function testEnclosingParamBoundRejectsNonSubtypeWithGroundedMessage(): void
    {
        // Box<Banana>::contains<Fruit> — Fruit is NOT a subtype of Banana, so this must reject, and
        // the message must show the GROUNDED bound (`Banana`), not the literal type parameter `E`.
        try {
            $this->compile([
                'Models.xphp' => self::MODELS,
                'Box.xphp' => <<<'PHP'
                <?php
                declare(strict_types=1);
                namespace App;
                class Box<+E> {
                    public function contains<U : E>(U $value): bool { return true; }
                }
                PHP,
                'Use.xphp' => <<<'PHP'
                <?php
                declare(strict_types=1);
                namespace App;
                $box = new Box::<Banana>();
                $box->contains::<Fruit>(new Fruit());
                PHP,
            ]);
            self::fail('Expected a bound violation for Box<Banana>::contains<Fruit>.');
        } catch (RuntimeException $e) {
            $msg = $e->getMessage();
            self::assertStringContainsString('Generic bound violated', $msg);
            self::assertStringContainsString('extend/implement "App\\Banana"', $msg, 'bound must be grounded to the receiver arg Banana');
            self::assertStringNotContainsString('"E"', $msg, 'must not report the literal type parameter E');
        }
    }

    public function testUnboundedMethodGenericIsUnaffected(): void
    {
        $dist = $this->compile([
            'Models.xphp' => self::MODELS,
            'Box.xphp' => <<<'PHP'
            <?php
            declare(strict_types=1);
            namespace App;
            class Box<+E> {
                public function pick<U>(U $value): U { return $value; }
            }
            PHP,
            'Use.xphp' => <<<'PHP'
            <?php
            declare(strict_types=1);
            namespace App;
            $box = new Box::<Fruit>();
            $box->pick::<Banana>(new Banana());
            PHP,
        ]);

        self::assertStringContainsString('pick_', self::read($dist, 'Use.php'));
    }

    public function testThisSelfCallWithConcreteTurbofishIsUnprovableAndHardFails(): void
    {
        // `$this->contains::<Banana>()` inside the template body. Whether `Banana : E` holds is
        // instance-dependent (true for Box<Fruit>, false for Box<Rock>) and the bound checker only
        // runs on the abstract template, so it is checked by nobody. Ground or fail → compile error,
        // with the `$this`-specific remedy (a self-call can't bind to a typed local).
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('`$this`-rooted self-call');
        $this->compile([
            'Models.xphp' => self::MODELS,
            'Box.xphp' => <<<'PHP'
            <?php
            declare(strict_types=1);
            namespace App;
            class Box<+E> {
                public function contains<U : E>(U $value): bool { return true; }
                public function probe(): bool { return $this->contains::<Banana>(new Banana()); }
            }
            PHP,
            'Use.xphp' => <<<'PHP'
            <?php
            declare(strict_types=1);
            namespace App;
            $box = new Box::<Fruit>();
            PHP,
        ]);
    }

    public function testParameterReceiverGroundsAndRejects(): void
    {
        // A parameter typed `Box<Banana>` must ground `contains<U:E>` to Banana; Fruit is not a
        // subtype, so this rejects — proving the param's type args are tracked and grounded.
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('extend/implement "App\\Banana"');
        $this->compile([
            'Models.xphp' => self::MODELS,
            'Box.xphp' => self::box(),
            'Use.xphp' => <<<'PHP'
            <?php
            declare(strict_types=1);
            namespace App;
            function consume(Box<Banana> $b): bool { return $b->contains::<Fruit>(new Fruit()); }
            PHP,
        ]);
    }

    public function testPropertyReceiverGroundsAndRejects(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('extend/implement "App\\Banana"');
        $this->compile([
            'Models.xphp' => self::MODELS,
            'Box.xphp' => self::box(),
            'Holder.xphp' => <<<'PHP'
            <?php
            declare(strict_types=1);
            namespace App;
            class Holder {
                private Box<Banana> $b;
                public function run(): bool { return $this->b->contains::<Fruit>(new Fruit()); }
            }
            PHP,
        ]);
    }

    public function testBranchMergeDisagreementIsUnprovableAndHardFails(): void
    {
        // Both arms assign a Box but with DIFFERENT args (Fruit vs Banana); the FQN merges (still Box)
        // yet the args conflict and are dropped, so the receiver's element type is undeterminable. The
        // `<U : E>` bound can't be proven and — not being a `$this` self-call — must fail at compile
        // time rather than silently drop (Maximum Runtime Safety: ground or fail).
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Cannot verify generic bound `U : E`');
        $this->compile([
            'Models.xphp' => self::MODELS,
            'Box.xphp' => self::box(),
            'Use.xphp' => <<<'PHP'
            <?php
            declare(strict_types=1);
            namespace App;
            function pick(bool $c): bool {
                if ($c) { $box = new Box::<Fruit>(); } else { $box = new Box::<Banana>(); }
                return $box->contains::<Food>(new Food());
            }
            PHP,
        ]);
    }

    public function testBranchMergeAgreementGroundsAndAcceptsSubtype(): void
    {
        // Both arms assign Box<Fruit> — the arms AGREE, so the merge keeps the element type and the
        // receiver is determined to be Box<Fruit>. The bound grounds to Fruit and `Banana` (a Fruit)
        // is accepted and the call specialized.
        $dist = $this->compile([
            'Models.xphp' => self::MODELS,
            'Box.xphp' => self::box(),
            'Use.xphp' => <<<'PHP'
            <?php
            declare(strict_types=1);
            namespace App;
            function pick(bool $c): bool {
                if ($c) { $box = new Box::<Fruit>(); } else { $box = new Box::<Fruit>(); }
                return $box->contains::<Banana>(new Banana());
            }
            PHP,
        ]);

        self::assertStringContainsString('contains_', self::read($dist, 'Use.php'));
    }

    public function testBranchMergeAgreementGroundsAndRejectsNonSubtype(): void
    {
        // Both arms assign Box<Banana> — the arms agree, so the args survive the merge and ground the
        // call to Banana. `Fruit` is not a subtype of Banana, so the determined receiver rejects it
        // (a knowable type is never dropped → a determinate violation is never silently accepted).
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('extend/implement "App\\Banana"');
        $this->compile([
            'Models.xphp' => self::MODELS,
            'Box.xphp' => self::box(),
            'Use.xphp' => <<<'PHP'
            <?php
            declare(strict_types=1);
            namespace App;
            function pick(bool $c): bool {
                if ($c) { $box = new Box::<Banana>(); } else { $box = new Box::<Banana>(); }
                return $box->contains::<Fruit>(new Fruit());
            }
            PHP,
        ]);
    }

    public function testArgsSurviveABranchThatDoesNotTouchTheReceiver(): void
    {
        // The receiver is set before the branch and never reassigned inside it, so its args survive
        // and ground the call — Fruit is not a subtype of Banana, so it still rejects.
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('extend/implement "App\\Banana"');
        $this->compile([
            'Models.xphp' => self::MODELS,
            'Box.xphp' => self::box(),
            'Use.xphp' => <<<'PHP'
            <?php
            declare(strict_types=1);
            namespace App;
            function keep(bool $c): bool {
                $box = new Box::<Banana>();
                if ($c) { $unrelated = 1; }
                return $box->contains::<Fruit>(new Fruit());
            }
            PHP,
        ]);
    }

    public function testClosureUseReceiverGroundsAndRejects(): void
    {
        // `use ($box)` imports the outer local's args into the closure scope, so the bound grounds
        // to Banana inside the closure body and rejects Fruit.
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('extend/implement "App\\Banana"');
        $this->compile([
            'Models.xphp' => self::MODELS,
            'Box.xphp' => self::box(),
            'Use.xphp' => <<<'PHP'
            <?php
            declare(strict_types=1);
            namespace App;
            function viaClosure(): callable {
                $box = new Box::<Banana>();
                return function () use ($box): bool { return $box->contains::<Fruit>(new Fruit()); };
            }
            PHP,
        ]);
    }

    // --- receiver type from a method return / chain / self-static ---

    public function testReturnTypedLocalReceiverGroundsAndRejects(): void
    {
        // `$x = $repo->getBox()` where `getBox(): Box<Fruit>` — the local's element type comes from
        // the declared return type, grounds to Fruit, and `Food` (a supertype) is rejected.
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('extend/implement "App\\Fruit"');
        $this->compile([
            'Models.xphp' => self::MODELS,
            'Box.xphp' => self::box(),
            'Repo.xphp' => self::repo(),
            'Use.xphp' => <<<'PHP'
            <?php
            declare(strict_types=1);
            namespace App;
            function pick(Repo $repo): bool {
                $x = $repo->getBox();
                return $x->contains::<Food>(new Food());
            }
            PHP,
        ]);
    }

    public function testChainedReturnReceiverGroundsAndAccepts(): void
    {
        // `$repo->getBox()->contains::<Banana>()` — the chain head's return type `Box<Fruit>` grounds
        // the bound to Fruit, and Banana (a Fruit) is accepted and specialized.
        $dist = $this->compile([
            'Models.xphp' => self::MODELS,
            'Box.xphp' => self::box(),
            'Repo.xphp' => self::repo(),
            'Use.xphp' => <<<'PHP'
            <?php
            declare(strict_types=1);
            namespace App;
            function pick(Repo $repo): bool {
                return $repo->getBox()->contains::<Banana>(new Banana());
            }
            PHP,
        ]);

        self::assertStringContainsString('contains_', self::read($dist, 'Use.php'));
    }

    public function testSelfReturningChainCarriesReceiverArgsAndRejects(): void
    {
        // `copy(): static` returns the receiver's own generic instance, so `$box->copy()` is still
        // Box<Banana>; `contains::<Fruit>` then rejects Fruit (not a subtype of Banana).
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('extend/implement "App\\Banana"');
        $this->compile([
            'Models.xphp' => self::MODELS,
            'Box.xphp' => <<<'PHP'
            <?php
            declare(strict_types=1);
            namespace App;
            class Box<+E> {
                public function copy(): static { return $this; }
                public function contains<U : E>(U $value): bool { return true; }
            }
            PHP,
            'Use.xphp' => <<<'PHP'
            <?php
            declare(strict_types=1);
            namespace App;
            function pick(): bool {
                $box = new Box::<Banana>();
                return $box->copy()->contains::<Fruit>(new Fruit());
            }
            PHP,
        ]);
    }

    public function testStaticFactoryReturnReceiverGroundsAndRejects(): void
    {
        // A static call's declared return type grounds the assigned local: `Factory::make(): Box<Fruit>`
        // makes `$x` a Box<Fruit>, and `contains::<Food>` rejects the supertype Food.
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('extend/implement "App\\Fruit"');
        $this->compile([
            'Models.xphp' => self::MODELS,
            'Box.xphp' => self::box(),
            'Factory.xphp' => <<<'PHP'
            <?php
            declare(strict_types=1);
            namespace App;
            class Factory {
                public static function make(): Box<Fruit> { return new Box::<Fruit>(); }
            }
            PHP,
            'Use.xphp' => <<<'PHP'
            <?php
            declare(strict_types=1);
            namespace App;
            function pick(): bool {
                $x = Factory::make();
                return $x->contains::<Food>(new Food());
            }
            PHP,
        ]);
    }

    public function testDeepSelfReturningChainResolvesWithoutBlowup(): void
    {
        // Regression: resolveCallReturn must memoize. Without it, a chained receiver re-descends both
        // the FQN and the args branch at every hop — O(2^N) in chain depth — and a ~24-deep `copy()`
        // chain hangs. Memoized, it resolves at once, still grounding to Box<Banana> and rejecting Fruit.
        $chain = str_repeat('->copy()', 24);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('extend/implement "App\\Banana"');
        $this->compile([
            'Models.xphp' => self::MODELS,
            'Box.xphp' => <<<'PHP'
            <?php
            declare(strict_types=1);
            namespace App;
            class Box<+E> {
                public function copy(): static { return $this; }
                public function contains<U : E>(U $value): bool { return true; }
            }
            PHP,
            'Use.xphp' => <<<PHP
            <?php
            declare(strict_types=1);
            namespace App;
            function pick(): bool {
                \$box = new Box::<Banana>();
                return \$box{$chain}->contains::<Fruit>(new Fruit());
            }
            PHP,
        ]);
    }

    // --- method-own sibling bound `<U, V : U>` grounded against the call's turbofish args ---

    public function testMethodOwnSiblingBoundAcceptsSubtypeArg(): void
    {
        $dist = $this->compile([
            'Models.xphp' => self::MODELS,
            'Util.xphp' => self::pairUtil(),
            'Use.xphp' => <<<'PHP'
            <?php
            declare(strict_types=1);
            namespace App;
            function go(): bool {
                $u = new Util();
                return $u->pair::<Fruit, Banana>(new Fruit(), new Banana());
            }
            PHP,
        ]);

        self::assertStringContainsString('pair_', self::read($dist, 'Use.php'));
    }

    public function testMethodOwnSiblingBoundRejectsNonSubtypeArg(): void
    {
        // `pair<U, V : U>` with `<Banana, Fruit>` — V (Fruit) must be a subtype of U (Banana); it is
        // not, so the call is rejected. The bound grounds against the call's own turbofish args.
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('extend/implement "App\\Banana"');
        $this->compile([
            'Models.xphp' => self::MODELS,
            'Util.xphp' => self::pairUtil(),
            'Use.xphp' => <<<'PHP'
            <?php
            declare(strict_types=1);
            namespace App;
            function go(): bool {
                $u = new Util();
                return $u->pair::<Banana, Fruit>(new Banana(), new Fruit());
            }
            PHP,
        ]);
    }

    // --- hard-fail the genuine residual (ground or fail) ---

    public function testRawGenericReceiverIsUnprovableAndHardFails(): void
    {
        // A raw `Box` parameter (no type argument) gives the receiver a known class but no element
        // type, so `<U : E>` can't be proven. Not a `$this` self-call → compile error.
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Cannot verify generic bound `U : E`');
        $this->compile([
            'Models.xphp' => self::MODELS,
            'Box.xphp' => self::box(),
            'Use.xphp' => <<<'PHP'
            <?php
            declare(strict_types=1);
            namespace App;
            function pick(Box $b): bool {
                return $b->contains::<Food>(new Food());
            }
            PHP,
        ]);
    }

    public function testStaticMethodClassParamBoundIsUnprovableAndHardFails(): void
    {
        // A class type parameter is unbound in a static context — there is no instance to ground `E` —
        // so a static method whose bound references it is always unprovable and fails.
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Cannot verify generic bound `U : E`');
        $this->compile([
            'Models.xphp' => self::MODELS,
            'Box.xphp' => <<<'PHP'
            <?php
            declare(strict_types=1);
            namespace App;
            class Box<+E> {
                public static function pick<U : E>(U $value): bool { return true; }
            }
            PHP,
            'Use.xphp' => <<<'PHP'
            <?php
            declare(strict_types=1);
            namespace App;
            function go(): bool {
                return Box::pick::<Banana>(new Banana());
            }
            PHP,
        ]);
    }

    public function testThisSelfCallUnprovableIsCollectedInCheckMode(): void
    {
        // The same `$this`-rooted self-call in `check` mode: collected as `xphp.bound_unprovable`
        // rather than thrown, so a whole-program check reports it instead of stopping at the first.
        $collector = $this->check([
            'Models.xphp' => self::MODELS,
            'Box.xphp' => <<<'PHP'
            <?php
            declare(strict_types=1);
            namespace App;
            class Box<+E> {
                public function contains<U : E>(U $value): bool { return true; }
                public function probe(): bool { return $this->contains::<Banana>(new Banana()); }
            }
            PHP,
            'Use.xphp' => <<<'PHP'
            <?php
            declare(strict_types=1);
            namespace App;
            $box = new Box::<Fruit>();
            PHP,
        ]);

        $codes = array_map(static fn (Diagnostic $d): string => $d->code, $collector->all());
        self::assertContains(GenericMethodCompiler::CODE_BOUND_UNPROVABLE, $codes);
    }

    public function testCheckModeCollectsTheUnprovableDiagnostic(): void
    {
        // In `check` mode the unprovable bound is collected as a diagnostic (with its actionable
        // message and stable code) instead of throwing, so a whole-program check can report it.
        $collector = $this->check([
            'Models.xphp' => self::MODELS,
            'Box.xphp' => self::box(),
            'Use.xphp' => <<<'PHP'
            <?php
            declare(strict_types=1);
            namespace App;
            function pick(Box $b): bool {
                return $b->contains::<Food>(new Food());
            }
            PHP,
        ]);

        $codes = array_map(static fn (Diagnostic $d): string => $d->code, $collector->all());
        self::assertContains(GenericMethodCompiler::CODE_BOUND_UNPROVABLE, $codes);
    }

    public function testUntypedForeachReceiverIsUndeterminedAndHardFails(): void
    {
        // A foreach loop variable has no declared type, so a turbofish call on it can't be
        // specialized — the generic method only exists as specializations, so leaving the call would
        // emit a non-existent method that fatals at runtime. Ground or fail → compile error.
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Cannot determine the receiver');
        $this->compile([
            'Models.xphp' => self::MODELS,
            'Box.xphp' => self::box(),
            'Use.xphp' => <<<'PHP'
            <?php
            declare(strict_types=1);
            namespace App;
            function pick(array $boxes): void {
                foreach ($boxes as $box) {
                    $box->contains::<Banana>(new Banana());
                }
            }
            PHP,
        ]);
    }

    public function testUndeterminedReceiverIsCollectedInCheckMode(): void
    {
        // The same undeterminable-receiver call in `check` mode: collected as
        // `xphp.undetermined_receiver` rather than thrown.
        $collector = $this->check([
            'Models.xphp' => self::MODELS,
            'Box.xphp' => self::box(),
            'Use.xphp' => <<<'PHP'
            <?php
            declare(strict_types=1);
            namespace App;
            function pick(array $boxes): void {
                foreach ($boxes as $box) {
                    $box->contains::<Banana>(new Banana());
                }
            }
            PHP,
        ]);

        $codes = array_map(static fn (Diagnostic $d): string => $d->code, $collector->all());
        self::assertContains(GenericMethodCompiler::CODE_UNDETERMINED_RECEIVER, $codes);
    }

    // --- forwarded `$this` self-call to an erasable method: lowered, not failed ---

    private const BOX_FORWARDING = <<<'PHP'
    <?php
    declare(strict_types=1);
    namespace App;
    class Box<+E> {
        public function contains<U : E>(U $value): bool { return true; }
        public function probe<U : E>(U $value): bool { return $this->contains::<U>($value); }
    }
    PHP;

    public function testErasableForwardingSelfCallIsLoweredNotHardFailed(): void
    {
        // `probe<U:E>` forwards its parameter to `$this->contains::<U>()` — both are erasable, so the
        // Specializer lowers both to E-mangled members and rewrites the forward. This COMPILES (it was
        // an interim hard-fail before erasure landed); the call site lowers to the mangled `probe_`.
        $dist = $this->compile([
            'Models.xphp' => self::MODELS,
            'Box.xphp' => self::BOX_FORWARDING,
            'Use.xphp' => <<<'PHP'
            <?php
            declare(strict_types=1);
            namespace App;
            $box = new Box::<Fruit>();
            $box->probe::<Banana>(new Banana());
            PHP,
        ]);

        self::assertStringContainsString('probe_', self::read($dist, 'Use.php'));
    }

    #[RunInSeparateProcess]
    public function testErasableForwardingRunsAtRuntime(): void
    {
        // The non-negotiable gate: execute the emitted output. A bare `$this->contains(...)` (the old
        // silent break) would fatal "undefined method" when `probe` runs; that it returns proves the
        // Specializer rewrote the forward to the emitted `contains_<E>` member.
        $fixture = CompiledFixture::compile(
            __DIR__ . '/../../fixture/compile/enclosing_bound_erasure_forwarding/source',
            'erase-fwd',
        );
        try {
            $fixture->registerAutoload('App');
            require __DIR__ . '/../../fixture/compile/enclosing_bound_erasure_forwarding/verify/runtime.php';
        } finally {
            $fixture->cleanup();
        }
    }

    #[RunInSeparateProcess]
    public function testTwoEnclosingBoundedParamsEraseAndRunAtRuntime(): void
    {
        // A method with two enclosing-bounded params (`<U:E, V:E>`) erases both to E (mangles on
        // [E, E]); both widen to the bound, so `bothAreFruit::<Banana, Cherry>` resolves and runs.
        $fixture = CompiledFixture::compile(
            __DIR__ . '/../../fixture/compile/enclosing_bound_erasure_two_params/source',
            'erase-two',
        );
        try {
            $fixture->registerAutoload('App');
            require __DIR__ . '/../../fixture/compile/enclosing_bound_erasure_two_params/verify/runtime.php';
        } finally {
            $fixture->cleanup();
        }
    }

    #[RunInSeparateProcess]
    public function testMultiClassParamErasureMangleKeysOnTheBoundsReferentAtRuntime(): void
    {
        // `containsValue<U:V>` on `Map<K, +V>` mangles on V (Fruit), not K (string). Call-site and
        // Specializer must agree on that key, or the call resolves to nothing. Executed.
        $fixture = CompiledFixture::compile(
            __DIR__ . '/../../fixture/compile/enclosing_bound_erasure_map_multiparam/source',
            'erase-map',
        );
        try {
            $fixture->registerAutoload('App');
            require __DIR__ . '/../../fixture/compile/enclosing_bound_erasure_map_multiparam/verify/runtime.php';
        } finally {
            $fixture->cleanup();
        }
    }

    #[RunInSeparateProcess]
    public function testTwoTurbofishTypesCollapseToOneWidenedMemberAtRuntime(): void
    {
        // The core erasure semantic: `contains::<Banana>` and `contains::<Cherry>` both lower to one
        // `contains_<Fruit>(Fruit)` member, widened to the bound, accepting each subtype. Executed.
        $fixture = CompiledFixture::compile(
            __DIR__ . '/../../fixture/compile/enclosing_bound_erasure_param_widening/source',
            'erase-widen',
        );
        try {
            $fixture->registerAutoload('App');
            require __DIR__ . '/../../fixture/compile/enclosing_bound_erasure_param_widening/verify/runtime.php';
        } finally {
            $fixture->cleanup();
        }
    }

    #[RunInSeparateProcess]
    public function testInheritedErasableMemberResolvesAtRuntime(): void
    {
        // `contains<U:E>` declared on a generic base, called on a subclass instantiation. The
        // call-site name (keyed on the receiver's E threaded to the declaring base) must match the
        // Specializer's emitted member on Base<Fruit>, inherited by ArrayList<Fruit>. Executed.
        $fixture = CompiledFixture::compile(
            __DIR__ . '/../../fixture/compile/enclosing_bound_erasure_inherited/source',
            'erase-inherit',
        );
        try {
            $fixture->registerAutoload('App');
            require __DIR__ . '/../../fixture/compile/enclosing_bound_erasure_inherited/verify/runtime.php';
        } finally {
            $fixture->cleanup();
        }
    }

    #[RunInSeparateProcess]
    public function testErasureIsVarianceSafeOnTheCovariantChainAtRuntime(): void
    {
        // The variance gate: Box<+E> builds a covariant extends-chain; the distinct E-mangled
        // contains_<E> members coexist with no LSP fatal, and a Box<Banana> dispatches the inherited
        // contains_<Fruit>. Proven by executing the real compiled output.
        $fixture = CompiledFixture::compile(
            __DIR__ . '/../../fixture/compile/enclosing_bound_erasure_covariant_chain/source',
            'erase-chain',
        );
        try {
            $fixture->registerAutoload('App');
            require __DIR__ . '/../../fixture/compile/enclosing_bound_erasure_covariant_chain/verify/runtime.php';
        } finally {
            $fixture->cleanup();
        }
    }

    #[RunInSeparateProcess]
    public function testCovariantInterfaceUpcastResolvesTheErasedMemberAtRuntime(): void
    {
        // The headline gate: a `ListColl<Book>` upcast to `Collection<Product>` calls the erased
        // `contains` through the interface. The concrete supertype specialization that implements
        // `Collection<Product>`'s abstract erased member (`AbstractColl<Product>`) is NEVER
        // instantiated explicitly — the specialization closer must schedule it, or the emitted code
        // fatals at class load. Proven by executing the output.
        $fixture = CompiledFixture::compile(
            __DIR__ . '/../../fixture/compile/enclosing_bound_interface_upcast/source',
            'iface-upcast',
        );
        try {
            $fixture->registerAutoload('App');
            require __DIR__ . '/../../fixture/compile/enclosing_bound_interface_upcast/verify/runtime.php';
        } finally {
            $fixture->cleanup();
        }
    }

    #[RunInSeparateProcess]
    public function testMultiParamCovariantInterfaceUpcastResolvesAtRuntime(): void
    {
        // Multi-param: `HashMap<Id, Book>` (K invariant, +V covariant) upcast to `MMap<Id, Product>`.
        // The closer must schedule `AbstractMap<Id, Product>` — keep K=Id, raise V to Product — and the
        // erased `containsValue` (mangled on V) must resolve through the covariant chain. Executed.
        $fixture = CompiledFixture::compile(
            __DIR__ . '/../../fixture/compile/enclosing_bound_interface_upcast_map/source',
            'iface-upcast-map',
        );
        try {
            $fixture->registerAutoload('App');
            require __DIR__ . '/../../fixture/compile/enclosing_bound_interface_upcast_map/verify/runtime.php';
        } finally {
            $fixture->cleanup();
        }
    }

    #[RunInSeparateProcess]
    public function testVarianceEdgeDoesNotOverwriteASourceParentAtRuntime(): void
    {
        // A covariant class with a SOURCE parent (`ListColl<+E> extends Base<E>`) instantiated at two
        // args (Fruit, Banana). The variance edge emitter must keep each specialization's source
        // `extends Base<E>` rather than overwrite it with the same-template covariant super
        // (`ListColl<Banana> extends ListColl<Fruit>`) — overwriting would sever the inherited
        // `contains_<Banana>` member and fatal "undefined method". Proven by executing the output.
        $fixture = CompiledFixture::compile(
            __DIR__ . '/../../fixture/compile/variance_edge_preserves_source_parent/source',
            'variance-src-parent',
        );
        try {
            $fixture->registerAutoload('App');
            require __DIR__ . '/../../fixture/compile/variance_edge_preserves_source_parent/verify/runtime.php';
        } finally {
            $fixture->cleanup();
        }
    }

    private const PRODUCT = "<?php\ndeclare(strict_types=1);\nnamespace App;\nclass Product {}\n";
    private const BOOK = "<?php\ndeclare(strict_types=1);\nnamespace App;\nclass Book extends Product {}\n";
    private const COLLECTION_IFACE = <<<'PHP'
        <?php
        declare(strict_types=1);
        namespace App;
        interface Collection<+E> {
            public function contains<E2 : E>(E2 $value): bool;
        }
        PHP;
    private const ABSTRACT_COLL = <<<'PHP'
        <?php
        declare(strict_types=1);
        namespace App;
        abstract class AbstractColl<+E> implements Collection<E> {
            /** @var list<mixed> */
            protected array $items;
            public function __construct(E ...$items) { $this->items = $items; }
            public function contains<E2 : E>(E2 $value): bool { return \in_array($value, $this->items, true); }
        }
        PHP;

    public function testCovariantUpcastSchedulesTheConcreteSupertypeSpecialization(): void
    {
        // In-process (mutation-visible) proof of the closer's success path: a `ListColl<Book>` upcast
        // to `Collection<Product>` schedules the implementing supertype `AbstractColl<Product>`, which
        // is never instantiated explicitly. (The runtime fixture proves it then loads + runs.)
        $result = $this->compileResult([
            'Product.xphp' => self::PRODUCT,
            'Book.xphp' => self::BOOK,
            'Collection.xphp' => self::COLLECTION_IFACE,
            'AbstractColl.xphp' => self::ABSTRACT_COLL,
            'ListColl.xphp' => "<?php\ndeclare(strict_types=1);\nnamespace App;\nclass ListColl<+E> extends AbstractColl<E> {}\n",
            'Use.xphp' => <<<'PHP'
                <?php
                declare(strict_types=1);
                namespace App;
                function probe(Collection<Product> $c): bool { return $c->contains::<Product>(new Product()); }
                $l = new ListColl::<Book>(new Book());
                $r = probe($l);
                PHP,
        ]);

        $abstractColl = self::specializationsOf($result, 'App\\AbstractColl');
        self::assertContains(['App\\Product'], $abstractColl, 'the closer must schedule AbstractColl<Product>');
        self::assertContains(['App\\Book'], $abstractColl, 'AbstractColl<Book> is the natural specialization');
    }

    public function testUpcastWhenTheConcreteClassDeclaresTheMethodItself(): void
    {
        // The erasable body is on the CONCRETE class itself (no abstract base, no other parent). The
        // declaring-class search must include the concrete class itself, and schedule ListColl<Product>.
        $result = $this->compileResult([
            'Product.xphp' => self::PRODUCT,
            'Book.xphp' => self::BOOK,
            'Collection.xphp' => self::COLLECTION_IFACE,
            'ListColl.xphp' => <<<'PHP'
                <?php
                declare(strict_types=1);
                namespace App;
                class ListColl<+E> implements Collection<E> {
                    /** @var list<mixed> */
                    protected array $items;
                    public function __construct(E ...$items) { $this->items = $items; }
                    public function contains<E2 : E>(E2 $value): bool { return \in_array($value, $this->items, true); }
                }
                PHP,
            'Use.xphp' => <<<'PHP'
                <?php
                declare(strict_types=1);
                namespace App;
                function probe(Collection<Product> $c): bool { return $c->contains::<Product>(new Product()); }
                $l = new ListColl::<Book>(new Book());
                $r = probe($l);
                PHP,
        ]);

        $listColl = self::specializationsOf($result, 'App\\ListColl');
        self::assertContains(['App\\Product'], $listColl, 'closer must schedule ListColl<Product> when the leaf itself declares the method');
    }

    public function testInvariantInterfaceParameterSchedulesNoUpcastImplementer(): void
    {
        // The interface parameter is INVARIANT, so `Holder<Book>` is NOT an instance of `Inv<Product>`
        // even though Book <: Product. The closer must reach the strict-upcast check and decline to
        // schedule (no `Holder<Product>` / no implementer for `Inv<Product>`).
        $result = $this->compileResult([
            'Product.xphp' => self::PRODUCT,
            'Book.xphp' => self::BOOK,
            'Inv.xphp' => <<<'PHP'
                <?php
                declare(strict_types=1);
                namespace App;
                interface Inv<E> {
                    public function contains<E2 : E>(E2 $value): bool;
                }
                PHP,
            'Holder.xphp' => <<<'PHP'
                <?php
                declare(strict_types=1);
                namespace App;
                class Holder<E> implements Inv<E> {
                    /** @var list<mixed> */
                    protected array $items;
                    public function __construct(E ...$items) { $this->items = $items; }
                    public function contains<E2 : E>(E2 $value): bool { return \in_array($value, $this->items, true); }
                }
                PHP,
            'Use.xphp' => <<<'PHP'
                <?php
                declare(strict_types=1);
                namespace App;
                function withBooks(Inv<Book> $x): bool { return $x->contains::<Book>(new Book()); }
                function withProducts(Inv<Product> $x): bool { return $x->contains::<Product>(new Product()); }
                $h = new Holder::<Book>(new Book());
                $r = withBooks($h);
                PHP,
        ]);

        // Holder<Product> is never instantiated and the invariant interface gives no upcast, so the
        // closer schedules nothing for it.
        self::assertSame([['App\\Book']], self::specializationsOf($result, 'App\\Holder'), 'invariant interface → no upcast → no extra Holder');
    }

    public function testInterfaceWithANonGenericMethodStillFindsTheErasableOne(): void
    {
        // The interface mixes a non-generic method (`size`) with the erasable `contains`. Collecting the
        // erasable names must skip `size` and keep scanning to `contains` — the closer still schedules
        // the implementer under a covariant upcast.
        $result = $this->compileResult([
            'Product.xphp' => self::PRODUCT,
            'Book.xphp' => self::BOOK,
            'Collection.xphp' => <<<'PHP'
                <?php
                declare(strict_types=1);
                namespace App;
                interface Collection<+E> {
                    public function size(): int;
                    public function contains<E2 : E>(E2 $value): bool;
                }
                PHP,
            'AbstractColl.xphp' => <<<'PHP'
                <?php
                declare(strict_types=1);
                namespace App;
                abstract class AbstractColl<+E> implements Collection<E> {
                    /** @var list<mixed> */
                    protected array $items;
                    public function __construct(E ...$items) { $this->items = $items; }
                    public function size(): int { return \count($this->items); }
                    public function contains<E2 : E>(E2 $value): bool { return \in_array($value, $this->items, true); }
                }
                PHP,
            'ListColl.xphp' => "<?php\ndeclare(strict_types=1);\nnamespace App;\nclass ListColl<+E> extends AbstractColl<E> {}\n",
            'Use.xphp' => <<<'PHP'
                <?php
                declare(strict_types=1);
                namespace App;
                function probe(Collection<Product> $c): bool { return $c->contains::<Product>(new Product()); }
                $l = new ListColl::<Book>(new Book());
                $r = probe($l);
                PHP,
        ]);

        self::assertContains(['App\\Product'], self::specializationsOf($result, 'App\\AbstractColl'), 'the erasable method must be found past the non-generic one');
    }

    public function testDirectImplementationSchedulesNoExtraSpecialization(): void
    {
        // The concrete spec implements the interface spec it is used as DIRECTLY (no covariant upcast:
        // ListColl<Book> used as Collection<Book>). The closer's argsEqual early-return must fire — no
        // AbstractColl<Product> appears.
        $result = $this->compileResult([
            'Product.xphp' => self::PRODUCT,
            'Book.xphp' => self::BOOK,
            'Collection.xphp' => self::COLLECTION_IFACE,
            'AbstractColl.xphp' => self::ABSTRACT_COLL,
            'ListColl.xphp' => "<?php\ndeclare(strict_types=1);\nnamespace App;\nclass ListColl<+E> extends AbstractColl<E> {}\n",
            'Use.xphp' => <<<'PHP'
                <?php
                declare(strict_types=1);
                namespace App;
                function probe(Collection<Book> $c): bool { return $c->contains::<Book>(new Book()); }
                $l = new ListColl::<Book>(new Book());
                $r = probe($l);
                PHP,
        ]);

        $abstractColl = self::specializationsOf($result, 'App\\AbstractColl');
        self::assertSame([['App\\Book']], $abstractColl, 'no upcast → only AbstractColl<Book>, nothing extra scheduled');
    }

    public function testDeclaringClassWithASourceParentMakesTheUpcastUnschedulable(): void
    {
        // The implementation lives on a class that itself extends another class, so the covariant edge
        // that would inherit it can't be emitted (single inheritance). The closer must hard-fail rather
        // than emit class-load-fataling code.
        $this->expectException(RuntimeException::class);
        // Assert the code, the erased method name, the interface, and the remedy all appear — pins the
        // diagnostic's content, not just that it threw.
        $this->expectExceptionMessageMatches(
            '/xphp\.unschedulable_covariant_upcast.+erased method "contains".+App\\\\Collection.+'
            . 'already extends another class.+PHP allows one parent.+'
            . 'Provide a concrete implementation.+covariant base class/s',
        );

        $this->compileResult([
            'Product.xphp' => self::PRODUCT,
            'Book.xphp' => self::BOOK,
            'Collection.xphp' => self::COLLECTION_IFACE,
            'Mid.xphp' => "<?php\ndeclare(strict_types=1);\nnamespace App;\nabstract class Mid<+E> {}\n",
            'ListColl.xphp' => <<<'PHP'
                <?php
                declare(strict_types=1);
                namespace App;
                class ListColl<+E> extends Mid<E> implements Collection<E> {
                    /** @var list<mixed> */
                    protected array $items;
                    public function __construct(E ...$items) { $this->items = $items; }
                    public function contains<E2 : E>(E2 $value): bool { return \in_array($value, $this->items, true); }
                }
                PHP,
            'Use.xphp' => <<<'PHP'
                <?php
                declare(strict_types=1);
                namespace App;
                function probe(Collection<Product> $c): bool { return $c->contains::<Product>(new Product()); }
                $l = new ListColl::<Book>(new Book());
                $r = probe($l);
                PHP,
        ]);
    }

    public function testReorderedImplementsClauseMakesTheUpcastUnschedulable(): void
    {
        // The declaring class implements the interface with its parameters REORDERED
        // (`class Holder<+A, B> implements Pair<B, A>`), so the closer cannot derive the implementing
        // specialization from the supertype's args (it would need to invert the mapping). It must
        // hard-fail with the "does not pass its type parameters through" diagnostic, not schedule a
        // wrong specialization.
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches(
            '/xphp\.unschedulable_covariant_upcast.+does not pass its type parameters through to the '
            . 'interface unchanged, so the implementing specialization cannot be derived/s',
        );

        $this->compileResult([
            'Product.xphp' => self::PRODUCT,
            'Book.xphp' => self::BOOK,
            'Id.xphp' => "<?php\ndeclare(strict_types=1);\nnamespace App;\nclass Id {}\n",
            'Pair.xphp' => <<<'PHP'
                <?php
                declare(strict_types=1);
                namespace App;
                interface Pair<K, +V> {
                    public function contains<U : V>(U $value): bool;
                }
                PHP,
            'Holder.xphp' => <<<'PHP'
                <?php
                declare(strict_types=1);
                namespace App;
                class Holder<+A, B> implements Pair<B, A> {
                    /** @var list<mixed> */
                    protected array $items;
                    public function __construct(A ...$items) { $this->items = $items; }
                    public function contains<U : A>(U $value): bool { return \in_array($value, $this->items, true); }
                }
                PHP,
            'Use.xphp' => <<<'PHP'
                <?php
                declare(strict_types=1);
                namespace App;
                function probe(Pair<Id, Product> $p): bool { return $p->contains::<Product>(new Product()); }
                $h = new Holder::<Book, Id>(new Book());
                $r = probe($h);
                PHP,
        ]);
    }

    public function testBareInstanceMethodGenericCallFailsCompile(): void
    {
        // A turbofish-less call to a method generic with no all-default params can't infer its type
        // argument — it must fail compile, not silently emit a call to the stripped `pick_T_<…>`.
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/pick/');

        $this->compile([
            'Box.xphp' => "<?php\ndeclare(strict_types=1);\nnamespace App;\nclass Box { public function pick<T>(T \$x): T { return \$x; } }\n",
            'Use.xphp' => "<?php\ndeclare(strict_types=1);\nnamespace App;\n\$b = new Box();\n\$bad = \$b->pick('b');\n",
        ]);
    }

    public function testBareInstanceMethodGenericCallIsCollectedInCheck(): void
    {
        // The same bare call in `check` mode is collected (not thrown), so a whole-program check reports
        // it instead of a runtime fatal — the gap the ticket is about.
        $collector = $this->check([
            'Box.xphp' => "<?php\ndeclare(strict_types=1);\nnamespace App;\nclass Box { public function pick<T>(T \$x): T { return \$x; } }\n",
            'Use.xphp' => "<?php\ndeclare(strict_types=1);\nnamespace App;\n\$b = new Box();\n\$bad = \$b->pick('b');\n",
        ]);
        $codes = array_map(static fn (Diagnostic $d): string => $d->code, $collector->all());
        self::assertContains(Registry::CODE_MISSING_TYPE_ARGUMENT, $codes);
    }

    public function testBareCallToAnAllDefaultMethodGenericStillResolves(): void
    {
        // A bare call to a method generic whose type parameter is fully defaulted pads to the default and
        // compiles — unchanged behavior, no false positive.
        $dist = $this->compile([
            'Box.xphp' => "<?php\ndeclare(strict_types=1);\nnamespace App;\nclass Box { public function make<T = int>(): string { return 'x'; } }\n",
            'Use.xphp' => "<?php\ndeclare(strict_types=1);\nnamespace App;\n\$b = new Box();\n\$ok = \$b->make();\n",
        ]);
        self::assertStringContainsString('make_', self::read($dist, 'Use.php'));
    }

    public function testBareCallToANonGenericMethodIsUnaffected(): void
    {
        // A plain call to a non-generic method must not be flagged (it isn't a method-generic template).
        $collector = $this->check([
            'Box.xphp' => "<?php\ndeclare(strict_types=1);\nnamespace App;\nclass Box { public function plain(string \$x): string { return \$x; } }\n",
            'Use.xphp' => "<?php\ndeclare(strict_types=1);\nnamespace App;\n\$b = new Box();\n\$ok = \$b->plain('x');\n",
        ]);
        self::assertSame([], $collector->all(), 'a non-generic bare call must not be flagged');
    }

    public function testBareStaticMethodGenericCallIsReported(): void
    {
        // The static path (`Box::pick('b')`) has the identical silent-skip branch — also reported.
        $collector = $this->check([
            'Box.xphp' => "<?php\ndeclare(strict_types=1);\nnamespace App;\nclass Box { public static function pick<T>(T \$x): T { return \$x; } }\n",
            'Use.xphp' => "<?php\ndeclare(strict_types=1);\nnamespace App;\n\$bad = Box::pick('b');\n",
        ]);
        $codes = array_map(static fn (Diagnostic $d): string => $d->code, $collector->all());
        self::assertContains(Registry::CODE_MISSING_TYPE_ARGUMENT, $codes);
    }

    public function testBareFreeFunctionGenericCallFailsCompile(): void
    {
        // The free-function path skipped bare calls via a different early return; a bare call to a
        // generic function must also fail rather than emit a call to the stripped `pick_T_<…>`.
        $this->expectException(RuntimeException::class);
        $this->compile([
            'fns.xphp' => "<?php\ndeclare(strict_types=1);\nnamespace App;\nfunction pick<T>(T \$x): T { return \$x; }\n",
            'Use.xphp' => "<?php\ndeclare(strict_types=1);\nnamespace App;\n\$bad = pick('b');\n",
        ]);
    }

    public function testBareFreeFunctionGenericCallIsCollectedInCheck(): void
    {
        $collector = $this->check([
            'fns.xphp' => "<?php\ndeclare(strict_types=1);\nnamespace App;\nfunction pick<T>(T \$x): T { return \$x; }\n",
            'Use.xphp' => "<?php\ndeclare(strict_types=1);\nnamespace App;\n\$bad = pick('b');\n",
        ]);
        $codes = array_map(static fn (Diagnostic $d): string => $d->code, $collector->all());
        self::assertContains(Registry::CODE_MISSING_TYPE_ARGUMENT, $codes);
    }

    public function testBareNonGenericFreeFunctionCallIsUnaffected(): void
    {
        // A bare call to a non-generic free function (not in functionTemplates) must not be flagged.
        $collector = $this->check([
            'fns.xphp' => "<?php\ndeclare(strict_types=1);\nnamespace App;\nfunction plain(string \$x): string { return \$x; }\n",
            'Use.xphp' => "<?php\ndeclare(strict_types=1);\nnamespace App;\n\$ok = plain('x');\n",
        ]);
        self::assertSame([], $collector->all(), 'a non-generic free-function bare call must not be flagged');
    }

    public function testBareGenericClosureCallIsCollectedInCheck(): void
    {
        // A generic closure is tracked at assignment; a bare `$f('b')` (no turbofish) was skipped via
        // the func-call early return and never reached the variable-turbofish path. It must be reported.
        $collector = $this->check([
            'Use.xphp' => <<<'PHP'
                <?php
                declare(strict_types=1);
                namespace App;
                $f = function<T>(T $x): T { return $x; };
                $bad = $f('b');
                PHP,
        ]);
        $codes = array_map(static fn (Diagnostic $d): string => $d->code, $collector->all());
        self::assertContains(Registry::CODE_MISSING_TYPE_ARGUMENT, $codes);
    }

    public function testBareGenericClosureCallFailsCompile(): void
    {
        $this->expectException(RuntimeException::class);
        $this->compile([
            'Use.xphp' => <<<'PHP'
                <?php
                declare(strict_types=1);
                namespace App;
                $f = function<T>(T $x): T { return $x; };
                $bad = $f('b');
                PHP,
        ]);
    }

    public function testNullsafeForwardedSelfCallIsAlsoRewritten(): void
    {
        // A nullsafe forward (`$this?->contains::<U>()`) is rewritten the same as the plain form.
        $dist = $this->compile([
            'Models.xphp' => self::MODELS,
            'Box.xphp' => <<<'PHP'
            <?php
            declare(strict_types=1);
            namespace App;
            class Box<+E> {
                public function contains<U : E>(U $value): bool { return true; }
                public function probe<U : E>(U $value): bool { return $this?->contains::<U>($value); }
            }
            PHP,
            'Use.xphp' => <<<'PHP'
            <?php
            declare(strict_types=1);
            namespace App;
            $box = new Box::<Fruit>();
            $box->probe::<Banana>(new Banana());
            PHP,
        ]);

        // Compiled with no unspecializable error; the call site lowered to the mangled `probe_`.
        self::assertStringContainsString('probe_', self::read($dist, 'Use.php'));
    }

    public function testErasableForwardingDoesNotReportUnspecializableInCheckMode(): void
    {
        $collector = $this->check([
            'Models.xphp' => self::MODELS,
            'Box.xphp' => self::BOX_FORWARDING,
            'Use.xphp' => <<<'PHP'
            <?php
            declare(strict_types=1);
            namespace App;
            $box = new Box::<Fruit>();
            $box->probe::<Banana>(new Banana());
            PHP,
        ]);

        $codes = array_map(static fn (Diagnostic $d): string => $d->code, $collector->all());
        self::assertNotContains(GenericMethodCompiler::CODE_UNSPECIALIZABLE_SELF_CALL, $codes);
    }

    public function testUnboundedForwardedSelfCallAlsoHardFails(): void
    {
        // Not bound-specific: forwarding to an UNBOUNDED generic method breaks the same way (the inner
        // turbofish is stripped and the method never specialized), so it is rejected too.
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('forwards a type parameter');
        $this->compile([
            'Models.xphp' => self::MODELS,
            'Holder.xphp' => <<<'PHP'
            <?php
            declare(strict_types=1);
            namespace App;
            class Holder {
                public function identity<T>(T $x): T { return $x; }
                public function forward<T>(T $x): T { return $this->identity::<T>($x); }
            }
            PHP,
            'Use.xphp' => <<<'PHP'
            <?php
            declare(strict_types=1);
            namespace App;
            $h = new Holder();
            PHP,
        ]);
    }

    public function testForwardedSelfCallWithArityErrorDoesNotDoubleReport(): void
    {
        // A `$this`-rooted self-call with a too-many-args turbofish must report only the arity error
        // in check mode, not also `unspecializable_self_call` — the arity check short-circuits first.
        $collector = $this->check([
            'Models.xphp' => self::MODELS,
            'Box.xphp' => <<<'PHP'
            <?php
            declare(strict_types=1);
            namespace App;
            class Box<+E> {
                public function contains<U : E>(U $value): bool { return true; }
                public function probe<U : E>(U $value): bool { return $this->contains::<U, U>($value); }
            }
            PHP,
            'Use.xphp' => <<<'PHP'
            <?php
            declare(strict_types=1);
            namespace App;
            $box = new Box::<Fruit>();
            PHP,
        ]);

        $codes = array_map(static fn (Diagnostic $d): string => $d->code, $collector->all());
        self::assertContains(Registry::CODE_TOO_MANY_TYPE_ARGUMENTS, $codes);
        self::assertNotContains(GenericMethodCompiler::CODE_UNSPECIALIZABLE_SELF_CALL, $codes);
    }

    // --- harness ---

    private static function repo(): string
    {
        return <<<'PHP'
        <?php
        declare(strict_types=1);
        namespace App;
        class Repo {
            public function getBox(): Box<Fruit> { return new Box::<Fruit>(); }
        }
        PHP;
    }

    private static function pairUtil(): string
    {
        return <<<'PHP'
        <?php
        declare(strict_types=1);
        namespace App;
        class Util {
            public function pair<U, V : U>(U $a, V $b): bool { return true; }
        }
        PHP;
    }

    private static function box(): string
    {
        return <<<'PHP'
        <?php
        declare(strict_types=1);
        namespace App;
        class Box<+E> {
            public function contains<U : E>(U $value): bool { return true; }
        }
        PHP;
    }

    /**
     * @param array<string, string> $files filename => contents
     * @return string the dist (target) directory
     */
    private function compile(array $files): string
    {
        $src = $this->work . '/' . uniqid('src', true);
        mkdir($src, 0o755, true);
        foreach ($files as $name => $contents) {
            file_put_contents($src . '/' . $name, $contents);
        }
        $dist = $src . '/dist';
        $cache = $src . '/.xphp-cache';

        $phpParser = (new ParserFactory())->createForHostVersion();
        $printer = new StandardPrinter();
        $writer = new NativeFileWriter();
        $compiler = new Compiler(
            new NativeFileReader(),
            $writer,
            new XphpSourceParser($phpParser),
            new Specializer(),
            new SpecializedClassGenerator($printer, $writer),
            $printer,
        );
        $sources = (new NativeFileFinder())->find($src)
            ->filter(static fn (string $f): bool => str_ends_with($f, '.xphp'));
        $compiler->compile($sources, $src, $dist, $cache);

        return $dist;
    }

    /**
     * Like compile(), but returns the CompileResult so a test can inspect which specializations the
     * registry holds (used to assert the specialization closer's effect in-process, where mutation
     * testing can attribute kills — the runtime fixtures run in separate processes).
     *
     * @param array<string, string> $files
     */
    private function compileResult(array $files): CompileResult
    {
        $src = $this->work . '/' . uniqid('src', true);
        mkdir($src, 0o755, true);
        foreach ($files as $name => $contents) {
            file_put_contents($src . '/' . $name, $contents);
        }

        $phpParser = (new ParserFactory())->createForHostVersion();
        $printer = new StandardPrinter();
        $writer = new NativeFileWriter();
        $compiler = new Compiler(
            new NativeFileReader(),
            $writer,
            new XphpSourceParser($phpParser),
            new Specializer(),
            new SpecializedClassGenerator($printer, $writer),
            $printer,
        );
        $sources = (new NativeFileFinder())->find($src)
            ->filter(static fn (string $f): bool => str_ends_with($f, '.xphp'));

        return $compiler->compile($sources, $src, $src . '/dist', $src . '/.xphp-cache');
    }

    /**
     * The concrete type-argument lists recorded for a given template FQN, each as a list of canonical
     * argument strings. Lets a test assert exactly which specializations of a class were scheduled.
     *
     * @return list<list<string>>
     */
    private static function specializationsOf(CompileResult $result, string $templateFqn): array
    {
        $out = [];
        foreach ($result->registry->instantiations() as $instantiation) {
            if ($instantiation->templateFqn === $templateFqn) {
                $out[] = array_map(static fn ($ref): string => $ref->canonical(), $instantiation->concreteTypes);
            }
        }
        return $out;
    }

    /**
     * Run the sources through `check` mode, which collects diagnostics instead of throwing.
     *
     * @param array<string, string> $files filename => contents
     */
    private function check(array $files): DiagnosticCollector
    {
        $src = $this->work . '/' . uniqid('src', true);
        mkdir($src, 0o755, true);
        foreach ($files as $name => $contents) {
            file_put_contents($src . '/' . $name, $contents);
        }

        $phpParser = (new ParserFactory())->createForHostVersion();
        $printer = new StandardPrinter();
        $writer = new NativeFileWriter();
        $compiler = new Compiler(
            new NativeFileReader(),
            $writer,
            new XphpSourceParser($phpParser),
            new Specializer(),
            new SpecializedClassGenerator($printer, $writer),
            $printer,
        );
        $sources = (new NativeFileFinder())->find($src)
            ->filter(static fn (string $f): bool => str_ends_with($f, '.xphp'));

        return $compiler->check($sources);
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
