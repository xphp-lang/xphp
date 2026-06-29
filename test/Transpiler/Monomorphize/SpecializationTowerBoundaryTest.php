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
     * Normalize a tower diagnostic for exact comparison: drop the worked example (its nesting depth
     * depends on the exact fixed-point order) and replace the environment-specific absolute source path
     * with `<SRC>`, so the remainder can be asserted as a whole string.
     */
    private function normalizeTowerMessage(string $message, string $sourceDir): string
    {
        $withoutExample = preg_replace('/ — e\.g\. "[^"]*"/', '', $message) ?? $message;
        return str_replace($sourceDir, '<SRC>', $withoutExample);
    }

    /**
     * The exact expected divergence diagnostic (sans worked example) for a List <-> Map tower, naming the
     * two concrete cycle classes in the order the diagnostic lists them, each tagged with its file.
     */
    private function expectedTowerMessage(
        string $first,
        string $firstFile,
        string $second,
        string $secondFile,
    ): string {
        return 'Generic specialization did not converge (exceeded depth 16): a self-reintroducing cycle '
            . 'grows without bound through ' . $first . ' (' . $firstFile . ') and ' . $second . ' ('
            . $secondFile . '). This happens when a member\'s type re-wraps the receiver\'s own type '
            . 'family in a growing form (for example `groupBy(): Map<L, List<E>>`, where Map\'s views '
            . 're-expose List). Break the cycle: return a non-self-reintroducing type from the re-exposing '
            . 'member (for example a non-generic iterable), or split the derivation so the growing type '
            . 'isn\'t reached through an unbounded chain.';
    }

    /**
     * Iterating the groups through the map's covariant `values()` view re-exposes a list-of-lists, whose
     * own `groupBy` re-wraps one level deeper each pass — an unbounded `List -> Map -> List -> ...` tower.
     * The depth cap aborts it fast with the localized diagnostic. The whole message is asserted: it names
     * both concrete cycle classes (Map-then-List order for this fixture) with their files, and nothing
     * else — no interface/abstract supertypes dragged in.
     */
    public function testGroupByThenValuesViewTowersAndAbortsWithLocalizedDiagnostic(): void
    {
        $src = realpath(__DIR__ . '/../../fixture/compile/reachable_groupby_then_values/source');
        self::assertIsString($src);
        $message = $this->towerMessage($src, 'tower-values');

        self::assertSame(
            $this->expectedTowerMessage(
                'App\\ImmutableMap',
                '<SRC>/ImmutableMap.xphp',
                'App\\ImmutableList',
                '<SRC>/ImmutableList.xphp',
            ),
            $this->normalizeTowerMessage($message, $src),
        );
        // The worked example is the DEEPEST type (many nested lists) — pins deepest, not shallowest.
        self::assertGreaterThanOrEqual(
            5,
            substr_count($message, 'App\\ImmutableList<'),
            'the worked example should be the deeply-nested deepest instantiation',
        );
    }

    /**
     * The `entries()` view re-exposes the list behind an `Entry<K, V>`, re-seeding the same tower by a
     * longer path. Same controlled abort; the whole message is asserted, here in List-then-Map order.
     */
    public function testGroupByThenEntriesViewTowersAndAbortsWithLocalizedDiagnostic(): void
    {
        $src = realpath(__DIR__ . '/../../fixture/compile/reachable_groupby_then_entries/source');
        self::assertIsString($src);
        $message = $this->towerMessage($src, 'tower-entries');

        self::assertSame(
            $this->expectedTowerMessage(
                'App\\ImmutableList',
                '<SRC>/ImmutableList.xphp',
                'App\\ImmutableMap',
                '<SRC>/ImmutableMap.xphp',
            ),
            $this->normalizeTowerMessage($message, $src),
        );
        self::assertGreaterThanOrEqual(
            5,
            substr_count($message, 'App\\ImmutableList<'),
            'the worked example should be the deeply-nested deepest instantiation',
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
            // The incompatibility is specifically between the two covariant value-list specializations.
            // The generated FQN is a pure function of the type arguments (a sha256 of their canonical
            // form), so it is deterministic and computed here via the production hasher rather than
            // hard-coded — pinning that it is the `ImmutableList<Book>` vs `ImmutableList<Media>` override
            // that fatals, not merely "some" incompatibility. (Which method PHP reports first is link
            // order, but both candidates return the value list, so both FQNs always appear.)
            $bookList = Registry::generatedFqn('App\\ImmutableList', [new TypeRef('App\\Book')]);
            $mediaList = Registry::generatedFqn('App\\ImmutableList', [new TypeRef('App\\Media')]);
            self::assertStringContainsString($bookList, $output, 'names the ImmutableList<Book> spec');
            self::assertStringContainsString($mediaList, $output, 'names the ImmutableList<Media> spec');
        } finally {
            $fixture->cleanup();
        }
    }
}
