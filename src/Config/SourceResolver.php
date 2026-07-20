<?php

declare(strict_types=1);

namespace XPHP\Config;

use RuntimeException;
use XPHP\FileSystem\FileFinder;

/**
 * Resolves a command's source set into a {@see ResolvedSources}, choosing between the two input
 * modes in precedence order:
 *
 *   1. an explicit positional **source directory** (single-dir, back-compatible form);
 *   2. an explicit **`--config`** manifest (an `xphp.json` file or a dir containing one);
 *   3. an auto-detected **`xphp.json`** in the working directory.
 *
 * The single-dir mode synthesises a trivial `ResolvedSources` (every `.xphp` under the dir, each
 * rooted at that dir, no manifest `target`/`cache`); manifest mode delegates to {@see ManifestResolver}.
 */
final class SourceResolver
{
    public function __construct(
        private readonly FileFinder $finder,
        private readonly ManifestResolver $manifestResolver,
    ) {
    }

    /**
     * @throws RuntimeException when the source dir is missing, or no source provider is given.
     */
    public function resolve(?string $sourceDir, ?string $config, string $workingDir): ResolvedSources
    {
        if ($sourceDir !== null && $sourceDir !== '') {
            if (!is_dir($sourceDir)) {
                throw new RuntimeException(sprintf('Source directory not found: %s', $sourceDir));
            }

            return $this->fromDirectory($sourceDir);
        }

        $manifest = $config ?? $this->autodetect($workingDir);
        if ($manifest === null) {
            throw new RuntimeException(
                'No sources to compile: pass a source directory, --config <xphp.json>, or add an xphp.json to the working directory.',
            );
        }

        return $this->manifestResolver->resolve($manifest);
    }

    private function fromDirectory(string $dir): ResolvedSources
    {
        $files = $this->finder->find($dir)
            ->filter(static fn (string $filepath): bool => str_ends_with($filepath, '.xphp'));

        $rootByFile = [];
        // @infection-ignore-all -- in single-dir mode this map just mirrors `$dir`, which is also
        // Compiler::compile's scalar-base fallback, so an empty map emits to the identical paths;
        // the map is load-bearing only for manifest multi-root (covered in CompileCommandTest).
        foreach ($files->filepaths as $filepath) {
            $rootByFile[$filepath] = $dir;
        }

        return new ResolvedSources($files, $rootByFile, null, null);
    }

    private function autodetect(string $workingDir): ?string
    {
        $candidate = $workingDir . '/' . ManifestResolver::MANIFEST_FILENAME;

        return is_file($candidate) ? $candidate : null;
    }
}
