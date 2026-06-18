<?php

declare(strict_types=1);

namespace XPHP\StaticAnalysis;

use XPHP\FileSystem\FilepathArray;
use XPHP\Transpiler\Monomorphize\Compiler;
use XPHP\Transpiler\Monomorphize\Registry;

/**
 * A throwaway directory holding one full compile of the sources — the concrete,
 * PHPStan-analysable PHP that monomorphization produces. PHPStan never sees the
 * `.xphp` generic sugar, so the gate compiles to a temp workspace and runs over:
 *   - `dist/`             — the rewritten user `.php` (scanned for symbol resolution),
 *   - `cache/Generated/`  — the specialized classes (the concrete generic code to analyse).
 *
 * The live `Registry` is kept so findings in a generated class can be mapped back
 * to the originating template declaration (generatedFqn → templateFqn → decl line).
 *
 * Always pair with `cleanup()` in a `finally` — the workspace is ephemeral.
 */
final readonly class CompiledWorkspace
{
    /**
     * The cache subdirectory specialized classes are emitted under, mirroring the
     * PSR-4 root for {@see Registry::GENERATED_NAMESPACE_PREFIX} (`XPHP\Generated\`).
     */
    private const string GENERATED_SUBDIR = 'Generated';

    private function __construct(
        public string $root,
        public string $distDir,
        public string $generatedDir,
        public Registry $registry,
    ) {
    }

    /** Compile into a fresh, uniquely-named directory beneath $tmpBase. */
    public static function inTempDir(
        Compiler $compiler,
        FilepathArray $sources,
        string $sourceDir,
        string $tmpBase,
    ): self {
        $root = rtrim($tmpBase, '/') . '/xphp-check-' . bin2hex(random_bytes(8));

        return self::compile($compiler, $sources, $sourceDir, $root);
    }

    /** Compile into an explicit $root (deterministic; used by tests). */
    public static function compile(
        Compiler $compiler,
        FilepathArray $sources,
        string $sourceDir,
        string $root,
    ): self {
        $distDir = $root . '/dist';
        $cacheDir = $root . '/cache';

        // The Compiler's writer creates intermediate directories as it emits, so
        // $root needs no pre-creation; an empty source set simply writes nothing.
        $result = $compiler->compile($sources, $sourceDir, $distDir, $cacheDir);

        // Canonicalize the analysable dirs: PHPStan reports findings under
        // realpath()'d paths (symlinks resolved, e.g. macOS /var -> /private/var),
        // so the representative file paths built from these must be canonical too
        // or the finding->representative join silently misses (a false clean pass).
        return new self(
            $root,
            self::canonical($distDir),
            self::canonical($cacheDir . '/' . self::GENERATED_SUBDIR),
            $result->registry,
        );
    }

    /** realpath() the dir if it exists; otherwise keep the constructed path (no files to match). */
    private static function canonical(string $dir): string
    {
        $real = realpath($dir);

        return $real !== false ? $real : $dir;
    }

    /** Recursively delete the workspace. Safe to call when $root was never created. */
    public function cleanup(): void
    {
        self::removeRecursively($this->root);
    }

    private static function removeRecursively(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        $entries = scandir($path);
        if ($entries === false) {
            return;
        }

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $full = $path . '/' . $entry;
            // Check is_link() BEFORE is_dir(): is_dir() follows a symlink to a
            // directory, so recursing on it would delete the link TARGET's contents
            // (outside the workspace). Always just unlink links.
            if (is_link($full)) {
                unlink($full);
            } elseif (is_dir($full)) {
                self::removeRecursively($full);
            } else {
                unlink($full);
            }
        }

        rmdir($path);
    }
}
