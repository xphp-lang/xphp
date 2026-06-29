<?php

declare(strict_types=1);

namespace XPHP\Transpiler\Monomorphize;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use XPHP\TestSupport\CompiledFixture;

/**
 * Characterization of the boundary where a covariant collection's grouping derivation re-exposes its own
 * type family. These programs do NOT compile-and-run as written, and that is the accepted behavior: the
 * decision is to diagnose and have the author restructure the re-exposing member's body (return a
 * non-generic iterable / split the derivation), not to make this exact source compile. The tests pin
 * HOW it fails (fast, loud, and bounded — not a hang or OOM), so a regression that turned the controlled
 * failure into a hang, an OOM, or a silently-wrong build would be caught. The planned change is a more
 * precise diagnostic (firing in `check`, naming the member), which sharpens the message without altering
 * these outcomes; a `dyn`-style erased seam that would make the natural source compile is deferred.
 *
 * The shape is the faithful collections lattice: a covariant `ImmutableList<+E>` with
 * `groupBy<L>(): ImmutableMap<L, ImmutableList<E>>`, and `ImmutableMap<K, +V>` whose views
 * (`values()`/`entries()`) re-expose the value as a list.
 */
final class SpecializationTowerBoundaryTest extends TestCase
{
    /**
     * Iterating the groups through the map's covariant `values()` view re-exposes a list-of-lists, whose
     * own `groupBy` re-wraps one level deeper each pass — an unbounded `List -> Map -> List -> ...` tower.
     * The depth cap aborts it fast with the localized diagnostic naming the runaway type family, rather
     * than hanging or exhausting memory.
     */
    public function testGroupByThenValuesViewTowersAndAbortsWithLocalizedDiagnostic(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/did not converge.*App\\\\ImmutableMap/s');

        // compile() rmdir's its work dir on throw, so there is nothing to clean up here.
        CompiledFixture::compile(
            __DIR__ . '/../../fixture/compile/reachable_groupby_then_values/source',
            'tower-values',
        );
    }

    /**
     * The `entries()` view re-exposes the list behind an `Entry<K, V>`, re-seeding the same tower by a
     * longer path. Same controlled abort.
     */
    public function testGroupByThenEntriesViewTowersAndAbortsWithLocalizedDiagnostic(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/did not converge.*App\\\\ImmutableMap/s');

        CompiledFixture::compile(
            __DIR__ . '/../../fixture/compile/reachable_groupby_then_entries/source',
            'tower-entries',
        );
    }

    /**
     * Grouping at two subtype-related element types (`Book` <: `Media`) produces variance-related map
     * specializations, so a covariant override edge should form on a view method — but under single
     * inheritance that covariant leaf edge is dropped, so the build is CLEAN yet the generated overrides
     * are incompatible at PHP class-load time. The load fatal is non-catchable, so it is observed from a
     * child process: the compile succeeds in-process, then loading the specs fatals with a
     * "must be compatible with" declaration error.
     */
    public function testGroupByAcrossSubtypeRelatedElementsCompilesButFatalsAtLoad(): void
    {
        $fixture = CompiledFixture::compile(
            __DIR__ . '/../../fixture/compile/reachable_groupby_subtype_elements/source',
            'tower-subtype',
        );
        try {
            // The build itself is clean — that is precisely the trap this case documents.
            self::assertNotEmpty(
                glob($fixture->cacheDir . '/Generated/App/ImmutableMap/T_*.php') ?: [],
                'the subtype-related grouping compiles to ImmutableMap specializations',
            );

            // Force the generated specs to load in a child process; the covariant override is
            // incompatible, so PHP fatals at class-link time.
            $runner = __DIR__
                . '/../../fixture/compile/reachable_groupby_subtype_elements/verify/load_is_incompatible.php';
            $cmd = sprintf(
                '%s %s %s %s 2>&1',
                escapeshellarg(PHP_BINARY),
                escapeshellarg($runner),
                escapeshellarg($fixture->targetDir),
                escapeshellarg($fixture->cacheDir),
            );
            $output = (string) shell_exec($cmd);

            self::assertStringNotContainsString('LOADED_OK', $output, 'the specs must not load cleanly');
            self::assertStringContainsString(
                'must be compatible with',
                $output,
                'loading fatals on the incompatible covariant override',
            );
        } finally {
            $fixture->cleanup();
        }
    }
}
