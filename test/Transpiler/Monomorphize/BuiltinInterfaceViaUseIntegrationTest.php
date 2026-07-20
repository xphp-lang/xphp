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

/**
 * Regression coverage: a generic that `extends`/`implements` a
 * built-in interface — or instantiates / catches an imported class — via a bare
 * `use` import used to keep those bare names when relocated into the
 * `XPHP\Generated\...` namespace, where they resolved to a non-existent
 * `XPHP\Generated\...\<Name>` and threw at autoload time.
 *
 * The fix fully-qualifies such names ONLY on the relocated specialized clone, so
 * (a) the generated classes load, and (b) the emitted user files stay byte-for-byte
 * unchanged (bare names + their `use` lines preserved).
 */
final class BuiltinInterfaceViaUseIntegrationTest extends TestCase
{
    private string $sourceDir;
    private string $workDir;
    private string $targetDir;
    private string $cacheDir;

    protected function setUp(): void
    {
        $this->sourceDir = realpath(__DIR__ . '/../../fixture/compile/builtin_interface_via_use/source')
            ?: throw new RuntimeException('Fixture missing');
        $this->workDir = sys_get_temp_dir() . '/xphp-builtin-via-use-' . uniqid('', true);
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

    public function testSpecializedClassFullyQualifiesBuiltinNamesFromUseImports(): void
    {
        $this->compile();

        $bagFqn = Registry::generatedFqn(
            'App\\BuiltinViaUse\\Bag',
            [new TypeRef('App\\BuiltinViaUse\\Models\\User')],
        );
        $arrayBagFqn = Registry::generatedFqn(
            'App\\BuiltinViaUse\\ArrayBag',
            [new TypeRef('App\\BuiltinViaUse\\Models\\User')],
        );

        $bagFile = $this->fqnToPath($bagFqn);
        $arrayBagFile = $this->fqnToPath($arrayBagFqn);
        self::assertFileExists($bagFile, 'specialized interface must be emitted');
        self::assertFileExists($arrayBagFile, 'specialized class must be emitted');

        $bagContent = file_get_contents($bagFile);
        $arrayBagContent = file_get_contents($arrayBagFile);

        // The bug: these names were copied bare into the generated namespace.
        self::assertStringContainsString('extends \\Countable, \\IteratorAggregate', $bagContent);
        self::assertStringContainsString('extends \\App\\BuiltinViaUse\\AbstractBag', $arrayBagContent);
        self::assertStringContainsString(', \\Countable, ', $arrayBagContent);
        self::assertStringContainsString('?\\Traversable', $arrayBagContent);
        self::assertStringContainsString('\\Countable|\\Traversable|null', $arrayBagContent);
        self::assertStringContainsString('accepts(\\App\\BuiltinViaUse\\Errors\\EmptyBagError $probe)', $arrayBagContent);
        self::assertStringContainsString('new \\ArrayIterator(', $arrayBagContent);
        self::assertStringContainsString('\\ArrayIterator::STD_PROP_LIST', $arrayBagContent);
        self::assertStringContainsString('instanceof \\Traversable', $arrayBagContent);
        self::assertStringContainsString('function (): \\Traversable {', $arrayBagContent);
        self::assertStringContainsString('const \\App\\BuiltinViaUse\\Color DEFAULT_COLOR = \\App\\BuiltinViaUse\\Color::Red', $arrayBagContent);
        self::assertStringContainsString('catch (\\App\\BuiltinViaUse\\Errors\\EmptyBagError', $arrayBagContent);
        self::assertStringContainsString('new \\App\\BuiltinViaUse\\Errors\\EmptyBagError(', $arrayBagContent);
        // The bare function call must NOT have been qualified.
        self::assertStringContainsString('\\count($this->items)', $arrayBagContent);

        $snapshotDir = __DIR__ . '/../../fixture/compile/builtin_interface_via_use/verify/testSpecializedClassFullyQualifiesBuiltinNamesFromUseImports';
        SnapshotHash::assertMatches($snapshotDir . '/Bag_User.expected.php', $bagContent);
        SnapshotHash::assertMatches($snapshotDir . '/ArrayBag_User.expected.php', $arrayBagContent);
    }

    public function testEmittedUserFileKeepsBareImportedNames(): void
    {
        // Hybrid-scope guarantee: only relocated clones are fully-qualified. The
        // non-generic PlainBag is never specialized, so its emitted output must
        // stay byte-identical to source — bare imported names + their `use` lines.
        $this->compile();

        $userFile = $this->targetDir . '/PlainBag.php';
        self::assertFileExists($userFile);
        $content = file_get_contents($userFile);

        self::assertStringContainsString('use ArrayIterator;', $content);
        self::assertStringContainsString('implements Countable', $content);
        self::assertStringContainsString('return new ArrayIterator(', $content);
        self::assertStringContainsString('catch (EmptyBagError ', $content);
        self::assertStringContainsString(': Traversable', $content);
        // No leaked fully-qualified rewrite in the user file.
        self::assertStringNotContainsString('implements \\Countable', $content);
        self::assertStringNotContainsString('new \\ArrayIterator(', $content);
        self::assertStringNotContainsString('catch (\\App\\BuiltinViaUse\\Errors\\EmptyBagError', $content);
    }

    public function testAllOutputFilesAreSyntacticallyValid(): void
    {
        $this->compile();

        $files = array_merge(
            self::globRecursive($this->targetDir, '*.php'),
            self::globRecursive($this->cacheDir . '/Generated', '*.php'),
        );
        self::assertNotEmpty($files);

        foreach ($files as $file) {
            $output = [];
            $exit = 0;
            exec('php -l ' . escapeshellarg($file) . ' 2>&1', $output, $exit);
            self::assertSame(0, $exit, "Syntax error in {$file}:\n" . implode("\n", $output));
        }
    }

    #[RunInSeparateProcess]
    public function testSpecializedClassLoadsAndResolvesBuiltinsAtRuntime(): void
    {
        $fixture = CompiledFixture::compile($this->sourceDir, 'builtin-via-use-runtime');
        $fixture->registerAutoload('App\\BuiltinViaUse\\');
        try {
            require __DIR__ . '/../../fixture/compile/builtin_interface_via_use/verify/runtime.php';
        } finally {
            $fixture->cleanup();
        }
    }

    private function compile(): void
    {
        $compiler = $this->buildCompiler();
        $sources = (new NativeFileFinder())
            ->find($this->sourceDir)
            ->filter(static fn (string $f): bool => str_ends_with($f, '.xphp'));
        $compiler->compile($sources, $this->sourceDir, $this->targetDir, $this->cacheDir);
    }

    private function fqnToPath(string $fqn): string
    {
        $prefix = Registry::GENERATED_NAMESPACE_PREFIX . '\\';
        $rel = str_starts_with($fqn, $prefix) ? substr($fqn, strlen($prefix)) : $fqn;
        return $this->cacheDir . '/Generated/' . str_replace('\\', '/', $rel) . '.php';
    }

    /**
     * @return list<string>
     */
    private static function globRecursive(string $dir, string $pattern): array
    {
        if (!is_dir($dir)) {
            return [];
        }
        $found = glob(rtrim($dir, '/') . '/' . $pattern) ?: [];
        foreach (glob(rtrim($dir, '/') . '/*', GLOB_ONLYDIR) ?: [] as $subdir) {
            $found = array_merge($found, self::globRecursive($subdir, $pattern));
        }
        return $found;
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
