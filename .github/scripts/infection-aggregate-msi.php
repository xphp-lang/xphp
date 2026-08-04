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
 *   php .github/scripts/infection-aggregate-msi.php [minCoveredMsi] [glob] [expectedShards]
 *
 *   minCoveredMsi   Gate threshold as a percentage (default 95, or the
 *                   MIN_COVERED_MSI env var if set).
 *   glob            Glob for the summary files
 *                   (default "var/infection-summary-*.json").
 *   expectedShards  Assert exactly this many summaries were found; 0/absent
 *                   disables the check (or the EXPECTED_SHARDS env var).
 *
 * The EXPECTED_SHARDS env var, when set to a positive integer, asserts that
 * exactly that many summaries were found. The gate reports over whatever
 * shards uploaded a summary; if one shard silently produced none (empty
 * slice, a dropped `if-no-files-found: ignore` artifact, an Infection that
 * wrote no log), the aggregate would cover only a subset yet still pass. This
 * turns that missing data into an explicit failure.
 *
 * Exit code: 0 if aggregate Covered MSI >= threshold, 1 otherwise (or if no
 * summary files are found, or fewer than EXPECTED_SHARDS -- missing data must
 * never look like a pass).
 */

$min = (float) ($argv[1] ?? getenv('MIN_COVERED_MSI') ?: '95');
$glob = $argv[2] ?? 'var/infection-summary-*.json';
$expected = (int) (($argv[3] ?? '') !== '' ? $argv[3] : (getenv('EXPECTED_SHARDS') ?: '0'));

$files = glob($glob) ?: [];

if ($files === []) {
    fwrite(STDERR, "FAIL: no shard summaries matched \"{$glob}\" -- did every shard run?\n");
    exit(1);
}

echo sprintf("Found %d shard %s%s\n", count($files), count($files) === 1 ? 'summary' : 'summaries', $expected > 0 ? " (expected {$expected})" : '');

if ($expected > 0 && count($files) < $expected) {
    fwrite(STDERR, sprintf(
        "FAIL: only %d of %d expected shard summaries present -- a shard was skipped or its upload was lost; refusing to gate on partial data\n",
        count($files),
        $expected,
    ));
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

    // Guard every key we read. A missing key would emit only an E_WARNING and
    // coerce to 0 -- and a silent 0 for escapedCount shrinks the denominator,
    // inflating the aggregate MSI so the gate passes with real escapes
    // uncounted. On a gate, an unrecognised schema must fail loudly, not
    // fail open, so we bail rather than trust a partial summary.
    foreach (['killedCount', 'errorCount', 'timeOutCount', 'escapedCount'] as $key) {
        if (!array_key_exists($key, $stats)) {
            fwrite(STDERR, "FAIL: {$file} is missing stats.{$key} -- Infection schema mismatch?\n");
            exit(1);
        }
    }

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
