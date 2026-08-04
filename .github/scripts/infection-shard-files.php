<?php

declare(strict_types=1);

/**
 * Compute one mutation shard's slice of the source tree.
 *
 * The mutation suite is split across N parallel CI jobs ("shards"), each
 * mutating a disjoint slice of src/. This script decides which source files
 * belong to a given shard and prints them as the comma-separated list
 * Infection's `--filter` expects.
 *
 * It replaces a `find -printf | sort | awk | paste` pipeline so the split runs
 * on any OS with PHP -- `find -printf` is GNU-only and absent on macOS/BSD.
 * Doing it in PHP also makes the packing fully deterministic: files are sorted
 * before packing, so every shard job computes the identical global partition
 * regardless of the runner's filesystem iteration order, which is what keeps
 * the slices disjoint and collectively exhaustive.
 *
 * Packing: files are greedily bin-packed by byte size, largest first, into
 * `shardTotal` buckets, each file going to the currently-lightest bucket
 * (longest-processing-time scheduling). Byte size is a cheap proxy for mutant
 * count, so buckets finish in roughly equal wall time. Ties break on path so
 * the result is stable.
 *
 * Usage:
 *   php .github/scripts/infection-shard-files.php <shardTotal> <shardIndex> [srcDir]
 *
 *   shardTotal   Number of shards (>= 1).
 *   shardIndex   This shard, 0-based in [0, shardTotal).
 *   srcDir       Root to scan for *.php (default "src").
 *
 * Prints this shard's files, comma-separated, with forward slashes and no
 * trailing newline. An empty slice prints nothing (exit 0); the caller treats
 * that as "nothing to mutate".
 */

$total = (int) ($argv[1] ?? 0);
$index = (int) ($argv[2] ?? -1);
$srcDir = $argv[3] ?? 'src';

if ($total < 1 || $index < 0 || $index >= $total) {
    fwrite(STDERR, "usage: infection-shard-files.php <shardTotal> <shardIndex> [srcDir]\n");
    fwrite(STDERR, "  shardTotal >= 1, 0 <= shardIndex < shardTotal\n");
    exit(2);
}

if (!is_dir($srcDir)) {
    fwrite(STDERR, "FAIL: source directory \"{$srcDir}\" does not exist\n");
    exit(2);
}

// Collect every *.php file under srcDir with its byte size.
$files = [];
$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($srcDir, FilesystemIterator::SKIP_DOTS),
);

foreach ($iterator as $file) {
    if ($file->isFile() && $file->getExtension() === 'php') {
        // Normalise to forward slashes so --filter paths match on every OS.
        $path = str_replace(DIRECTORY_SEPARATOR, '/', $file->getPathname());
        $files[] = ['path' => $path, 'size' => $file->getSize()];
    }
}

// Largest first, ties broken by path -> identical order on every runner.
usort(
    $files,
    static fn (array $a, array $b): int => ($b['size'] <=> $a['size']) ?: strcmp($a['path'], $b['path']),
);

// LPT bin-packing: each file lands in the currently-lightest bucket.
$load = array_fill(0, $total, 0);
$buckets = array_fill(0, $total, []);

foreach ($files as $file) {
    $lightest = 0;
    for ($bucket = 1; $bucket < $total; ++$bucket) {
        if ($load[$bucket] < $load[$lightest]) {
            $lightest = $bucket;
        }
    }

    $load[$lightest] += $file['size'];
    $buckets[$lightest][] = $file['path'];
}

echo implode(',', $buckets[$index]);
