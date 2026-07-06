<?php

declare(strict_types=1);

namespace XPHP\Transpiler\Monomorphize;

use PhpParser\ParserFactory;
use PhpParser\PrettyPrinter\Standard as StandardPrinter;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use XPHP\FileSystem\FileFinder\NativeFileFinder;
use XPHP\FileSystem\FileReader\NativeFileReader;
use XPHP\FileSystem\FileWriter\NativeFileWriter;

/**
 * Pins enclosing-type-parameter bound grounding when the receiver's element type is recovered from a
 * branch merge: a receiver assigned to the SAME parameterised type in every arm of an if/else is
 * determined, the bound that references its element type is grounded, and a real violation is
 * rejected at compile time — exactly as it is for a straight-line receiver.
 *
 * Each branch-merge case is paired with a "groundable" control that compiles the SAME violation with
 * a straight-line receiver whose element type is known. The pair proves the branch-merge path grounds
 * to the same result as the direct path, honouring the project's maximum-runtime-safety principle:
 * a knowable type is never dropped, so a determinate violation is never silently accepted.
 */
final class EnclosingParamBoundLimitationTest extends TestCase
{
    /** @var list<string> */
    private array $workDirs = [];

    protected function tearDown(): void
    {
        foreach ($this->workDirs as $dir) {
            self::rrmdir($dir);
        }
        $this->workDirs = [];
    }

    // --- Branch-merge agreement: a determinable receiver IS grounded and checked (bare `<U : E>`) ---

    public function testBranchMergedReceiverIsGroundedAndRejectsAnElementViolation(): void
    {
        // `pick()` calls contains::<Rock> on a Box<Fruit> receiver assigned in BOTH arms of an
        // if/else. The arms agree, so the element type is determined (branch-merge agreement), the
        // bound grounds to Fruit, and Rock — not a Fruit — is rejected at compile time.
        try {
            $this->compileFixture('enclosing_param_bound_lenient_drop');
            self::fail('expected a bound violation for contains::<Rock> on a branch-merged Box<Fruit>');
        } catch (RuntimeException $e) {
            self::assertStringContainsString('Generic bound violated', $e->getMessage());
            self::assertStringContainsString('extend/implement "App\\Fruit"', $e->getMessage());
        }
    }

    public function testTheSameElementViolationIsCaughtWhenTheReceiverIsGroundable(): void
    {
        // Identical violation, but a straight-line Box<Fruit> receiver — E grounds to Fruit, so the
        // bound IS checked and Rock is rejected. (Control for the lenient-drop limitation.)
        try {
            $this->compileInline([
                'Models.xphp' => self::MODELS_LENIENT,
                'Box.xphp' => self::BOX_CONTAINS,
                'Use.xphp' => <<<'PHP'
                <?php
                declare(strict_types=1);
                namespace App;
                $box = new Box::<Fruit>();
                $box->contains::<Rock>(new Rock());
                PHP,
            ]);
            self::fail('expected a bound violation for contains::<Rock> on Box<Fruit>');
        } catch (RuntimeException $e) {
            self::assertStringContainsString('Generic bound violated', $e->getMessage());
            self::assertStringContainsString('extend/implement "App\\Fruit"', $e->getMessage());
        }
    }

    // --- Branch-merge agreement: a compound `<U : \Stringable & E>` is grounded WHOLE and checked ---

    public function testBranchMergedReceiverIsGroundedAndRejectsACompoundViolation(): void
    {
        // `store()` calls register::<Banana> on a Box<Fruit> receiver assigned in BOTH arms of an
        // if/else. The arms agree, so the element type is determined, the bound grounds to
        // `\Stringable & Fruit`, and Banana — a Fruit but not \Stringable — is rejected on the
        // \Stringable operand.
        try {
            $this->compileFixture('enclosing_param_bound_compound_drop');
            self::fail('expected a bound violation for register::<Banana> on a branch-merged Box<Fruit>');
        } catch (RuntimeException $e) {
            self::assertStringContainsString('Generic bound violated', $e->getMessage());
            self::assertStringContainsString('Stringable', $e->getMessage());
        }
    }

    public function testTheStringableOperandIsEnforcedWhenTheReceiverIsGroundable(): void
    {
        // Identical call, but a straight-line Box<Fruit> receiver — E grounds to Fruit, the bound
        // becomes `\Stringable & Fruit`, and the intersection rejects Banana for not being Stringable.
        // (Control for the compound-drop limitation: it proves the \Stringable operand is what's lost.)
        try {
            $this->compileInline([
                'Models.xphp' => self::MODELS_COMPOUND,
                'Box.xphp' => self::BOX_REGISTER,
                'Use.xphp' => <<<'PHP'
                <?php
                declare(strict_types=1);
                namespace App;
                $box = new Box::<Fruit>();
                $box->register::<Banana>(new Banana());
                PHP,
            ]);
            self::fail('expected a bound violation for register::<Banana> on Box<Fruit>');
        } catch (RuntimeException $e) {
            self::assertStringContainsString('Generic bound violated', $e->getMessage());
            self::assertStringContainsString('Stringable', $e->getMessage());
        }
    }

    // --- inline sources shared by the controls (mirror the fixtures) ---

    private const MODELS_LENIENT = <<<'PHP'
    <?php
    declare(strict_types=1);
    namespace App;
    class Fruit {}
    class Rock {}
    PHP;

    private const BOX_CONTAINS = <<<'PHP'
    <?php
    declare(strict_types=1);
    namespace App;
    class Box<out E> {
        public function contains<U : E>(U $value): bool { return true; }
    }
    PHP;

    private const MODELS_COMPOUND = <<<'PHP'
    <?php
    declare(strict_types=1);
    namespace App;
    class Fruit {}
    class Banana extends Fruit {}
    PHP;

    private const BOX_REGISTER = <<<'PHP'
    <?php
    declare(strict_types=1);
    namespace App;
    class Box<out E> {
        public function register<U : \Stringable & E>(U $value): void {}
    }
    PHP;

    // --- harness ---

    /** Compile a fixture's `source/` dir to a fresh temp dist; returns the dist path. */
    private function compileFixture(string $name): string
    {
        $src = realpath(__DIR__ . '/../../fixture/compile/' . $name . '/source')
            ?: throw new RuntimeException("missing fixture: {$name}");
        $out = $this->freshWorkDir();
        $sources = (new NativeFileFinder())->find($src)
            ->filter(static fn (string $f): bool => str_ends_with($f, '.xphp'));
        $this->buildCompiler()->compile($sources, $src, $out . '/dist', $out . '/cache');

        return $out . '/dist';
    }

    /** @param array<string, string> $files filename => contents */
    private function compileInline(array $files): void
    {
        $out = $this->freshWorkDir();
        $src = $out . '/src';
        mkdir($src, 0o755, true);
        foreach ($files as $name => $contents) {
            file_put_contents($src . '/' . $name, $contents);
        }
        $sources = (new NativeFileFinder())->find($src)
            ->filter(static fn (string $f): bool => str_ends_with($f, '.xphp'));
        $this->buildCompiler()->compile($sources, $src, $out . '/dist', $out . '/cache');
    }

    private function freshWorkDir(): string
    {
        $dir = sys_get_temp_dir() . '/xphp-encbound-lim-' . uniqid('', true);
        mkdir($dir, 0o755, true);
        $this->workDirs[] = $dir;

        return $dir;
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
