<?php

declare(strict_types=1);

namespace XPHP\StaticAnalysis;

use PhpParser\ParserFactory;
use PhpParser\PrettyPrinter\Standard as StandardPrinter;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use XPHP\FileSystem\FileFinder\NativeFileFinder;
use XPHP\FileSystem\FileReader\NativeFileReader;
use XPHP\FileSystem\FileWriter\NativeFileWriter;
use XPHP\FileSystem\FilepathArray;
use XPHP\Transpiler\Monomorphize\Compiler;
use XPHP\Transpiler\Monomorphize\SpecializedClassGenerator;
use XPHP\Transpiler\Monomorphize\Specializer;
use XPHP\Transpiler\Monomorphize\XphpSourceParser;

final class CompiledWorkspaceTest extends TestCase
{
    public function testCompileEmitsDistAndGeneratedAndKeepsRegistry(): void
    {
        $root = $this->scratchRoot();
        $workspace = CompiledWorkspace::compile(
            $this->compiler(),
            $this->boxGenericSources(),
            $this->boxGenericSourceDir(),
            $root,
        );

        try {
            self::assertSame($root, $workspace->root);
            // distDir/generatedDir are canonicalized (realpath) for PHPStan path matching.
            self::assertSame(realpath($root . '/dist'), $workspace->distDir);
            self::assertSame(realpath($root . '/cache/Generated'), $workspace->generatedDir);

            // Rewritten user code landed in dist/.
            self::assertFileExists($workspace->distDir . '/Containers/Box.php');
            // Specialized classes landed under cache/Generated/.
            $generated = glob($workspace->generatedDir . '/App/BoxGeneric/Containers/Box/*.php') ?: [];
            self::assertNotEmpty($generated, 'expected specialized Box classes under generatedDir');

            // The live registry is retained for back-mapping (decl line lookup).
            self::assertNotEmpty($workspace->registry->definitions());
            self::assertNotEmpty($workspace->registry->instantiations());
        } finally {
            $workspace->cleanup();
        }
    }

    public function testCleanupRemovesTheWorkspace(): void
    {
        $root = $this->scratchRoot();
        $workspace = CompiledWorkspace::compile(
            $this->compiler(),
            $this->boxGenericSources(),
            $this->boxGenericSourceDir(),
            $root,
        );
        self::assertDirectoryExists($root);

        $workspace->cleanup();

        self::assertDirectoryDoesNotExist($root);
    }

    public function testInTempDirCreatesUniqueRootBeneathBase(): void
    {
        $base = $this->scratchRoot();
        mkdir($base, 0o755, true);

        // Pass a trailing slash so the regex (single separator) also pins rtrim().
        $a = CompiledWorkspace::inTempDir($this->compiler(), $this->boxGenericSources(), $this->boxGenericSourceDir(), $base . '/');
        $b = CompiledWorkspace::inTempDir($this->compiler(), $this->boxGenericSources(), $this->boxGenericSourceDir(), $base . '/');

        try {
            // Exactly one separator (rtrim normalises the base) then the tag and a
            // 16-hex-char suffix (bin2hex of random_bytes(8)).
            self::assertMatchesRegularExpression(
                '#^' . preg_quote($base, '#') . '/xphp-check-[0-9a-f]{16}$#',
                $a->root,
            );
            self::assertNotSame($a->root, $b->root);
            self::assertDirectoryExists($a->root);
            self::assertDirectoryExists($b->root);
        } finally {
            $a->cleanup();
            $b->cleanup();
            $this->rrmdir($base);
        }
    }

    public function testGeneratedDirFallsBackToConstructedPathWhenNotCreated(): void
    {
        // Empty sources emit no specialized classes, so cache/Generated is never
        // created and realpath() returns false — canonical() must fall back to the
        // constructed path (harmless: there are no representatives to match anyway).
        $root = $this->scratchRoot();
        $workspace = CompiledWorkspace::compile(
            $this->compiler(),
            new FilepathArray(),
            $this->boxGenericSourceDir(),
            $root,
        );

        try {
            self::assertDirectoryDoesNotExist($root . '/cache/Generated');
            self::assertSame($root . '/cache/Generated', $workspace->generatedDir);
        } finally {
            $workspace->cleanup();
        }
    }

    public function testCleanupIsIdempotentWhenRootIsAlreadyGone(): void
    {
        $root = $this->scratchRoot();
        $workspace = CompiledWorkspace::compile(
            $this->compiler(),
            new FilepathArray(),
            $this->boxGenericSourceDir(),
            $root,
        );

        $workspace->cleanup();
        self::assertDirectoryDoesNotExist($root);

        // A second cleanup hits the `!is_dir` short-circuit and must not throw.
        $workspace->cleanup();
        $this->addToAssertionCount(1);
    }

    public function testCleanupRemovesNestedTreeWithoutFollowingSymlinks(): void
    {
        // A hand-built workspace: nested files + a symlink pointing OUT of the
        // workspace. cleanup() must delete the tree and the link, but never touch
        // the link's target contents.
        $root = $this->scratchRoot();
        mkdir($root . '/a/b', 0o755, true);
        file_put_contents($root . '/a/b/file.php', '<?php');

        $external = $this->scratchRoot();
        mkdir($external, 0o755, true);
        file_put_contents($external . '/keep.txt', 'precious');
        symlink($external, $root . '/a/link-to-external');

        $workspace = CompiledWorkspace::compile(
            $this->compiler(),
            new FilepathArray(),
            $this->boxGenericSourceDir(),
            $root,
        );

        try {
            $workspace->cleanup();

            self::assertDirectoryDoesNotExist($root);
            // The symlink target and its contents must survive.
            self::assertFileExists($external . '/keep.txt');
            self::assertSame('precious', file_get_contents($external . '/keep.txt'));
        } finally {
            $this->rrmdir($external);
        }
    }

    private function compiler(): Compiler
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

    private function boxGenericSourceDir(): string
    {
        return realpath(__DIR__ . '/../fixture/compile/box_generic/source')
            ?: throw new RuntimeException('box_generic fixture missing');
    }

    private function boxGenericSources(): FilepathArray
    {
        return (new NativeFileFinder())
            ->find($this->boxGenericSourceDir())
            ->filter(static fn (string $f): bool => str_ends_with($f, '.xphp'));
    }

    private function scratchRoot(): string
    {
        return sys_get_temp_dir() . '/xphp-workspace-' . uniqid('', true);
    }

    private function rrmdir(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }
        $entries = scandir($path) ?: [];
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $full = $path . '/' . $entry;
            is_dir($full) ? $this->rrmdir($full) : unlink($full);
        }
        rmdir($path);
    }
}
