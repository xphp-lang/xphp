<?php

declare(strict_types=1);

namespace XPHP\Transpiler\Monomorphize;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use XPHP\TestSupport\CompiledFixture;

/**
 * Characterization of the boundary where a derivation's result type re-exposes the receiver's own type
 * family in a growing form (a list-to-map derivation whose map re-exposes the list). Such a program does
 * NOT compile, and that is the accepted behavior: the decision is to diagnose and have the author
 * restructure (give the result a view-less type / split the derivation), not to make this source compile.
 * The test pins HOW it fails — fast, loud, and bounded by the depth cap, with a localized diagnostic that
 * names every concrete class in the cycle and its source file — so a regression that turned the controlled
 * failure into a hang, an OOM, or a silently-wrong build would be caught. A `dyn`-style erased seam that
 * would make the natural source compile is deferred (see ADR-0020).
 *
 * The fixture is a minimal two-template cycle (`Lst<out E>::toMap(): Mp<int, Lst<E>>` +
 * `Mp<K, out V>::values(): Lst<V>`) — small enough to reach the depth cap quickly under any memory config.
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
     * The exact expected divergence diagnostic (sans worked example), naming the two concrete cycle
     * classes in the order the diagnostic lists them, each tagged with its file.
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
     * The whole diagnostic is asserted: the depth-cap header, both concrete cycle classes (Mp-then-Lst
     * order for this fixture) each tagged with its source file, and the actionable guidance — and nothing
     * else (no interface/abstract supertypes dragged in). The deepest worked example is pinned separately
     * by its nesting depth (which also proves the *deepest* instantiation is chosen, not the shallowest).
     */
    public function testSelfReintroducingDerivationTowersAndNamesTheCycle(): void
    {
        $src = realpath(__DIR__ . '/../../fixture/compile/self_reintroducing_tower/source');
        self::assertIsString($src);
        $message = $this->towerMessage($src, 'tower-min');

        self::assertSame(
            $this->expectedTowerMessage('App\\Mp', '<SRC>/Mp.xphp', 'App\\Lst', '<SRC>/Lst.xphp'),
            $this->normalizeTowerMessage($message, $src),
        );
        self::assertGreaterThanOrEqual(
            5,
            substr_count($message, 'App\\Lst<'),
            'the worked example should be the deeply-nested deepest instantiation',
        );
    }
}
