<?php

declare(strict_types=1);

namespace XPHP\Transpiler\Monomorphize;

use PhpParser\ParserFactory;
use PhpParser\PrettyPrinter\Standard as StandardPrinter;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use XPHP\FileSystem\FileFinder\NativeFileFinder;
use XPHP\FileSystem\FileReader\NativeFileReader;
use XPHP\FileSystem\FileWriter\NativeFileWriter;
use XPHP\TestSupport\CompiledFixture;
use XPHP\TestSupport\SnapshotHash;

final class VarianceEdgeIntegrationTest extends TestCase
{
    private string $workDir;
    private string $targetDir;
    private string $cacheDir;

    protected function setUp(): void
    {
        $this->workDir = sys_get_temp_dir() . '/xphp-variance-' . uniqid('', true);
        $this->targetDir = $this->workDir . '/dist';
        $this->cacheDir = $this->workDir . '/.xphp-cache';
        mkdir($this->workDir, 0o755, true);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->workDir)) {
            self::rrmdir($this->workDir);
        }
    }

    #[RunInSeparateProcess]
    public function testCovariantImmutableCollectionTakesTypedConstructorInput(): void
    {
        // A covariant immutable collection `ImmutableList<+T>` with a `T`-typed
        // constructor. The constructor param keeps its REAL element type on each
        // specialization (`Fruit ...` / `Banana ...`) — PHP exempts `__construct`
        // from LSP, so `ImmutableList<Banana>` extends `ImmutableList<Fruit>` with
        // NO autoload fatal, a Banana list is usable where a Fruit list is
        // expected, and construction is runtime-type-checked.
        $fixture = CompiledFixture::compile(
            __DIR__ . '/../../fixture/compile/generic_covariant_immutable_constructor/source',
            'variance-covariant-constructor',
        );
        try {
            $specializationDir = $fixture->cacheDir . '/Generated/App/CovariantConstructor/ImmutableList';
            $files = glob($specializationDir . '/T_*.php') ?: [];
            self::assertCount(2, $files, 'two ImmutableList specializations (Fruit, Banana)');

            $combined = '';
            $extendsEdges = 0;
            foreach ($files as $file) {
                $content = file_get_contents($file);
                self::assertIsString($content);
                $combined .= $content;
                if (str_contains($content, 'extends \\XPHP\\Generated\\App\\CovariantConstructor\\ImmutableList\\T_')) {
                    $extendsEdges++;
                }
            }
            // Each specialization keeps its REAL element type in the constructor.
            self::assertSame(
                1,
                preg_match_all('/function __construct\(\\\\App\\\\CovariantConstructor\\\\Fruit \.\.\.\$items\)/', $combined),
                'Fruit specialization constructor keeps `Fruit ...$items`',
            );
            self::assertSame(
                1,
                preg_match_all('/function __construct\(\\\\App\\\\CovariantConstructor\\\\Banana \.\.\.\$items\)/', $combined),
                'Banana specialization constructor keeps `Banana ...$items`',
            );
            self::assertStringNotContainsString('mixed ...$items', $combined, 'nothing is erased to mixed');
            // Exactly one specialization extends the other — the covariant edge.
            self::assertSame(1, $extendsEdges, 'ImmutableList<Banana> extends ImmutableList<Fruit>');
            // `final` is stripped from variant-class specializations so the edge's
            // parent isn't a final class (which would PHP-fatal at autoload).
            self::assertStringNotContainsString('final class', $combined);

            $fixture->registerAutoload('App\\CovariantConstructor');
            require __DIR__ . '/../../fixture/compile/generic_covariant_immutable_constructor/verify/runtime.php';
        } finally {
            $fixture->cleanup();
        }
    }

    public function testBoundedCovariantConstructorKeepsConcreteType(): void
    {
        // A bounded covariant constructor param keeps its REAL substituted type (the
        // concrete arg, not the bound and not `mixed`) — constructors are LSP-exempt.
        $generated = $this->compileFixtureAndReadGenerated('compile/generic_covariant_bounded_constructor/source');
        self::assertStringContainsString('__construct(\\App\\BoundConstructor\\Tag ...$items)', $generated);
        self::assertStringNotContainsString('__construct(mixed', $generated);
        self::assertStringNotContainsString('__construct(\\Stringable', $generated);
    }

    public function testMixedVarianceConstructorKeepsConcreteTypes(): void
    {
        // `Pair<+A, B>`: the covariant `A` constructor param keeps its concrete type, the
        // invariant `B` param keeps its concrete substituted type, and a plain
        // scalar param (`int $tag`) is left untouched (it isn't a type-param).
        $generated = $this->compileFixtureAndReadGenerated('compile/generic_mixed_variance_constructor/source');
        self::assertMatchesRegularExpression('/__construct\(\\\\App\\\\MixedConstructor\\\\Apple \$a, \\\\App\\\\MixedConstructor\\\\Apple \$b, int \$tag\)/', $generated);
        self::assertStringNotContainsString('mixed $a', $generated);
    }

    public function testContravariantConstructorParamKeepsConcreteType(): void
    {
        // Symmetry with the covariant case: a `-T` constructor param keeps its real type
        // too, and the contravariant edge (Consumer<Fruit> extends Consumer<Banana>)
        // stays valid because constructors are LSP-exempt.
        $generated = $this->compileFixtureAndReadGenerated('compile/generic_contravariant_constructor/source');
        self::assertSame(1, preg_match_all('/function __construct\(\\\\App\\\\ContravariantConstructor\\\\Banana \.\.\.\$items\)/', $generated));
        self::assertSame(1, preg_match_all('/function __construct\(\\\\App\\\\ContravariantConstructor\\\\Fruit \.\.\.\$items\)/', $generated));
        self::assertStringNotContainsString('mixed ...$items', $generated);
        self::assertStringContainsString('extends \\XPHP\\Generated\\App\\ContravariantConstructor\\Consumer\\T_', $generated);
    }

    public function testNonBareVariantConstructorParamShapesAreRejected(): void
    {
        // Only a *bare* variance-marked type-param is currently supported in a
        // constructor parameter. Richer shapes are still rejected by the
        // inner-variance check (not yet supported in constructor position):
        //   - `?T`        — nullable, not a bare Name
        //   - `Box<T>`    — T through another generic's invariant slot
        //   - `(T $a, ?T $b)` — the allowed leading `T` must not stop the walk from
        //                       reaching the bad trailing `?T`
        $fixtures = [
            'check/variance_constructor_nullable/source',
            'check/variance_constructor_nested_generic/source',
            'check/variance_constructor_mixed_params/source',
        ];
        foreach ($fixtures as $fixture) {
            $this->compileFixtureExpectingVarianceViolation($fixture);
        }
    }

    public function testTwoCovariantParamsBothKeepConcreteTypes(): void
    {
        // Two covariant params: BOTH `T`-typed constructor params keep their real
        // substituted types (pins that nothing is erased for any variant constructor param).
        $generated = $this->compileFixtureAndReadGenerated('compile/generic_two_covariant_constructor/source');
        self::assertSame(1, preg_match_all('/__construct\(\\\\App\\\\TwoConstructor\\\\Apple \$a, \\\\App\\\\TwoConstructor\\\\Apple \$b\)/', $generated));
        self::assertStringNotContainsString('mixed $a', $generated);
    }

    public function testInvariantClassConstructorIsNotErasedAndKeepsFinal(): void
    {
        // An invariant class is not variance-erased (constructor param keeps its concrete
        // type) and its `final` modifier is preserved (no edges → no LSP hazard).
        $generated = $this->compileFixtureAndReadGenerated('compile/generic_invariant_constructor/source');
        self::assertStringContainsString('final class', $generated);
        self::assertStringContainsString('App\\InvariantConstructor\\Apple $item', $generated);
        self::assertStringNotContainsString('mixed $item', $generated);
    }

    public function testCovariantSubtypeEdgeIsEmittedAsExtendsForClassSpecializations(): void
    {
        // Fixture: `variance_covariant_happy/`. Producer<+T>, Banana <: Fruit.
        // Two specializations; Producer_Banana extends Producer_Fruit because
        // +T is covariant.
        $sourceDir = realpath(__DIR__ . '/../../fixture/compile/variance_covariant_happy/source')
            ?: throw new RuntimeException('Fixture missing');
        $compiler = $this->buildCompiler();
        $sources = (new NativeFileFinder())->find($sourceDir)
            ->filter(static fn (string $f): bool => str_ends_with($f, '.xphp'));

        $result = $compiler->compile($sources, $sourceDir, $this->targetDir, $this->cacheDir);

        self::assertSame(2, $result->generatedCount);
        $fruitFqn = Registry::generatedFqn(
            'App\\VarianceCovariantHappy\\Containers\\Producer',
            [new TypeRef('App\\VarianceCovariantHappy\\Models\\Fruit')],
        );
        $bananaFqn = Registry::generatedFqn(
            'App\\VarianceCovariantHappy\\Containers\\Producer',
            [new TypeRef('App\\VarianceCovariantHappy\\Models\\Banana')],
        );
        $bananaFile = $this->fqnToPath($bananaFqn);
        self::assertFileExists($bananaFile);
        $bananaContent = file_get_contents($bananaFile);

        // SnapshotHash::normalize() renames hashes in first-seen byte order.
        // A regression that re-targeted the `extends` to the *wrong*
        // specialization wouldn't shift first-seen order, so the snapshot
        // alone could miss it. The explicit FQN-substring check below
        // pins the parent identity; the snapshot pins everything else.
        self::assertStringContainsString('extends \\' . $fruitFqn, $bananaContent);
        SnapshotHash::assertMatches(
            __DIR__ . '/../../fixture/compile/variance_covariant_happy/verify/testCovariantSubtypeEdgeIsEmittedAsExtendsForClassSpecializations/Producer_Banana.expected.php',
            $bananaContent,
        );
    }

    public function testContravariantSubtypeEdgeFlipsDirection(): void
    {
        // Fixture: `variance_contravariant_happy/`. Consumer<-T>, Dog <: Animal.
        // With contravariance, the edge flips: Consumer_Animal extends
        // Consumer_Dog (not the other way around).
        $sourceDir = realpath(__DIR__ . '/../../fixture/compile/variance_contravariant_happy/source')
            ?: throw new RuntimeException('Fixture missing');
        $compiler = $this->buildCompiler();
        $sources = (new NativeFileFinder())->find($sourceDir)
            ->filter(static fn (string $f): bool => str_ends_with($f, '.xphp'));

        $result = $compiler->compile($sources, $sourceDir, $this->targetDir, $this->cacheDir);

        self::assertSame(2, $result->generatedCount);
        $animalFqn = Registry::generatedFqn(
            'App\\VarianceContravariantHappy\\Containers\\Consumer',
            [new TypeRef('App\\VarianceContravariantHappy\\Models\\Animal')],
        );
        $dogFqn = Registry::generatedFqn(
            'App\\VarianceContravariantHappy\\Containers\\Consumer',
            [new TypeRef('App\\VarianceContravariantHappy\\Models\\Dog')],
        );
        $animalContent = file_get_contents($this->fqnToPath($animalFqn));

        // Pin the parent identity (flipped from covariance: Animal extends Dog).
        self::assertStringContainsString('extends \\' . $dogFqn, $animalContent);
        SnapshotHash::assertMatches(
            __DIR__ . '/../../fixture/compile/variance_contravariant_happy/verify/testContravariantSubtypeEdgeFlipsDirection/Consumer_Animal.expected.php',
            $animalContent,
        );
    }

    public function testEmittedSubtypeChainAutoloadsWithoutPhpFatal(): void
    {
        // The critical safety test: PHP applies LSP signature compat at
        // autoload time. If the variance edge emission produces an incompatible
        // method signature, PHP fatals with "Declaration of X::m must be
        // compatible with Y::m". Spawn a subprocess that requires every emitted
        // file for the covariant fixture; non-zero exit means a fatal at load.
        $sourceDir = realpath(__DIR__ . '/../../fixture/compile/variance_covariant_happy/source')
            ?: throw new RuntimeException('Fixture missing');
        $compiler = $this->buildCompiler();
        $sources = (new NativeFileFinder())->find($sourceDir)
            ->filter(static fn (string $f): bool => str_ends_with($f, '.xphp'));
        $compiler->compile($sources, $sourceDir, $this->targetDir, $this->cacheDir);

        $loader = $this->writeAutoloadCheck(
            'App\\VarianceCovariantHappy',
            [
                'Containers/Producer.php' => 'Producer',
            ],
            [
                'Models/Fruit.php',
                'Models/Banana.php',
            ],
        );

        $output = [];
        $exitCode = 0;
        exec('php ' . escapeshellarg($loader) . ' 2>&1', $output, $exitCode);
        self::assertSame(
            0,
            $exitCode,
            "Autoload-time fatal:\n" . implode("\n", $output),
        );
        self::assertContains('OK', $output);
    }

    public function testContravariantConstructorChainAutoloadsAndConstructsWithoutPhpFatal(): void
    {
        // The CONTRAVARIANT counterpart of the autoload proof, with a real-typed
        // constructor. The edge flips: `Consumer<Fruit>` extends `Consumer<Banana>`,
        // so the child constructor (`Fruit ...$items`) WIDENS the parent's (`Banana ...`).
        // PHP exempts `__construct` from LSP, so the chain must both autoload AND
        // construct instances of each specialization without a fatal — empirically
        // confirming the same exemption holds in the contravariant direction.
        $src = realpath(__DIR__ . '/../../fixture/compile/generic_contravariant_constructor/source')
            ?: throw new RuntimeException('Fixture missing');
        $compiler = $this->buildCompiler();
        $sources = (new NativeFileFinder())->find($src)
            ->filter(static fn (string $f): bool => str_ends_with($f, '.xphp'));
        $compiler->compile($sources, $src, $this->targetDir, $this->cacheDir);

        $bananaFqn = Registry::generatedFqn('App\\ContravariantConstructor\\Consumer', [new TypeRef('App\\ContravariantConstructor\\Banana')]);
        $fruitFqn = Registry::generatedFqn('App\\ContravariantConstructor\\Consumer', [new TypeRef('App\\ContravariantConstructor\\Fruit')]);
        $prefixes = [
            'XPHP\\Generated\\' => $this->cacheDir . '/Generated',
            'App\\ContravariantConstructor\\' => $this->targetDir,
        ];

        $loader = $this->workDir . '/contra-load.php';
        $script = "<?php\n"
            . "spl_autoload_register(function (string \$c): void {\n"
            . "    foreach (" . var_export($prefixes, true) . " as \$p => \$base) {\n"
            . "        if (str_starts_with(\$c, \$p)) {\n"
            . "            \$f = \$base . '/' . str_replace('\\\\', '/', substr(\$c, strlen(\$p))) . '.php';\n"
            . "            if (is_file(\$f)) { require_once \$f; }\n"
            . "        }\n"
            . "    }\n"
            . "});\n"
            . "new (" . var_export($bananaFqn, true) . ")(new \\App\\ContravariantConstructor\\Banana());\n"
            . "new (" . var_export($fruitFqn, true) . ")(new \\App\\ContravariantConstructor\\Fruit());\n"
            . "echo \"OK\\n\";\n";
        file_put_contents($loader, $script);

        $output = [];
        $exitCode = 0;
        exec('php ' . escapeshellarg($loader) . ' 2>&1', $output, $exitCode);
        self::assertSame(0, $exitCode, "Contravariant constructor chain fataled:\n" . implode("\n", $output));
        self::assertContains('OK', $output);
    }

    public function testNoEdgeBetweenUnrelatedSpecializations(): void
    {
        // Banana and Apple both extend Fruit but not each other. The edge
        // between Producer_Banana and Producer_Apple must NOT be emitted (no
        // PHP-level subtype relationship between Banana and Apple).
        $sourceDir = $this->workDir . '/src-unrelated';
        mkdir($sourceDir . '/Containers', 0o755, true);
        mkdir($sourceDir . '/Models', 0o755, true);
        file_put_contents($sourceDir . '/Containers/Producer.xphp', <<<'PHP'
        <?php
        namespace App\Containers;
        class Producer<+T>
        {
            public function get(): T { throw new \LogicException; }
        }
        PHP);
        file_put_contents($sourceDir . '/Models/Fruit.xphp', <<<'PHP'
        <?php
        namespace App\Models;
        class Fruit {}
        PHP);
        file_put_contents($sourceDir . '/Models/Banana.xphp', <<<'PHP'
        <?php
        namespace App\Models;
        class Banana extends Fruit {}
        PHP);
        file_put_contents($sourceDir . '/Models/Apple.xphp', <<<'PHP'
        <?php
        namespace App\Models;
        class Apple extends Fruit {}
        PHP);
        file_put_contents($sourceDir . '/Use.xphp', <<<'PHP'
        <?php
        namespace App;
        $b = new Containers\Producer::<Models\Banana>;
        $a = new Containers\Producer::<Models\Apple>;
        PHP);

        $compiler = $this->buildCompiler();
        $sources = (new NativeFileFinder())->find($sourceDir)
            ->filter(static fn (string $f): bool => str_ends_with($f, '.xphp'));
        $compiler->compile($sources, $sourceDir, $this->targetDir, $this->cacheDir);

        $bananaFqn = Registry::generatedFqn(
            'App\\Containers\\Producer',
            [new TypeRef('App\\Models\\Banana')],
        );
        $appleFqn = Registry::generatedFqn(
            'App\\Containers\\Producer',
            [new TypeRef('App\\Models\\Apple')],
        );
        $bananaContent = file_get_contents($this->fqnToPath($bananaFqn));
        $appleContent = file_get_contents($this->fqnToPath($appleFqn));

        // Negative invariants kept: neither specialization may reference
        // the other (no PHP-level subtype between Banana and Apple).
        self::assertStringNotContainsString($appleFqn, $bananaContent);
        self::assertStringNotContainsString($bananaFqn, $appleContent);
        $snapshotDir = __DIR__ . '/VarianceEdgeIntegrationTest/testNoEdgeBetweenUnrelatedSpecializations';
        SnapshotHash::assertMatches($snapshotDir . '/Producer_Banana.expected.php', $bananaContent);
        SnapshotHash::assertMatches($snapshotDir . '/Producer_Apple.expected.php', $appleContent);
    }

    public function testScalarArgsSkipVarianceEdgeEmission(): void
    {
        // `Producer<+T>` instantiated with int and string -- no PHP-level
        // subtype relationship between scalars, so no edge is emitted in
        // either direction.
        $sourceDir = $this->workDir . '/src-scalar';
        mkdir($sourceDir, 0o755, true);
        file_put_contents($sourceDir . '/Producer.xphp', <<<'PHP'
        <?php
        namespace App;
        class Producer<+T>
        {
            public function get(): T { throw new \LogicException; }
        }
        PHP);
        file_put_contents($sourceDir . '/Use.xphp', <<<'PHP'
        <?php
        namespace App;
        $i = new Producer::<int>;
        $s = new Producer::<string>;
        PHP);

        $compiler = $this->buildCompiler();
        $sources = (new NativeFileFinder())->find($sourceDir)
            ->filter(static fn (string $f): bool => str_ends_with($f, '.xphp'));
        $compiler->compile($sources, $sourceDir, $this->targetDir, $this->cacheDir);

        $intFqn = Registry::generatedFqn(
            'App\\Producer',
            [new TypeRef('int', isScalar: true)],
        );
        $stringFqn = Registry::generatedFqn(
            'App\\Producer',
            [new TypeRef('string', isScalar: true)],
        );
        $intContent = file_get_contents($this->fqnToPath($intFqn));
        $stringContent = file_get_contents($this->fqnToPath($stringFqn));

        // Negative invariants kept: scalars have no PHP-level subtype
        // relationship, so neither specialization may reference the other.
        self::assertStringNotContainsString($stringFqn, $intContent);
        self::assertStringNotContainsString($intFqn, $stringContent);
        $snapshotDir = __DIR__ . '/VarianceEdgeIntegrationTest/testScalarArgsSkipVarianceEdgeEmission';
        SnapshotHash::assertMatches($snapshotDir . '/Producer_int.expected.php', $intContent);
        SnapshotHash::assertMatches($snapshotDir . '/Producer_string.expected.php', $stringContent);
    }

    public function testTransitiveEdgesCollapseToDirectParent(): void
    {
        // Banana <: Apple <: Fruit. Three specializations of Producer<+T>.
        // The variance edges form a chain: Producer_Banana extends Producer_Apple,
        // Producer_Apple extends Producer_Fruit. Producer_Banana does NOT need a
        // direct edge to Producer_Fruit (PHP resolves it transitively).
        $sourceDir = $this->workDir . '/src-transitive';
        mkdir($sourceDir, 0o755, true);
        file_put_contents($sourceDir . '/P.xphp', <<<'PHP'
        <?php
        namespace App;
        class P<+T> { public function get(): T { throw new \LogicException; } }
        PHP);
        file_put_contents($sourceDir . '/Fruit.xphp', <<<'PHP'
        <?php
        namespace App;
        class Fruit {}
        PHP);
        file_put_contents($sourceDir . '/Apple.xphp', <<<'PHP'
        <?php
        namespace App;
        class Apple extends Fruit {}
        PHP);
        file_put_contents($sourceDir . '/Banana.xphp', <<<'PHP'
        <?php
        namespace App;
        class Banana extends Apple {}
        PHP);
        file_put_contents($sourceDir . '/Use.xphp', <<<'PHP'
        <?php
        namespace App;
        $f = new P::<Fruit>;
        $a = new P::<Apple>;
        $b = new P::<Banana>;
        PHP);

        $compiler = $this->buildCompiler();
        $sources = (new NativeFileFinder())->find($sourceDir)
            ->filter(static fn (string $f): bool => str_ends_with($f, '.xphp'));
        $compiler->compile($sources, $sourceDir, $this->targetDir, $this->cacheDir);

        $fruitFqn = Registry::generatedFqn('App\\P', [new TypeRef('App\\Fruit')]);
        $appleFqn = Registry::generatedFqn('App\\P', [new TypeRef('App\\Apple')]);
        $bananaFqn = Registry::generatedFqn('App\\P', [new TypeRef('App\\Banana')]);

        $bananaContent = file_get_contents($this->fqnToPath($bananaFqn));
        $appleContent = file_get_contents($this->fqnToPath($appleFqn));

        // Pin parent identities (the snapshot's first-seen-order
        // normalization can't tell apart hash swaps within a file).
        self::assertStringContainsString('extends \\' . $appleFqn, $bananaContent);
        self::assertStringContainsString('extends \\' . $fruitFqn, $appleContent);
        // Negative invariant kept: Banana does NOT inherit transitively.
        self::assertStringNotContainsString('extends \\' . $fruitFqn, $bananaContent);

        $snapshotDir = __DIR__ . '/VarianceEdgeIntegrationTest/testTransitiveEdgesCollapseToDirectParent';
        SnapshotHash::assertMatches($snapshotDir . '/P_Banana.expected.php', $bananaContent);
        SnapshotHash::assertMatches($snapshotDir . '/P_Apple.expected.php', $appleContent);
    }

    public function testAllThreeFeaturesCompose(): void
    {
        // Fixture: `variance_with_defaults_and_bounds/`. `Cache<+K : Stringable
        // & Countable, V = mixed>` composes covariance + intersection bound
        // + default. Verifies the integration: parse succeeds, bound is
        // checked, default pads, variance edges emit (single specialization
        // here -- no edge yet, but the pipeline runs cleanly).
        $sourceDir = realpath(__DIR__ . '/../../fixture/compile/variance_with_defaults_and_bounds/source')
            ?: throw new RuntimeException('Fixture missing');
        $compiler = $this->buildCompiler();
        $sources = (new NativeFileFinder())->find($sourceDir)
            ->filter(static fn (string $f): bool => str_ends_with($f, '.xphp'));

        $result = $compiler->compile($sources, $sourceDir, $this->targetDir, $this->cacheDir);

        self::assertSame(1, $result->generatedCount);
        // Defaulted V pads to mixed in the specialization.
        $cacheFqn = Registry::generatedFqn(
            'App\\VarianceWithDefaultsAndBounds\\Containers\\Cache',
            [
                new TypeRef('App\\VarianceWithDefaultsAndBounds\\Models\\Tag'),
                new TypeRef('mixed', isScalar: true),
            ],
        );
        self::assertFileExists($this->fqnToPath($cacheFqn));
    }

    public function testInterfaceSpecializationsGetMultiExtendsButFilterTransitives(): void
    {
        // Interface_ template with covariant +T. Banana <: Apple <: Fruit.
        // Each specialization's `extends` list must contain ONLY direct
        // supers, not transitively-implied ones -- so Banana's interface
        // extends [Apple] only, NOT [Apple, Fruit]. The filter-direct-supers
        // pass is exercised here on a multi-extends path that single-extends
        // Class_ tests don't cover.
        $sourceDir = $this->workDir . '/src-interface-transitive';
        mkdir($sourceDir, 0o755, true);
        file_put_contents($sourceDir . '/IProducer.xphp', <<<'PHP'
        <?php
        namespace App;
        interface IProducer<+T> { public function get(): T; }
        PHP);
        file_put_contents($sourceDir . '/Fruit.xphp', <<<'PHP'
        <?php
        namespace App;
        class Fruit {}
        PHP);
        file_put_contents($sourceDir . '/Apple.xphp', <<<'PHP'
        <?php
        namespace App;
        class Apple extends Fruit {}
        PHP);
        file_put_contents($sourceDir . '/Banana.xphp', <<<'PHP'
        <?php
        namespace App;
        class Banana extends Apple {}
        PHP);
        file_put_contents($sourceDir . '/Use.xphp', <<<'PHP'
        <?php
        namespace App;
        // Just reference the IProducer specializations to register them.
        function consume(IProducer::<Fruit> $f, IProducer::<Apple> $a, IProducer::<Banana> $b): void {}
        PHP);

        $compiler = $this->buildCompiler();
        $sources = (new NativeFileFinder())->find($sourceDir)
            ->filter(static fn (string $f): bool => str_ends_with($f, '.xphp'));
        $compiler->compile($sources, $sourceDir, $this->targetDir, $this->cacheDir);

        $fruitFqn = Registry::generatedFqn('App\\IProducer', [new TypeRef('App\\Fruit')]);
        $appleFqn = Registry::generatedFqn('App\\IProducer', [new TypeRef('App\\Apple')]);
        $bananaFqn = Registry::generatedFqn('App\\IProducer', [new TypeRef('App\\Banana')]);

        $bananaContent = file_get_contents($this->fqnToPath($bananaFqn));
        $appleContent = file_get_contents($this->fqnToPath($appleFqn));

        // Pin parent identities for both interface specializations.
        self::assertStringContainsString($appleFqn, $bananaContent);
        self::assertStringContainsString($fruitFqn, $appleContent);
        // Negative invariant kept: Banana must NOT extend Fruit transitively.
        self::assertStringNotContainsString($fruitFqn, $bananaContent);

        $snapshotDir = __DIR__ . '/VarianceEdgeIntegrationTest/testInterfaceSpecializationsGetMultiExtendsButFilterTransitives';
        SnapshotHash::assertMatches($snapshotDir . '/IProducer_Banana.expected.php', $bananaContent);
        SnapshotHash::assertMatches($snapshotDir . '/IProducer_Apple.expected.php', $appleContent);
    }

    public function testInvariantTemplateProducesNoVarianceEdges(): void
    {
        // Pure invariant template -- no `+` / `-` markers, so the edge emitter
        // short-circuits and no specialization gets an extra extends.
        $sourceDir = $this->workDir . '/src-invariant';
        mkdir($sourceDir, 0o755, true);
        file_put_contents($sourceDir . '/Box.xphp', <<<'PHP'
        <?php
        namespace App;
        class Box<T>
        {
            public T $item;
        }
        PHP);
        file_put_contents($sourceDir . '/Fruit.xphp', <<<'PHP'
        <?php
        namespace App;
        class Fruit {}
        PHP);
        file_put_contents($sourceDir . '/Banana.xphp', <<<'PHP'
        <?php
        namespace App;
        class Banana extends Fruit {}
        PHP);
        file_put_contents($sourceDir . '/Use.xphp', <<<'PHP'
        <?php
        namespace App;
        $f = new Box::<Fruit>;
        $b = new Box::<Banana>;
        PHP);

        $compiler = $this->buildCompiler();
        $sources = (new NativeFileFinder())->find($sourceDir)
            ->filter(static fn (string $f): bool => str_ends_with($f, '.xphp'));
        $compiler->compile($sources, $sourceDir, $this->targetDir, $this->cacheDir);

        $fruitFqn = Registry::generatedFqn('App\\Box', [new TypeRef('App\\Fruit')]);
        $bananaFqn = Registry::generatedFqn('App\\Box', [new TypeRef('App\\Banana')]);
        $bananaContent = file_get_contents($this->fqnToPath($bananaFqn));

        // Negative invariant kept: invariant template -- no variance edges.
        self::assertStringNotContainsString($fruitFqn, $bananaContent);
        SnapshotHash::assertMatches(
            __DIR__ . '/VarianceEdgeIntegrationTest/testInvariantTemplateProducesNoVarianceEdges/Box_Banana.expected.php',
            $bananaContent,
        );
    }

    /**
     * Build a PHP script that registers an autoloader mapping FQNs to file
     * paths, then references one specialization of each kind. The autoloader
     * resolves on-demand, so the parent-before-child file order doesn't have
     * to be hardcoded.
     *
     * @param array<string, string> $markerInterfaces map of "relative/path.php" => interfaceShortName
     * @param list<string> $modelFiles relative paths
     */
    private function writeAutoloadCheck(
        string $appNamespace,
        array $markerInterfaces,
        array $modelFiles,
    ): string {
        $loader = $this->workDir . '/load.php';
        $body = "<?php\n\n";

        // Build the FQN-to-path map for the autoloader.
        $map = [];
        // Marker interfaces.
        foreach ($markerInterfaces as $relPath => $shortName) {
            $fqn = $appNamespace . '\\Containers\\' . $shortName;
            $map[$fqn] = $this->targetDir . '/' . $relPath;
        }
        // Model classes -- derive FQN from the file path.
        foreach ($modelFiles as $relPath) {
            $base = basename($relPath, '.php');
            $fqn = $appNamespace . '\\Models\\' . $base;
            $map[$fqn] = $this->targetDir . '/' . $relPath;
        }
        // Specialized classes under .xphp-cache/Generated.
        $generatedDir = $this->cacheDir . '/Generated';
        $specializedFqns = [];
        if (is_dir($generatedDir)) {
            $iter = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($generatedDir));
            foreach ($iter as $file) {
                if ($file->isFile() && str_ends_with($file->getFilename(), '.php')) {
                    // FQN: XPHP\Generated + relative dir + class name (basename without .php).
                    $relPath = substr($file->getPathname(), strlen($generatedDir) + 1);
                    $fqn = 'XPHP\\Generated\\' . str_replace('/', '\\', substr($relPath, 0, -4));
                    $map[$fqn] = $file->getPathname();
                    $specializedFqns[] = $fqn;
                }
            }
        }

        $body .= "spl_autoload_register(function (string \$class): void {\n";
        $body .= "    \$map = " . var_export($map, true) . ";\n";
        $body .= "    if (isset(\$map[\$class])) { require_once \$map[\$class]; }\n";
        $body .= "});\n\n";

        // Reference each specialized class to force autoload.
        foreach ($specializedFqns as $fqn) {
            $body .= "class_exists(" . var_export($fqn, true) . ");\n";
        }
        $body .= "echo \"OK\\n\";\n";
        file_put_contents($loader, $body);
        return $loader;
    }

    /**
     * Compile a tracked fixture's `source/` dir and return the concatenated text of
     * every generated specialization, for asserting on emitted constructor signatures.
     *
     * @param string $relFixtureDir path under `test/fixture/`, e.g. `compile/foo/source`
     */
    private function compileFixtureAndReadGenerated(string $relFixtureDir): string
    {
        $src = realpath(__DIR__ . '/../../fixture/' . $relFixtureDir)
            ?: throw new RuntimeException("missing fixture: {$relFixtureDir}");
        $compiler = $this->buildCompiler();
        $sources = (new NativeFileFinder())->find($src)
            ->filter(static fn (string $f): bool => str_ends_with($f, '.xphp'));
        $compiler->compile($sources, $src, $this->targetDir, $this->cacheDir);

        $generatedDir = $this->cacheDir . '/Generated';
        if (!is_dir($generatedDir)) {
            return '';
        }
        $out = '';
        $iter = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($generatedDir));
        foreach ($iter as $file) {
            if ($file->isFile() && str_ends_with($file->getFilename(), '.php')) {
                $out .= file_get_contents($file->getPathname()) . "\n";
            }
        }
        return $out;
    }

    /**
     * Compile a tracked fixture's `source/` dir and assert it raises a variance
     * violation (compile-mode, fail-fast).
     *
     * @param string $relFixtureDir path under `test/fixture/`, e.g. `check/foo/source`
     */
    private function compileFixtureExpectingVarianceViolation(string $relFixtureDir): void
    {
        $src = realpath(__DIR__ . '/../../fixture/' . $relFixtureDir)
            ?: throw new RuntimeException("missing fixture: {$relFixtureDir}");
        $dir = sys_get_temp_dir() . '/xphp-iv-' . uniqid('', true);
        mkdir($dir, 0o755, true);
        $compiler = $this->buildCompiler();
        $sources = (new NativeFileFinder())->find($src)
            ->filter(static fn (string $f): bool => str_ends_with($f, '.xphp'));
        try {
            $compiler->compile($sources, $src, $dir . '/dist', $dir . '/cache');
            self::fail('expected a variance violation, none thrown');
        } catch (RuntimeException $e) {
            self::assertStringContainsString('Variance violation', $e->getMessage());
        } finally {
            self::rrmdir($dir);
        }
    }

    private function fqnToPath(string $fqn): string
    {
        $prefix = Registry::GENERATED_NAMESPACE_PREFIX . '\\';
        $rel = str_starts_with($fqn, $prefix) ? substr($fqn, strlen($prefix)) : $fqn;
        return $this->cacheDir . '/Generated/' . str_replace('\\', '/', $rel) . '.php';
    }

    private function buildCompiler(): Compiler
    {
        $phpParser = (new ParserFactory())->createForHostVersion();
        $printer = new StandardPrinter();
        $writer = new NativeFileWriter();

        return new Compiler(
            new NativeFileReader(),
            $writer,
            new XphpSourceParser($phpParser),
            new Specializer(),
            new SpecializedClassGenerator($printer, $writer),
            $printer,
        );
    }

    private static function rrmdir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            is_dir($path) ? self::rrmdir($path) : unlink($path);
        }
        rmdir($dir);
    }
}
