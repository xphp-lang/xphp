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
 * End-to-end coverage for grounding a method-generic bound that references an enclosing class type
 * parameter (`<U : E>`) against the receiver's concrete type argument — the element-consuming
 * method shape on a covariant collection. Accept/reject/multi-arg pin that the bound is checked
 * against the *grounded* type (not the literal `E`); the lenient cases pin that ungroundable
 * receivers fall back to "no check" rather than the old misleading rejection.
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

    /** Shared model: Book extends Product extends Item. */
    private const MODELS = <<<'PHP'
    <?php
    declare(strict_types=1);
    namespace App;
    class Item {}
    class Product extends Item {}
    class Book extends Product {}
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
            $box = new Box::<Product>();
            $box->contains::<Book>(new Book());
            PHP,
        ]);

        // Compiled without a bound violation, and the call site was specialized (the method was
        // mangled, not left as a bare `contains`).
        self::assertStringContainsString('contains_', self::read($dist, 'Use.php'));
    }

    public function testInheritedEnclosingParamBoundAcceptsSubtype(): void
    {
        // `contains` is declared on a generic BASE; the receiver is a subclass. Grounding must
        // thread the receiver's `Product` through `extends Base<E>` to the base's `E`.
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
            $list = new ArrayList::<Product>();
            $list->contains::<Book>(new Book());
            PHP,
        ]);

        $this->addToAssertionCount(1); // reaching here = compiled with no bound violation.
    }

    public function testMultiArgEnclosingParamBoundGroundsTheRightParameter(): void
    {
        // `Pair<K, +V>::containsValue<U : V>` — grounding must pick V (index 1), not K. If it used
        // K (Item), `Book <: Item` would also pass, so make K a type Book is NOT a subtype of.
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
            $pair = new Pair::<Key, Product>();
            $pair->containsValue::<Book>(new Book());
            PHP,
        ]);

        $this->addToAssertionCount(1);
    }

    public function testEnclosingParamBoundRejectsNonSubtypeWithGroundedMessage(): void
    {
        // Box<Book>::contains<Product> — Product is NOT a subtype of Book, so this must reject, and
        // the message must show the GROUNDED bound (`Book`), not the literal type parameter `E`.
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
                $box = new Box::<Book>();
                $box->contains::<Product>(new Product());
                PHP,
            ]);
            self::fail('Expected a bound violation for Box<Book>::contains<Product>.');
        } catch (RuntimeException $e) {
            $msg = $e->getMessage();
            self::assertStringContainsString('Generic bound violated', $msg);
            self::assertStringContainsString('extend/implement "App\\Book"', $msg, 'bound must be grounded to the receiver arg Book');
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
            $box = new Box::<Product>();
            $box->pick::<Book>(new Book());
            PHP,
        ]);

        self::assertStringContainsString('pick_', self::read($dist, 'Use.php'));
    }

    public function testThisReceiverEnclosingBoundIsLenient(): void
    {
        // `$this->contains::<Book>()` inside the template body: E has no concrete value yet, so the
        // bound is dropped (lenient) rather than rejected against the phantom `E`.
        $this->compile([
            'Models.xphp' => self::MODELS,
            'Box.xphp' => <<<'PHP'
            <?php
            declare(strict_types=1);
            namespace App;
            class Box<+E> {
                public function contains<U : E>(U $value): bool { return true; }
                public function probe(): bool { return $this->contains::<Book>(new Book()); }
            }
            PHP,
            'Use.xphp' => <<<'PHP'
            <?php
            declare(strict_types=1);
            namespace App;
            $box = new Box::<Product>();
            PHP,
        ]);

        $this->addToAssertionCount(1);
    }

    public function testParameterReceiverGroundsAndRejects(): void
    {
        // A parameter typed `Box<Book>` must ground `contains<U:E>` to Book; Product is not a
        // subtype, so this rejects — proving the param's type args are tracked and grounded.
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('extend/implement "App\\Book"');
        $this->compile([
            'Models.xphp' => self::MODELS,
            'Box.xphp' => self::box(),
            'Use.xphp' => <<<'PHP'
            <?php
            declare(strict_types=1);
            namespace App;
            function consume(Box<Book> $b): bool { return $b->contains::<Product>(new Product()); }
            PHP,
        ]);
    }

    public function testPropertyReceiverGroundsAndRejects(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('extend/implement "App\\Book"');
        $this->compile([
            'Models.xphp' => self::MODELS,
            'Box.xphp' => self::box(),
            'Holder.xphp' => <<<'PHP'
            <?php
            declare(strict_types=1);
            namespace App;
            class Holder {
                private Box<Book> $b;
                public function run(): bool { return $this->b->contains::<Product>(new Product()); }
            }
            PHP,
        ]);
    }

    public function testBranchMergeDropsArgsAndFallsBackToLenient(): void
    {
        // Both arms assign a Box but with DIFFERENT args; the FQN merges (still Box) yet the args
        // conflict, so they are dropped → the call is lenient. If the args were not dropped, the
        // call would ground to one arm's type and wrongly reject `Item` (a supertype of both).
        $this->compile([
            'Models.xphp' => self::MODELS,
            'Box.xphp' => self::box(),
            'Use.xphp' => <<<'PHP'
            <?php
            declare(strict_types=1);
            namespace App;
            function pick(bool $c): bool {
                if ($c) { $box = new Box::<Product>(); } else { $box = new Box::<Book>(); }
                return $box->contains::<Item>(new Item());
            }
            PHP,
        ]);

        $this->addToAssertionCount(1);
    }

    public function testArgsSurviveABranchThatDoesNotTouchTheReceiver(): void
    {
        // The receiver is set before the branch and never reassigned inside it, so its args survive
        // and ground the call — Product is not a subtype of Book, so it still rejects.
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('extend/implement "App\\Book"');
        $this->compile([
            'Models.xphp' => self::MODELS,
            'Box.xphp' => self::box(),
            'Use.xphp' => <<<'PHP'
            <?php
            declare(strict_types=1);
            namespace App;
            function keep(bool $c): bool {
                $box = new Box::<Book>();
                if ($c) { $unrelated = 1; }
                return $box->contains::<Product>(new Product());
            }
            PHP,
        ]);
    }

    public function testClosureUseReceiverGroundsAndRejects(): void
    {
        // `use ($box)` imports the outer local's args into the closure scope, so the bound grounds
        // to Book inside the closure body and rejects Product.
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('extend/implement "App\\Book"');
        $this->compile([
            'Models.xphp' => self::MODELS,
            'Box.xphp' => self::box(),
            'Use.xphp' => <<<'PHP'
            <?php
            declare(strict_types=1);
            namespace App;
            function viaClosure(): callable {
                $box = new Box::<Book>();
                return function () use ($box): bool { return $box->contains::<Product>(new Product()); };
            }
            PHP,
        ]);
    }

    // --- harness ---

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
