<?php

declare(strict_types=1);

namespace XPHP\Config;

use RuntimeException;
use XPHP\FileSystem\FileFinder;
use XPHP\FileSystem\FilepathArray;
use XPHP\FileSystem\FileReader;

/**
 * Resolves an `xphp.json` manifest into the full set of `.xphp` source roots to compile: the
 * package's own `sources` plus every transitively-`include`d package. `include` entries may be
 * globs (`*`/`?`/`[…]` per segment, via native `glob`; recursive `**` is rejected) — matched
 * directories that contain an `xphp.json` are pulled in and others skipped (vendor over-matches by
 * design), while an explicit (non-glob) entry lacking an `xphp.json` is a hard error.
 *
 * The walk dedups by realpath (diamonds resolve once) and is cycle-safe (a↔b terminates). Paths and
 * globs are resolved against each manifest's own directory. `target`/`cache` come from the entry
 * manifest only.
 *
 * Manifest *content* is read through the injected {@see FileReader} and `.xphp` files enumerated via
 * {@see FileFinder}; directory discovery (globbing, existence) uses the native filesystem.
 */
final class ManifestResolver
{
    public const MANIFEST_FILENAME = 'xphp.json';

    public function __construct(
        private readonly FileReader $reader,
        private readonly FileFinder $finder,
        private readonly ManifestParser $parser = new ManifestParser(),
    ) {
    }

    /**
     * @param string $entry path to an `xphp.json`, or a directory containing one.
     * @throws RuntimeException if no manifest is found, a source dir is missing, an explicit
     *   include lacks a manifest, or a manifest is invalid.
     */
    public function resolve(string $entry): ResolvedSources
    {
        $entryPath = $this->manifestPathFor($entry);
        $entryManifest = $this->parseFile($entryPath);

        $visited = [];
        $seenRoots = [];
        /** @var array<string,string> $rootByFile */
        $rootByFile = [];
        $this->walk($entryPath, $entryManifest, $visited, $seenRoots, $rootByFile);

        $entryDir = dirname($entryPath);

        return new ResolvedSources(
            new FilepathArray(...array_keys($rootByFile)),
            $rootByFile,
            $entryManifest->target !== null ? self::join($entryDir, $entryManifest->target) : null,
            $entryManifest->cache !== null ? self::join($entryDir, $entryManifest->cache) : null,
        );
    }

    /**
     * @param array<string,true> $visited realpath of each visited manifest (dedup + cycle guard)
     * @param array<string,true> $seenRoots realpath of each enumerated source root (dedup)
     * @param array<string,string> $rootByFile accumulated filepath → root
     */
    private function walk(string $manifestPath, Manifest $manifest, array &$visited, array &$seenRoots, array &$rootByFile): void
    {
        $key = self::realKey($manifestPath);
        if (isset($visited[$key])) {
            return;
        }
        // @infection-ignore-all TrueValue -- dedup/cycle-guard keys on isset(), which tests key
        // existence not value, so the assigned literal is immaterial (cycle + diamond tests pin it).
        $visited[$key] = true;
        $dir = dirname($manifestPath);

        foreach ($manifest->sources as $src) {
            $root = self::join($dir, $src);
            if (!is_dir($root)) {
                throw new RuntimeException(sprintf(
                    'Invalid %s: source "%s" is not a directory (%s).',
                    $manifestPath,
                    $src,
                    $root,
                ));
            }
            $rootKey = self::realKey($root);
            if (isset($seenRoots[$rootKey])) {
                continue;
            }
            // @infection-ignore-all TrueValue -- root dedup keys on isset() (existence, not value),
            // so the assigned literal is immaterial; the diamond test pins single-enumeration.
            $seenRoots[$rootKey] = true;
            foreach ($this->finder->find($root)->filepaths as $file) {
                if (str_ends_with($file, '.xphp')) {
                    $rootByFile[$file] = $root;
                }
            }
        }

        foreach ($manifest->include as $entry) {
            $isGlob = self::isGlob($entry);
            $dirs = $isGlob ? $this->expandGlob($dir, $entry) : [self::join($dir, $entry)];
            foreach ($dirs as $candidate) {
                $childManifest = $candidate . '/' . self::MANIFEST_FILENAME;
                if (!is_file($childManifest)) {
                    if ($isGlob) {
                        continue; // a glob over-matches non-xphp dirs — skip those silently.
                    }
                    throw new RuntimeException(sprintf(
                        'Invalid %s: include "%s" has no %s (%s).',
                        $manifestPath,
                        $entry,
                        self::MANIFEST_FILENAME,
                        $childManifest,
                    ));
                }
                $this->walk($childManifest, $this->parseFile($childManifest), $visited, $seenRoots, $rootByFile);
            }
        }
    }

    /** Resolve $entry (a manifest file or a dir containing one) to a manifest filepath. */
    private function manifestPathFor(string $entry): string
    {
        if (is_file($entry)) {
            return $entry;
        }
        if (is_dir($entry)) {
            $path = $entry . '/' . self::MANIFEST_FILENAME;
            if (is_file($path)) {
                return $path;
            }
            throw new RuntimeException(sprintf('No %s found in %s.', self::MANIFEST_FILENAME, $entry));
        }
        throw new RuntimeException(sprintf('Manifest path does not exist: %s.', $entry));
    }

    private function parseFile(string $manifestPath): Manifest
    {
        return $this->parser->parse($this->reader->read($manifestPath), $manifestPath);
    }

    /**
     * Expand a glob (relative to $base) to the directories it matches, via the native libc glob
     * with GLOB_ONLYDIR (so only directories are returned, sorted). Single-star segments are
     * supported, so composer's flat `vendor/<org>/<pkg>` layout is matched by a two-segment star
     * glob under `vendor`. Recursive double-star is intentionally unsupported — rejected with a
     * clear message rather than silently mis-handled.
     *
     * @return list<string>
     */
    private function expandGlob(string $base, string $pattern): array
    {
        if (str_contains($pattern, '**')) {
            throw new RuntimeException(sprintf(
                'Invalid include glob "%s": "**" is not supported — use "*" per path segment (e.g. "vendor/*/*").',
                $pattern,
            ));
        }
        $matches = glob(self::join($base, $pattern), GLOB_ONLYDIR);

        return $matches === false ? [] : $matches;
    }

    private static function isGlob(string $s): bool
    {
        return strpbrk($s, '*?[') !== false;
    }

    /** Join $rel onto $base unless $rel is already absolute; `.` resolves to $base. */
    private static function join(string $base, string $rel): string
    {
        if (str_starts_with($rel, '/')) {
            return $rel;
        }
        if ($rel === '.' || $rel === './') {
            return $base;
        }

        return $base . '/' . $rel;
    }

    /** realpath() for stable dedup keys, falling back to the raw path when it doesn't resolve. */
    private static function realKey(string $path): string
    {
        $real = realpath($path);

        return $real !== false ? $real : $path;
    }
}
