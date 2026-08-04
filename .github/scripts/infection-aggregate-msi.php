<?php

declare(strict_types=1);

/**
 * Aggregate the per-shard Infection summary JSON files into a single,
 * project-wide Covered MSI and enforce the mutation gate once over the
 * whole run.
 *
 * The mutation suite is split across N parallel CI jobs ("shards"), each
 * mutating a disjoint slice of src/ and writing an
 * `--logger-summary-json` file. Covered MSI is
 *
 *     (killed + errored + timed-out) / covered-mutants
 *
 * i.e. a ratio, so the true project-wide figure is the sum of the shards'
 * numerators over the sum of their denominators -- NOT the mean of their
 * per-shard percentages (which would over/under-weight small shards). This
 * script reconstructs that exact ratio, so the distributed gate is
 * numerically identical to the single-machine `--min-covered-msi` gate.
 *
 * Usage:
 *   php .github/scripts/infection-aggregate-msi.php [minCoveredMsi] [glob]
 *
 *   minCoveredMsi  Gate threshold as a percentage (default 95, or the
 *                  MIN_COVERED_MSI env var if set).
 *   glob           Glob for the summary files
 *                  (default "var/infection-summary-*.json").
 *
 * Exit code: 0 if aggregate Covered MSI >= threshold, 1 otherwise (or if no
 * summary files are found -- a missing shard must never look like a pass).
 */

$min = (float) ($argv[1] ?? getenv('MIN_COVERED_MSI') ?: '95');
$glob = $argv[2] ?? 'var/infection-summary-*.json';

$files = glob($glob) ?: [];

if ($files === []) {
    fwrite(STDERR, "FAIL: no shard summaries matched \"{$glob}\" -- did every shard run?\n");
    exit(1);
}

$killed = 0;   // killed by tests + static analysis
$errored = 0;  // mutant caused a fatal error -> counts as killed
$timedOut = 0; // mutant hung past the timeout -> counts as killed
$escaped = 0;  // covered but survived -> the mutants that lower MSI

foreach ($files as $file) {
    $decoded = json_decode((string) file_get_contents($file), true);

    if (!is_array($decoded) || !isset($decoded['stats'])) {
        fwrite(STDERR, "FAIL: {$file} is not a valid Infection summary JSON\n");
        exit(1);
    }

    $stats = $decoded['stats'];
    $killed += $stats['killedCount'];
    $errored += $stats['errorCount'];
    $timedOut += $stats['timeOutCount'];
    $escaped += $stats['escapedCount'];
}

$numerator = $killed + $errored + $timedOut;
$covered = $numerator + $escaped;

// A run with zero covered mutants (e.g. every shard's slice was fully
// ignored) has nothing to measure; treat it as a vacuous pass rather than a
// divide-by-zero. In practice the packer keeps every shard non-trivial.
$msi = $covered > 0 ? 100.0 * $numerator / $covered : 100.0;

$shards = count($files);
$line = sprintf(
    'Aggregate Covered MSI: %.2f%% (%d/%d covered mutants killed across %d shards; %d escaped)',
    $msi,
    $numerator,
    $covered,
    $shards,
    $escaped,
);

echo $line, "\n";

// Surface the headline on the GitHub Actions run summary when available.
$summaryPath = getenv('GITHUB_STEP_SUMMARY');
if (is_string($summaryPath) && $summaryPath !== '') {
    $status = $msi >= $min ? '✅ PASS' : '❌ FAIL';
    file_put_contents(
        $summaryPath,
        sprintf("### Mutation gate: %s\n\n%s (gate: %.2f%%)\n", $status, $line, $min),
        FILE_APPEND,
    );
}

if ($msi < $min) {
    fwrite(STDERR, sprintf("FAIL: Covered MSI %.2f%% is below the %.2f%% gate\n", $msi, $min));
    exit(1);
}

echo sprintf("PASS: Covered MSI %.2f%% meets the %.2f%% gate\n", $msi, $min);
exit(0);
