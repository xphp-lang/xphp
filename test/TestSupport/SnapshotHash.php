<?php

declare(strict_types=1);

namespace XPHP\TestSupport;

use PHPUnit\Framework\Assert;
use RuntimeException;

/**
 * Snapshot assertion helper that normalizes the deterministic
 * `T_<hash>` segments xphp emits into stable per-file placeholders
 * before comparing bytes.
 *
 * Why per-file numbered placeholders instead of one wildcard:
 *   distinct hashes inside a single file (e.g. dispatcher arms
 *   referencing different specializations) get distinct
 *   `T_<HASH_1>`, `T_<HASH_2>` placeholders. Identical hashes
 *   collapse to the same placeholder. A regression that swaps two
 *   hashes inside one file shifts the placeholder pairing and
 *   surfaces as a diff. A global `T_<HASH>` wildcard would have
 *   silently passed.
 *
 * Update mode: when `XPHP_UPDATE_SNAPSHOTS=1` is set in the
 * environment, a mismatch writes the actual (un-normalized) content
 * to the expected file in place. Set `CI` and the update is refused
 * with a stderr warning, so CI runs can never silently green-light
 * stale snapshots.
 *
 * Multi-snapshot gotcha: if a single test method asserts multiple
 * snapshots back-to-back, PHPUnit halts at the first failed assertion.
 * Under update mode only the first failing snapshot refreshes per run;
 * subsequent ones need re-runs to pick up.
 *
 * Compiler-output drift: if `nikic/php-parser` is bumped the pretty
 * printer's formatting may shift. A failed snapshot run after a
 * dependency bump is the expected signal to re-run with
 * `XPHP_UPDATE_SNAPSHOTS=1` and review the resulting diff.
 */
final class SnapshotHash
{
    /**
     * Compare `$actual` against the contents of `$expectedFile` after
     * normalising hash segments on both sides. Honors
     * `XPHP_UPDATE_SNAPSHOTS=1` when not running in CI.
     */
    public static function assertMatches(string $expectedFile, string $actual): void
    {
        $shouldUpdate = self::shouldUpdateSnapshots();

        if (!file_exists($expectedFile)) {
            if (!$shouldUpdate) {
                Assert::fail(sprintf(
                    "Snapshot missing: %s\n"
                    . "Run with XPHP_UPDATE_SNAPSHOTS=1 to create it"
                    . " (ignored when CI=1).",
                    $expectedFile,
                ));
            }
            self::writeSnapshot($expectedFile, $actual);
            Assert::assertTrue(true, 'snapshot created');
            return;
        }

        $expected = file_get_contents($expectedFile);
        $expectedNormalized = self::normalize($expected);
        $actualNormalized = self::normalize($actual);

        if ($expectedNormalized !== $actualNormalized && $shouldUpdate) {
            self::writeSnapshot($expectedFile, $actual);
            Assert::assertTrue(true, 'snapshot updated');
            return;
        }

        Assert::assertSame(
            $expectedNormalized,
            $actualNormalized,
            sprintf(
                "Snapshot mismatch (compared after hash normalization).\n"
                . "  expected: %s\n"
                . "  Run with XPHP_UPDATE_SNAPSHOTS=1 to refresh"
                . " (ignored when CI=1).",
                $expectedFile,
            ),
        );
    }

    /**
     * Replace each unique `T_<64 hex>` run in `$content` with a stable
     * per-file placeholder `T_<HASH_N>` (N counted by first-seen
     * order). Identical hashes collapse to the same N; distinct
     * hashes get distinct Ns. Width is pinned to exactly 64 to match
     * `Registry::canonicalHash` (full SHA-256), so a fixture literal
     * shaped `T_<short hex>` won't get caught by the normalizer.
     *
     * Caveat: first-seen-order numbering can't tell apart two hashes
     * that swap *roles* without changing order of appearance. A
     * regression of that shape needs an additional FQN-substring or
     * count assertion alongside the snapshot.
     */
    public static function normalize(string $content): string
    {
        $counter = 0;
        $map = [];

        $normalized = preg_replace_callback(
            // Right-anchor with negative lookahead so a 65+ hex run
            // doesn't get silently truncated to 64.
            '/T_[0-9a-f]{64}(?![0-9a-f])/',
            static function (array $m) use (&$counter, &$map): string {
                $hash = $m[0];
                if (!isset($map[$hash])) {
                    $counter++;
                    $map[$hash] = "T_<HASH_{$counter}>";
                }
                return $map[$hash];
            },
            $content,
        );

        // Canonicalize trailing whitespace to exactly one newline so
        // the writer's policy and the compiler's actual output match
        // regardless of where the trailing newline came from.
        return rtrim($normalized, "\r\n") . "\n";
    }

    /**
     * @infection-ignore-all -- CI guard is observable only when the
     * caller sets `XPHP_UPDATE_SNAPSHOTS=1` AND `CI=1` simultaneously,
     * a combination no test exercises. The logic is defensive against
     * a misconfigured CI workflow.
     */
    private static function shouldUpdateSnapshots(): bool
    {
        if (getenv('XPHP_UPDATE_SNAPSHOTS') !== '1') {
            return false;
        }
        if (getenv('CI')) {
            fwrite(STDERR, "[SnapshotHash] XPHP_UPDATE_SNAPSHOTS ignored: CI is set.\n");
            return false;
        }
        return true;
    }

    private static function writeSnapshot(string $expectedFile, string $actual): void
    {
        $dir = dirname($expectedFile);
        if (!is_dir($dir) && !mkdir($dir, 0o755, true) && !is_dir($dir)) {
            throw new RuntimeException(sprintf('Could not create snapshot directory: %s', $dir));
        }
        // Enforce exactly one trailing newline.
        $payload = rtrim($actual, "\n") . "\n";
        file_put_contents($expectedFile, $payload);
    }
}
