<?php

declare(strict_types=1);

namespace XPHP\Transpiler\Monomorphize;

use PhpParser\ParserFactory;
use PhpParser\PrettyPrinter\Standard as StandardPrinter;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use XPHP\FileSystem\FileFinder\NativeFileFinder;
use XPHP\FileSystem\FilepathArray;
use XPHP\FileSystem\FileReader\NativeFileReader;
use XPHP\FileSystem\FileWriter\NativeFileWriter;
use XPHP\TestSupport\CompiledFixture;
use XPHP\TestSupport\SnapshotHash;

final class BoundedGenericIntegrationTest extends TestCase
{
    private string $workDir;
    private string $targetDir;
    private string $cacheDir;

    protected function setUp(): void
    {
        $this->workDir = sys_get_temp_dir() . '/xphp-bounds-' . uniqid('', true);
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

    public function testBoundIsSatisfiedByImplementingClass(): void
    {
        $sourceDir = realpath(__DIR__ . '/../../fixture/compile/bounds_happy/source')
            ?: throw new RuntimeException('Fixture missing');
        $compiler = $this->buildCompiler();
        $sources = (new NativeFileFinder())->find($sourceDir)
            ->filter(static fn (string $f): bool => str_ends_with($f, '.xphp'));

        $result = $compiler->compile($sources, $sourceDir, $this->targetDir, $this->cacheDir);

        $boxFqn = Registry::generatedFqn('App\\BoundsHappy\\Containers\\Box', [new TypeRef('App\\BoundsHappy\\Models\\Tag')]);
        $boxFile = $this->fqnToPath($boxFqn);
        self::assertFileExists($boxFile, 'Box<Tag> must specialize when Tag implements \\Stringable (bound satisfied via hierarchy)');

        SnapshotHash::assertMatches(
            __DIR__ . '/../../fixture/compile/bounds_happy/verify/testBoundIsSatisfiedByImplementingClass/Box.expected.php',
            file_get_contents($boxFile),
        );

        self::assertGreaterThan(0, $result->generatedCount);
    }

    public function testBoundViolationOnScalarConcreteFailsCompilationWithClearMessage(): void
    {
        $sourceDir = $this->workDir . '/src';
        mkdir($sourceDir, 0o755, true);
        $boxFile = $sourceDir . '/Box.xphp';
        file_put_contents($boxFile, <<<'PHP'
        <?php
        namespace App;
        class Box<T: \Stringable>
        {
            public T $item;
        }
        PHP);
        $useFile = $sourceDir . '/Use.xphp';
        file_put_contents($useFile, <<<'PHP'
        <?php
        namespace App;
        $x = new Box::<int>();
        PHP);

        $compiler = $this->buildCompiler();
        $sources = new FilepathArray($boxFile, $useFile);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Generic bound violated');
        $this->expectExceptionMessage('int');
        $this->expectExceptionMessage('Stringable');
        $compiler->compile($sources, $sourceDir, $this->targetDir, $this->cacheDir);
    }

    public function testBoundViolationOnUnknownClassFailsCompilation(): void
    {
        $sourceDir = $this->workDir . '/src';
        mkdir($sourceDir, 0o755, true);
        $boxFile = $sourceDir . '/Box.xphp';
        file_put_contents($boxFile, <<<'PHP'
        <?php
        namespace App;
        class Box<T: \Stringable>
        {
            public T $item;
        }
        PHP);
        $useFile = $sourceDir . '/Use.xphp';
        file_put_contents($useFile, <<<'PHP'
        <?php
        namespace App;
        // Unknown\Thing isn't in any source file and isn't a built-in PHP type,
        // so the hierarchy can't prove it satisfies \Stringable.
        $x = new Box::<Unknown\Thing>();
        PHP);

        $compiler = $this->buildCompiler();
        $sources = new FilepathArray($boxFile, $useFile);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('not in the source set');
        $compiler->compile($sources, $sourceDir, $this->targetDir, $this->cacheDir);
    }

    public function testIntersectionBoundSatisfiedByImplementingClass(): void
    {
        // Fixture: `test/fixture/compile/bounds_intersection/`.
        // `T : Stringable & Countable` accepts a concrete class that
        // implements both -- end-to-end: compile produces the specialization,
        // emitted PHP is syntactically valid.
        $sourceDir = realpath(__DIR__ . '/../../fixture/compile/bounds_intersection/source')
            ?: throw new RuntimeException('Fixture missing');
        $compiler = $this->buildCompiler();
        $sources = (new NativeFileFinder())->find($sourceDir)
            ->filter(static fn (string $f): bool => str_ends_with($f, '.xphp'));

        $result = $compiler->compile($sources, $sourceDir, $this->targetDir, $this->cacheDir);

        $boxFqn = Registry::generatedFqn(
            'App\\BoundsIntersection\\Containers\\Box',
            [new TypeRef('App\\BoundsIntersection\\Models\\Tag')],
        );
        self::assertFileExists($this->fqnToPath($boxFqn));
        self::assertGreaterThan(0, $result->generatedCount);
    }

    public function testIntersectionBoundViolationOnPartiallySatisfyingClass(): void
    {
        // `T : A & B` rejects a concrete that satisfies A but not B.
        // The error message names the full intersection bound.
        $sourceDir = $this->workDir . '/src-and-violation';
        mkdir($sourceDir, 0o755, true);
        $boxFile = $sourceDir . '/Box.xphp';
        file_put_contents($boxFile, <<<'PHP'
        <?php
        namespace App;
        class Box<T: \Stringable & \Countable>
        {
            public function __construct(public T $item) {}
        }
        PHP);
        $partialFile = $sourceDir . '/StringOnly.xphp';
        file_put_contents($partialFile, <<<'PHP'
        <?php
        namespace App;
        class StringOnly implements \Stringable
        {
            public function __construct(public string $v) {}
            public function __toString(): string { return $this->v; }
        }
        PHP);
        $useFile = $sourceDir . '/Use.xphp';
        file_put_contents($useFile, <<<'PHP'
        <?php
        namespace App;
        $s = new StringOnly('hi');
        $b = new Box::<StringOnly>($s);
        PHP);

        $compiler = $this->buildCompiler();
        $sources = new FilepathArray($boxFile, $partialFile, $useFile);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Generic bound violated');
        $this->expectExceptionMessage('Stringable & Countable');
        $compiler->compile($sources, $sourceDir, $this->targetDir, $this->cacheDir);
    }

    public function testUnionBoundSatisfiedByEitherOperand(): void
    {
        // Fixture: `test/fixture/compile/bounds_union/`.
        // `T : Stringable | Countable` accepts concretes that satisfy EITHER
        // operand. The fixture instantiates with both shapes:
        // StringableOnly (satisfies left), CountableOnly (satisfies right).
        $sourceDir = realpath(__DIR__ . '/../../fixture/compile/bounds_union/source')
            ?: throw new RuntimeException('Fixture missing');
        $compiler = $this->buildCompiler();
        $sources = (new NativeFileFinder())->find($sourceDir)
            ->filter(static fn (string $f): bool => str_ends_with($f, '.xphp'));

        $result = $compiler->compile($sources, $sourceDir, $this->targetDir, $this->cacheDir);

        // Two specializations expected (StringableOnly, CountableOnly).
        self::assertSame(2, $result->generatedCount);
        $boxStringable = Registry::generatedFqn(
            'App\\BoundsUnion\\Containers\\Box',
            [new TypeRef('App\\BoundsUnion\\Models\\StringableOnly')],
        );
        $boxCountable = Registry::generatedFqn(
            'App\\BoundsUnion\\Containers\\Box',
            [new TypeRef('App\\BoundsUnion\\Models\\CountableOnly')],
        );
        self::assertFileExists($this->fqnToPath($boxStringable));
        self::assertFileExists($this->fqnToPath($boxCountable));
    }

    public function testUnionBoundViolationOnNeitherOperand(): void
    {
        // `T : A | B` rejects a concrete that satisfies neither.
        $sourceDir = $this->workDir . '/src-or-violation';
        mkdir($sourceDir, 0o755, true);
        $boxFile = $sourceDir . '/Box.xphp';
        file_put_contents($boxFile, <<<'PHP'
        <?php
        namespace App;
        class Box<T: \Stringable | \Countable>
        {
            public function __construct(public T $item) {}
        }
        PHP);
        $useFile = $sourceDir . '/Use.xphp';
        file_put_contents($useFile, <<<'PHP'
        <?php
        namespace App;
        $b = new Box::<int>(7);
        PHP);

        $compiler = $this->buildCompiler();
        $sources = new FilepathArray($boxFile, $useFile);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Generic bound violated');
        $this->expectExceptionMessage('Stringable | Countable');
        // Both operands return `false` from `isSubtype` (int vs class bound) --
        // the verdict must be "does not satisfy" (definite failure), NOT
        // "compiler cannot prove" (unknown). The distinction kills mutants
        // that flip the union's $sawNull initialization / ternary direction.
        $this->expectExceptionMessage('does not satisfy');
        $compiler->compile($sources, $sourceDir, $this->targetDir, $this->cacheDir);
    }

    public function testScalarUnionBoundAcceptsScalarArguments(): void
    {
        // A scalar-union bound `<T : int|string>` must accept scalar type arguments. Each scalar operand of
        // the bound is recognised as a builtin (left unqualified, flagged isScalar) rather than
        // namespace-qualified as a phantom class `App\int` -- so the bound check compares
        // scalar-against-scalar and both `::<int>` and `::<string>` specialize.
        $sourceDir = $this->workDir . '/src';
        mkdir($sourceDir, 0o755, true);
        $boxFile = $sourceDir . '/Box.xphp';
        file_put_contents($boxFile, <<<'PHP'
        <?php
        namespace App;
        final class Box<T : int|string>
        {
            public function __construct(public readonly T $value) {}
        }
        PHP);
        $useFile = $sourceDir . '/Use.xphp';
        file_put_contents($useFile, <<<'PHP'
        <?php
        namespace App;
        $i = new Box::<int>(7);
        $s = new Box::<string>('x');
        PHP);

        $compiler = $this->buildCompiler();
        $result = $compiler->compile(new FilepathArray($boxFile, $useFile), $sourceDir, $this->targetDir, $this->cacheDir);

        self::assertSame(2, $result->generatedCount, 'both int and string satisfy the int|string bound');
    }

    public function testScalarUnionBoundRejectsClassArgument(): void
    {
        // The dual of the accept case: a class argument does NOT satisfy a scalar-union bound, and the
        // diagnostic renders the bound with its scalar operands unqualified (`int | string`, not
        // `App\int | App\string`).
        $sourceDir = $this->workDir . '/src';
        mkdir($sourceDir, 0o755, true);
        $boxFile = $sourceDir . '/Box.xphp';
        file_put_contents($boxFile, <<<'PHP'
        <?php
        namespace App;
        final class Box<T : int|string>
        {
            public function __construct(public readonly T $value) {}
        }
        PHP);
        $thingFile = $sourceDir . '/Thing.xphp';
        file_put_contents($thingFile, <<<'PHP'
        <?php
        namespace App;
        final class Thing {}
        PHP);
        $useFile = $sourceDir . '/Use.xphp';
        file_put_contents($useFile, <<<'PHP'
        <?php
        namespace App;
        $t = new Box::<Thing>(new Thing());
        PHP);

        $compiler = $this->buildCompiler();
        $sources = new FilepathArray($boxFile, $thingFile, $useFile);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Generic bound violated');
        $this->expectExceptionMessage('int | string');
        $this->expectExceptionMessage('does not satisfy');
        $compiler->compile($sources, $sourceDir, $this->targetDir, $this->cacheDir);
    }

    public function testSingleScalarBoundRejectsDifferentScalar(): void
    {
        // A single scalar bound `<T : string>` rejects a different scalar (`int`) -- the bound is a concrete
        // scalar, so only that scalar (or a subtype, of which scalars have none) satisfies it.
        $sourceDir = $this->workDir . '/src';
        mkdir($sourceDir, 0o755, true);
        $boxFile = $sourceDir . '/Box.xphp';
        file_put_contents($boxFile, <<<'PHP'
        <?php
        namespace App;
        final class Box<T : string>
        {
            public function __construct(public readonly T $value) {}
        }
        PHP);
        $useFile = $sourceDir . '/Use.xphp';
        file_put_contents($useFile, <<<'PHP'
        <?php
        namespace App;
        $b = new Box::<int>(7);
        PHP);

        $compiler = $this->buildCompiler();
        $sources = new FilepathArray($boxFile, $useFile);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Generic bound violated');
        $this->expectExceptionMessage('int');
        $compiler->compile($sources, $sourceDir, $this->targetDir, $this->cacheDir);
    }

    public function testNonReservedScalarAliasResolvesAsClassBoundNotScalar(): void
    {
        // Regression guard: `integer`/`boolean`/`double` are LEGAL class names (not reserved PHP keywords),
        // so they are kept out of SCALAR_TYPES. A bound `<T : Double>` must resolve `Double` to the class,
        // not be mistaken for the scalar `double` -- otherwise a valid subtype argument would be falsely
        // rejected.
        $sourceDir = $this->workDir . '/src';
        mkdir($sourceDir, 0o755, true);
        $modelFile = $sourceDir . '/Models.xphp';
        file_put_contents($modelFile, <<<'PHP'
        <?php
        namespace App;
        class Double {}
        final class Sub extends Double {}
        PHP);
        $boxFile = $sourceDir . '/Box.xphp';
        file_put_contents($boxFile, <<<'PHP'
        <?php
        namespace App;
        final class Box<T : Double>
        {
            public function __construct(public readonly T $value) {}
        }
        PHP);
        $useFile = $sourceDir . '/Use.xphp';
        file_put_contents($useFile, <<<'PHP'
        <?php
        namespace App;
        $b = new Box::<Sub>(new Sub());
        PHP);

        $compiler = $this->buildCompiler();
        $result = $compiler->compile(new FilepathArray($boxFile, $modelFile, $useFile), $sourceDir, $this->targetDir, $this->cacheDir);

        self::assertSame(1, $result->generatedCount, 'Sub extends Double, so the class bound <T : Double> is satisfied');
    }

    public function testMixedCaseScalarBoundIsRecognisedCaseInsensitively(): void
    {
        // PHP type keywords are case-insensitive, so a bound written `<T : Int|String>` must be recognised
        // as the scalar union, not namespace-qualified as classes `App\Int` / `App\String`. The leaf
        // lowercases the name before matching the keyword list, exactly as the signature-type resolver does.
        $sourceDir = $this->workDir . '/src';
        mkdir($sourceDir, 0o755, true);
        $boxFile = $sourceDir . '/Box.xphp';
        file_put_contents($boxFile, <<<'PHP'
        <?php
        namespace App;
        final class Box<T : Int|String>
        {
            public function __construct(public readonly T $value) {}
        }
        PHP);
        $useFile = $sourceDir . '/Use.xphp';
        file_put_contents($useFile, <<<'PHP'
        <?php
        namespace App;
        $i = new Box::<int>(7);
        $s = new Box::<string>('x');
        PHP);

        $compiler = $this->buildCompiler();
        $result = $compiler->compile(new FilepathArray($boxFile, $useFile), $sourceDir, $this->targetDir, $this->cacheDir);

        self::assertSame(2, $result->generatedCount, 'Int|String is the scalar union regardless of letter case');
    }

    #[RunInSeparateProcess]
    public function testScalarAliasClassTypeArgumentsResolveAndRunAtRuntime(): void
    {
        // The headline behavioural gate: `Double`/`Integer`/`Boolean` are real classes whose names alias the
        // gettype-style scalar names. Used as generic type ARGUMENTS they must resolve to the classes, so
        // each `Box<…>` specialization is typed on `\App\Double` etc. Before the fix the alias was mistaken
        // for the scalar and emitted `public readonly double $value` -- which PHP reads as a non-existent
        // class and fatals at construction. Compiled and executed end-to-end.
        $fixture = CompiledFixture::compile(
            __DIR__ . '/../../fixture/compile/scalar_alias_class_resolves/source',
            'scalar-alias',
        );
        try {
            $fixture->registerAutoload('App');
            require __DIR__ . '/../../fixture/compile/scalar_alias_class_resolves/verify/runtime.php';
        } finally {
            $fixture->cleanup();
        }
    }

    public function testScalarAliasClassInSignaturePositionResolvesToClass(): void
    {
        // The signature-position twin: a class named `Double` used as a member type inside a generic template
        // must resolve to the fully-qualified class (`?\App\Double`), not the bare scalar keyword `double`
        // (which would emit a phantom type in the generated namespace).
        $sourceDir = $this->workDir . '/src';
        mkdir($sourceDir, 0o755, true);
        file_put_contents($sourceDir . '/Double.xphp', <<<'PHP'
        <?php
        namespace App;
        final class Double { public function __construct(public readonly float $f) {} }
        PHP);
        file_put_contents($sourceDir . '/Pair.xphp', <<<'PHP'
        <?php
        namespace App;
        final class Pair<T> { public function __construct(public readonly T $t, public ?Double $d = null) {} }
        PHP);
        file_put_contents($sourceDir . '/Use.xphp', <<<'PHP'
        <?php
        namespace App;
        $p = new Pair::<int>(3, new Double(1.0));
        PHP);

        $files = (new NativeFileFinder())->find($sourceDir)->filter(static fn (string $f): bool => str_ends_with($f, '.xphp'));
        $this->buildCompiler()->compile($files, $sourceDir, $this->targetDir, $this->cacheDir);

        $specs = glob($this->cacheDir . '/Generated/App/Pair/*.php') ?: [];
        self::assertNotEmpty($specs, 'Pair<int> must specialize');
        $content = file_get_contents($specs[0]);
        self::assertStringContainsString('\\App\\Double', $content, 'the aliased class must resolve to the FQ class');
        self::assertStringNotContainsString('?double ', $content, 'must not emit the bare scalar keyword as the member type');
    }

    public function testUndeclaredScalarAliasInMemberPositionIsRejected(): void
    {
        // The undeclared-type check is no longer fooled by the alias: an alias-named class used as a member
        // type but NOT declared in the source set is a genuine undeclared type. Before the fix it was
        // silently absorbed as the scalar `double`; it now fails loudly.
        $sourceDir = $this->workDir . '/src';
        mkdir($sourceDir, 0o755, true);
        file_put_contents($sourceDir . '/Pair.xphp', <<<'PHP'
        <?php
        namespace App;
        final class Pair<T> { public ?Double $d = null; public function __construct(public readonly T $t) {} }
        PHP);
        file_put_contents($sourceDir . '/Use.xphp', <<<'PHP'
        <?php
        namespace App;
        $p = new Pair::<int>(3);
        PHP);

        $files = (new NativeFileFinder())->find($sourceDir)->filter(static fn (string $f): bool => str_ends_with($f, '.xphp'));

        $this->expectException(RuntimeException::class);
        // Single matcher: the diagnostic must name the offending type AND classify it as undeclared.
        // (expectExceptionMessage is assign-only, so two calls would assert only the second.)
        $this->expectExceptionMessageMatches('/`Double`.*is not a declared type parameter/');
        $this->buildCompiler()->compile($files, $sourceDir, $this->targetDir, $this->cacheDir);
    }

    public function testRealScalarTypeArgumentStaysScalarAfterAliasFix(): void
    {
        // Guard against over-narrowing the keyword list: a genuine scalar argument must still specialize as
        // the scalar, not be qualified into a phantom class `\App\int`.
        $sourceDir = $this->workDir . '/src';
        mkdir($sourceDir, 0o755, true);
        file_put_contents($sourceDir . '/Box.xphp', <<<'PHP'
        <?php
        namespace App;
        final class Box<T> { public function __construct(public readonly T $value) {} }
        PHP);
        file_put_contents($sourceDir . '/Use.xphp', <<<'PHP'
        <?php
        namespace App;
        $b = new Box::<int>(7);
        PHP);

        $files = (new NativeFileFinder())->find($sourceDir)->filter(static fn (string $f): bool => str_ends_with($f, '.xphp'));
        $this->buildCompiler()->compile($files, $sourceDir, $this->targetDir, $this->cacheDir);

        $specs = glob($this->cacheDir . '/Generated/App/Box/*.php') ?: [];
        self::assertNotEmpty($specs);
        $content = file_get_contents($specs[0]);
        self::assertStringContainsString('int $value', $content, 'a real scalar arg stays the scalar keyword');
        self::assertStringNotContainsString('App\\int', $content, 'a real scalar must not be namespace-qualified');
    }

    public function testUnionBoundWithUnknownOperandYieldsNullVerdict(): void
    {
        // when at least one union operand returns `null` from
        // isSubtype (unknown class) and no operand returns `true`, the
        // combinator must return null -> "compiler cannot prove" message.
        // Kills the union-branch FalseValue / Identical / Ternary / ReturnRemoval
        // mutants that would yield `false` (-> "does not satisfy") instead.
        //
        // Concrete must be a CLASS NAME that isn't in the source set --
        // isSubtype short-circuits scalars to false (TypeHierarchy.php:97), so
        // a scalar concrete never produces a null verdict regardless of bound.
        $sourceDir = $this->workDir . '/src-or-unknown';
        mkdir($sourceDir, 0o755, true);
        $boxFile = $sourceDir . '/Box.xphp';
        file_put_contents($boxFile, <<<'PHP'
        <?php
        namespace App;
        class Box<T: \Stringable | \Vendor\OtherUnknown>
        {
            public function __construct(public T $item) {}
        }
        PHP);
        $useFile = $sourceDir . '/Use.xphp';
        file_put_contents($useFile, <<<'PHP'
        <?php
        namespace App;
        // \Vendor\Unknown is a class not in the source set -- isSubtype
        // returns null for BOTH operands of the union (the concrete itself
        // is unknown, so the hierarchy can't trace it to either operand).
        // Union combinator: all null -> null verdict.
        $b = new Box::<\Vendor\Unknown>(null);
        PHP);

        $compiler = $this->buildCompiler();
        $sources = new FilepathArray($boxFile, $useFile);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Generic bound violated');
        $this->expectExceptionMessage('compiler cannot prove');
        $compiler->compile($sources, $sourceDir, $this->targetDir, $this->cacheDir);
    }

    public function testIntersectionBoundWithUnknownOperandYieldsNullVerdict(): void
    {
        // symmetric to the Union null-verdict test.
        // `T : Stringable & \Vendor\UnknownIface` with a concrete that
        // implements neither (and is itself unknown) -> at least one operand
        // returns null from isSubtype, no operand returns false. Intersection
        // combinator: all true OR all-null + no-false -> null.
        //
        // In practice the unknown-concrete shortcut means BOTH operands
        // return null (the concrete itself is unknown, so the hierarchy
        // can't prove subtype against either operand). Verdict: null
        // -> "compiler cannot prove" message.
        $sourceDir = $this->workDir . '/src-and-unknown';
        mkdir($sourceDir, 0o755, true);
        $boxFile = $sourceDir . '/Box.xphp';
        file_put_contents($boxFile, <<<'PHP'
        <?php
        namespace App;
        class Box<T: \Stringable & \Vendor\Unknown>
        {
            public function __construct(public T $item) {}
        }
        PHP);
        $useFile = $sourceDir . '/Use.xphp';
        file_put_contents($useFile, <<<'PHP'
        <?php
        namespace App;
        // \Vendor\Mystery is a class not in the source set -- isSubtype
        // returns null for BOTH operands. Intersection: any null + no false
        // -> null verdict.
        $b = new Box::<\Vendor\Mystery>(null);
        PHP);

        $compiler = $this->buildCompiler();
        $sources = new FilepathArray($boxFile, $useFile);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Generic bound violated');
        $this->expectExceptionMessage('compiler cannot prove');
        $compiler->compile($sources, $sourceDir, $this->targetDir, $this->cacheDir);
    }

    public function testInverseDnfBoundViolationRendersWithParensAroundUnion(): void
    {
        // the Intersection branch of formatBound
        // must wrap inner Union operands in parens too -- otherwise
        // `(A | B) & C` renders as `A | B & C` (wrong precedence).
        // This test asserts the symmetric rendering for the
        // intersection-of-union shape.
        $sourceDir = $this->workDir . '/src-inverse-dnf';
        mkdir($sourceDir, 0o755, true);
        $boxFile = $sourceDir . '/Box.xphp';
        file_put_contents($boxFile, <<<'PHP'
        <?php
        namespace App;
        class Box<T: (\Stringable | \Countable) & \Iterator>
        {
            public function __construct(public T $item) {}
        }
        PHP);
        $useFile = $sourceDir . '/Use.xphp';
        file_put_contents($useFile, <<<'PHP'
        <?php
        namespace App;
        $b = new Box::<int>(7);
        PHP);

        $compiler = $this->buildCompiler();
        $sources = new FilepathArray($boxFile, $useFile);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Generic bound violated');
        // The union arm must be parenthesised in the rendered bound.
        $this->expectExceptionMessage('(Stringable | Countable) & Iterator');
        $compiler->compile($sources, $sourceDir, $this->targetDir, $this->cacheDir);
    }

    public function testDnfBoundViolationRendersWithParensInErrorMessage(): void
    {
        // when a DNF bound violation surfaces, the error message must
        // render Intersection operands wrapped in parens INSIDE the outer Union:
        // `(A & B) | C` not `A & B | C` (ambiguous) or `A & B | C` (wrong).
        //
        // Kills the formatBound mutants on Registry::formatBound's union branch:
        //   - InstanceOf_  (`$op instanceof BoundIntersection` -> negated)
        //   - Ternary     (wraps the non-intersection operand in parens instead)
        //   - ReturnRemoval (drops the union rendering entirely)
        $sourceDir = $this->workDir . '/src-dnf-violation';
        mkdir($sourceDir, 0o755, true);
        $boxFile = $sourceDir . '/Box.xphp';
        file_put_contents($boxFile, <<<'PHP'
        <?php
        namespace App;
        class Box<T: (\Stringable & \Countable) | \Iterator>
        {
            public function __construct(public T $item) {}
        }
        PHP);
        $useFile = $sourceDir . '/Use.xphp';
        file_put_contents($useFile, <<<'PHP'
        <?php
        namespace App;
        $b = new Box::<int>(7);
        PHP);

        $compiler = $this->buildCompiler();
        $sources = new FilepathArray($boxFile, $useFile);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Generic bound violated');
        // The intersection arm must be parenthesised in the rendered bound.
        $this->expectExceptionMessage('(Stringable & Countable) | Iterator');
        $compiler->compile($sources, $sourceDir, $this->targetDir, $this->cacheDir);
    }

    public function testDnfBoundAcceptsBothArms(): void
    {
        // Fixture: `test/fixture/compile/bounds_dnf/`.
        // `(Stringable & Countable) | Iterator` -- left arm satisfied by
        // StringableCountable, right arm satisfied by IteratorOnly. Both
        // shapes must specialize.
        $sourceDir = realpath(__DIR__ . '/../../fixture/compile/bounds_dnf/source')
            ?: throw new RuntimeException('Fixture missing');
        $compiler = $this->buildCompiler();
        $sources = (new NativeFileFinder())->find($sourceDir)
            ->filter(static fn (string $f): bool => str_ends_with($f, '.xphp'));

        $result = $compiler->compile($sources, $sourceDir, $this->targetDir, $this->cacheDir);

        self::assertSame(2, $result->generatedCount);
        $boxLeft = Registry::generatedFqn(
            'App\\BoundsDnf\\Containers\\Box',
            [new TypeRef('App\\BoundsDnf\\Models\\StringableCountable')],
        );
        $boxRight = Registry::generatedFqn(
            'App\\BoundsDnf\\Containers\\Box',
            [new TypeRef('App\\BoundsDnf\\Models\\IteratorOnly')],
        );
        self::assertFileExists($this->fqnToPath($boxLeft));
        self::assertFileExists($this->fqnToPath($boxRight));
    }

    public function testFBoundedRecursionCompilesWithGenericArgInBound(): void
    {
        // Fixture: `test/fixture/compile/bounds_f_bounded/`.
        // `Sortable<T: Comparable<T>>` -- the bound is itself a generic
        // (`Comparable<T>`), so the BoundLeaf carries a TypeRef with args
        // where the inner T refers to the enclosing type-param. The Tag
        // model implements `Comparable<Tag>` and is the legal concrete.
        $sourceDir = realpath(__DIR__ . '/../../fixture/compile/bounds_f_bounded/source')
            ?: throw new RuntimeException('Fixture missing');
        $compiler = $this->buildCompiler();
        $sources = (new NativeFileFinder())->find($sourceDir)
            ->filter(static fn (string $f): bool => str_ends_with($f, '.xphp'));

        $result = $compiler->compile($sources, $sourceDir, $this->targetDir, $this->cacheDir);

        self::assertGreaterThan(0, $result->generatedCount);
        $sortable = Registry::generatedFqn(
            'App\\BoundsFBounded\\Containers\\Sortable',
            [new TypeRef('App\\BoundsFBounded\\Models\\Tag')],
        );
        self::assertFileExists($this->fqnToPath($sortable));
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
