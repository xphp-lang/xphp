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
     * Compile a fixture expected to tower, and return the abort message. Fails the test if it does not
     * abort. `compile()` rmdir's its work dir on throw, so there is nothing to clean up.
     */
    private function towerMessage(string $sourceDir, string $tag): string
    {
        try {
            CompiledFixture::compile($sourceDir, $tag);
        } catch (RuntimeException $e) {
            return $e->getMessage();
        }
        self::fail('expected the specialization tower to abort, but compilation succeeded');
    }

    /**
     * Iterating the groups through the map's covariant `values()` view re-exposes a list-of-lists, whose
     * own `groupBy` re-wraps one level deeper each pass — an unbounded `List -> Map -> List -> ...` tower.
     * The depth cap aborts it fast with the localized diagnostic, which names every family in the cycle
     * and the source file each is defined in, rather than hanging or exhausting memory.
     */
    public function testGroupByThenValuesViewTowersAndAbortsWithLocalizedDiagnostic(): void
    {
        $message = $this->towerMessage(
            __DIR__ . '/../../fixture/compile/reachable_groupby_then_values/source',
            'tower-values',
        );

        self::assertStringContainsString('did not converge', $message);
        // Both families of the List <-> Map cycle are named, each with its defining source file.
        self::assertStringContainsString('App\\ImmutableMap', $message);
        self::assertStringContainsString('App\\ImmutableList', $message);
        self::assertStringContainsString('ImmutableMap.xphp', $message);
        self::assertStringContainsString('ImmutableList.xphp', $message);
    }

    /**
     * The `entries()` view re-exposes the list behind an `Entry<K, V>`, re-seeding the same tower by a
     * longer path. Same controlled abort, naming the cycle families and their files.
     */
    public function testGroupByThenEntriesViewTowersAndAbortsWithLocalizedDiagnostic(): void
    {
        $message = $this->towerMessage(
            __DIR__ . '/../../fixture/compile/reachable_groupby_then_entries/source',
            'tower-entries',
        );

        self::assertStringContainsString('did not converge', $message);
        self::assertStringContainsString('App\\ImmutableMap', $message);
        self::assertStringContainsString('App\\ImmutableList', $message);
        self::assertStringContainsString('ImmutableMap.xphp', $message);
        self::assertStringContainsString('ImmutableList.xphp', $message);
    }

    /**
     * Pin the full divergence diagnostic structure: the header + depth, every concrete class in the
     * cycle named with its defining file, the absence of the interface/abstract supertypes that are
     * merely specialized alongside the growing classes, the deepest worked example, and the actionable
     * guidance. The message IS the feature here, so it is asserted exhaustively.
     */
    public function testDivergenceMessageNamesTheCycleClassesTheirFilesExampleAndGuidance(): void
    {
        $m = $this->towerMessage(
            __DIR__ . '/../../fixture/compile/reachable_groupby_then_values/source',
            'tower-message',
        );

        // Header + the depth cap value.
        self::assertStringContainsString('Generic specialization did not converge (exceeded depth 16):', $m);
        self::assertStringContainsString('a self-reintroducing cycle grows without bound through', $m);

        // Both concrete cycle classes, each joined with " and " and tagged with its defining file.
        self::assertStringContainsString('App\\ImmutableMap (', $m);
        self::assertStringContainsString('ImmutableMap.xphp)', $m);
        self::assertStringContainsString('App\\ImmutableList (', $m);
        self::assertStringContainsString('ImmutableList.xphp)', $m);
        self::assertStringContainsString(' and ', $m);

        // The interface/abstract supertypes are specialized alongside the growing classes but do NOT
        // construct the deeper values, so they must be filtered out of the named families.
        self::assertStringNotContainsString('App\\Map', $m);
        self::assertStringNotContainsString('OrderedCollection', $m);
        self::assertStringNotContainsString('AbstractImmutableCollection', $m);
        self::assertStringNotContainsString('Collection.xphp', $m);

        // The worked example is the DEEPEST type: rooted at the family, with multi-level nesting (which
        // also pins that the *deepest* instantiation is chosen, not the shallowest).
        self::assertStringContainsString('App\\ImmutableMap<string,', $m);
        self::assertStringContainsString(
            'App\\ImmutableList<App\\ImmutableList<App\\ImmutableList<',
            $m,
        );

        // The standalone-cause sentence and the actionable guidance. Each assertion spans a boundary
        // between the message's literal chunks, so a reordering of those chunks (not just a deletion) is
        // also caught.
        self::assertStringContainsString('re-wraps the receiver\'s own type family in a growing form', $m);
        self::assertStringContainsString("List<E>>`, where Map's views re-expose List", $m);
        self::assertStringContainsString('non-self-reintroducing type from the re-exposing member', $m);
        self::assertStringContainsString('non-generic iterable', $m);
        self::assertStringContainsString(
            "split the derivation so the growing type isn't reached through an unbounded chain",
            $m,
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
