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
 * Regression for F1 in the second-agent review: the method/function specializers
 * used to skip ATTR_GENERIC_ARGS recursion, so `function wrap<T>(T $x): Box<T>`
 * would leave the `Box<T>` arg list untouched after substitution and the rest of
 * the pipeline silently miscompiled.
 */
final class NestedArgsInSpecializedFunctionTest extends TestCase
{
    private string $sourceDir;
    private string $workDir;
    private string $targetDir;
    private string $cacheDir;

    protected function setUp(): void
    {
        $this->sourceDir = realpath(__DIR__ . '/../../fixture/compile/generic_function_nested_args/source')
            ?: throw new RuntimeException('Fixture missing');
        $this->workDir = sys_get_temp_dir() . '/xphp-nested-args-' . uniqid('', true);
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

    public function testWrappedReturnTypeBoxOfTSpecializesToBoxOfInt(): void
    {
        $this->compile();

        // The specialized `wrap_T_<hash>` must have return type pointing at
        // `\XPHP\Generated\App\Containers\Box\T_<hash>`, not the unsubstituted `Box<T>`.
        $funcsPath = $this->targetDir . '/funcs.php';
        self::assertFileExists($funcsPath);
        $content = file_get_contents($funcsPath);

        self::assertMatchesRegularExpression(
            '#function wrap_T_[0-9a-f]+\(int \$x\): \\\\XPHP\\\\Generated\\\\App\\\\Containers\\\\Box\\\\T_[0-9a-f]+#',
            $content,
            'specialized wrap must have its Box<T> return type substituted to the matching Box<int> specialization FQN',
        );

        self::assertStringNotContainsString('Box<T>', $content, 'unsubstituted Box<T> must not survive');
        self::assertStringNotContainsString('Box<int>', $content, 'the in-source Box<int> must be rewritten to the specialized FQN, not left as a generic clause');
    }

    public function testRuntimeBoxOfIntIsConstructedByTheSpecializedWrap(): void
    {
        $this->compile();

        $loader = new \Composer\Autoload\ClassLoader();
        $loader->addPsr4('App\\', $this->targetDir);
        $loader->addPsr4(Registry::GENERATED_NAMESPACE_PREFIX . '\\', $this->cacheDir . '/Generated');
        $loader->register();

        try {
            // Free functions aren't autoloadable in PHP — require the rewritten file so
            // the mangled wrap_T_<hash> declaration enters the symbol table.
            require_once $this->targetDir . '/funcs.php';

            // Pull the mangled wrap_T_<hash> name out of the emitted file.
            $content = file_get_contents($this->targetDir . '/funcs.php');
            \preg_match('/function (wrap_T_[0-9a-f]+)\(/', $content, $m);
            self::assertNotEmpty($m, 'expected to find mangled wrap_T_<hash>');
            $mangled = '\\App\\' . $m[1];

            $boxFqn = Registry::generatedFqn('App\\Containers\\Box', [new TypeRef('int', isScalar: true)]);
            $result = $mangled(42);
            self::assertInstanceOf($boxFqn, $result, 'wrap<int> must return the specialized Box<int>');
            self::assertSame(42, $result->item);
        } finally {
            $loader->unregister();
        }
    }

    private function compile(): void
    {
        $compiler = $this->buildCompiler();
        $sources = (new NativeFileFinder())->find($this->sourceDir)
            ->filter(static fn (string $f): bool => str_ends_with($f, '.xphp'));
        $compiler->compile($sources, $this->sourceDir, $this->targetDir, $this->cacheDir);
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
