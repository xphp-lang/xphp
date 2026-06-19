<?php

declare(strict_types=1);

namespace XPHP\Transpiler\Monomorphize;

use PhpParser\ParserFactory;
use PhpParser\PrettyPrinter\Standard as StandardPrinter;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use XPHP\FileSystem\FileFinder\NativeFileFinder;
use XPHP\FileSystem\FilepathArray;
use XPHP\FileSystem\FileReader\NativeFileReader;
use XPHP\FileSystem\FileWriter\NativeFileWriter;
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

    public function testHashableBoundIsSatisfiedByImplementingClass(): void
    {
        // `Hashable` is a whitelisted bound name (ticket 0006). xphp recognizes it
        // even though the interface is provided by the consumer/library and isn't
        // in the scanned source set here — so `Set<T: Hashable>` resolves against a
        // class that `implements Hashable`.
        $sourceDir = $this->workDir . '/src';
        mkdir($sourceDir, 0o755, true);
        $setFile = $sourceDir . '/Set.xphp';
        file_put_contents($setFile, <<<'PHP'
        <?php
        namespace App;
        class Set<T: \Hashable>
        {
            private array $items = [];
            public function add(T $x): void { $this->items[] = $x; }
        }
        PHP);
        $userFile = $sourceDir . '/User.xphp';
        file_put_contents($userFile, <<<'PHP'
        <?php
        namespace App;
        class User implements \Hashable
        {
            public function hashCode(): int|string { return 1; }
            public function equals(self $other): bool { return true; }
        }
        PHP);
        $useFile = $sourceDir . '/Use.xphp';
        file_put_contents($useFile, <<<'PHP'
        <?php
        namespace App;
        $s = new Set::<User>();
        PHP);

        $compiler = $this->buildCompiler();
        $sources = new FilepathArray($setFile, $userFile, $useFile);
        $result = $compiler->compile($sources, $sourceDir, $this->targetDir, $this->cacheDir);

        // Set<User> specialized — the Hashable bound resolved (User implements it).
        self::assertSame(1, $result->generatedCount);
    }

    public function testHashableBoundViolationOnNonImplementingClass(): void
    {
        $sourceDir = $this->workDir . '/src';
        mkdir($sourceDir, 0o755, true);
        $setFile = $sourceDir . '/Set.xphp';
        file_put_contents($setFile, <<<'PHP'
        <?php
        namespace App;
        class Set<T: \Hashable>
        {
            public function add(T $x): void {}
        }
        PHP);
        $plainFile = $sourceDir . '/Plain.xphp';
        file_put_contents($plainFile, <<<'PHP'
        <?php
        namespace App;
        class Plain {}
        PHP);
        $useFile = $sourceDir . '/Use.xphp';
        file_put_contents($useFile, <<<'PHP'
        <?php
        namespace App;
        $s = new Set::<Plain>();
        PHP);

        $compiler = $this->buildCompiler();
        $sources = new FilepathArray($setFile, $plainFile, $useFile);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Generic bound violated');
        $this->expectExceptionMessage('Hashable');
        $compiler->compile($sources, $sourceDir, $this->targetDir, $this->cacheDir);
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
