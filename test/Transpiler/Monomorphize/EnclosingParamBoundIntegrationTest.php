<?php

declare(strict_types=1);

namespace XPHP\Transpiler\Monomorphize;

use PhpParser\ParserFactory;
use PhpParser\PrettyPrinter\Standard as StandardPrinter;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use XPHP\Diagnostics\Diagnostic;
use XPHP\Diagnostics\DiagnosticCollector;
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
