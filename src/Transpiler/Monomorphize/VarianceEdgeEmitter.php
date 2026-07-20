<?php

declare(strict_types=1);

namespace XPHP\Transpiler\Monomorphize;

use PhpParser\Node\Name\FullyQualified;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\Node\Stmt\Interface_;

/**
 * Emits subtype edges between specializations of the same generic template
 * based on each type-param's variance.
 *
 * For a template `Producer<out T>` with `Banana <: Fruit` at the PHP class level,
 * this adds `extends Producer_Fruit_<hash>` to the cloned `Producer_Banana`
 * Class_ node.
 *
 * The subtype relationship per arg-pair (invariant: equal; covariant/contravariant:
 * nested subtype) is decided by {@see VarianceSubtyping}, shared with the
 * specialization closer. A wrong edge would PHP-fatal at autoload, while a missed
 * edge only loses an `instanceof` relationship the compiler couldn't prove — so the
 * subtype check is conservative (the bound check `Registry::evaluateBound` treats null
 * verdicts as "reject"; the variance check treats them as "skip edge" for that same
 * fatal-vs-missed-edge asymmetry).
 *
 * Edge shape:
 *   - Class_ specialization -> `extends <super>` (single, since PHP allows
 *     only one class inheritance), and ONLY when the specialization has no source
 *     `extends` of its own — a class with a source parent keeps it (single inheritance;
 *     overwriting would sever the inherited member bodies). If multiple "direct" supers
 *     exist (unrelated diamond), the lexicographically-first generated FQN wins
 *     deterministically.
 *   - Interface_ specialization -> `extends <super1>, <super2>, ...`
 *     (multi-target; PHP interfaces support it).
 *
 * Transitive supers are filtered: `Banana <: Apple <: Fruit` produces
 * `Banana -> Apple` only (Fruit reached transitively via Apple).
 *
 * Runs as a dedicated Compiler phase between specialization (Phase 2) and
 * CallSiteRewriter (Phase 3) -- the fixed-point loop must finish before
 * pairwise variance comparisons can run.
 */
final class VarianceEdgeEmitter
{
    private readonly VarianceSubtyping $subtyping;

    public function __construct(TypeHierarchy $hierarchy)
    {
        $this->subtyping = new VarianceSubtyping($hierarchy);
    }

    /**
     * @param array<string, ClassLike> $specializedAsts keyed by generated FQCN
     */
    public function emitEdges(array $specializedAsts, Registry $registry): void
    {
        // Group specializations by template.
        $byTemplate = [];
        foreach ($registry->instantiations() as $generatedFqn => $instantiation) {
            $byTemplate[$instantiation->templateFqn][] = $instantiation;
        }

        foreach ($byTemplate as $templateFqn => $instantiations) {
            $definition = $registry->definition($templateFqn);
            if ($definition === null) {
                continue;
            }
            if (!self::hasNonInvariantParam($definition->typeParams)) {
                // Pure invariant template -- no variance edges possible.
                continue;
            }

            foreach ($instantiations as $sp1) {
                $ast = $specializedAsts[$sp1->generatedFqn] ?? null;
                if ($ast === null) {
                    continue;
                }

                // Collect all candidates: supers of sp1 (sp1 <: sp2).
                $candidates = [];
                foreach ($instantiations as $sp2) {
                    if ($sp1->generatedFqn === $sp2->generatedFqn) {
                        continue;
                    }
                    if ($this->subtyping->isVarianceSubtype(
                        $sp1->concreteTypes,
                        $sp2->concreteTypes,
                        $definition->typeParams,
                        $registry,
                    )) {
                        $candidates[] = $sp2;
                    }
                }

                // Filter to direct supers: sp2 is direct if no other sp3 in
                // candidates is itself a super of sp2 (i.e. sp3 sits between
                // sp1 and sp2 in the chain, so sp2 is reached transitively).
                $direct = $this->filterDirectSupers($candidates, $definition->typeParams, $registry);

                self::addImplementsEdges($ast, $direct);
            }
        }
    }

    /**
     * @param list<GenericInstantiation> $candidates
     * @param list<TypeParam> $params
     * @return list<GenericInstantiation>
     */
    private function filterDirectSupers(array $candidates, array $params, Registry $registry): array
    {
        $direct = [];
        foreach ($candidates as $sp2) {
            $impliedByAnother = false;
            foreach ($candidates as $sp3) {
                if ($sp2->generatedFqn === $sp3->generatedFqn) {
                    continue;
                }
                // sp3 is a more-specific super than sp2 if sp3 <: sp2 -- then
                // sp2 is reached transitively via sp3, so sp2 isn't direct.
                if ($this->subtyping->isVarianceSubtype(
                    $sp3->concreteTypes,
                    $sp2->concreteTypes,
                    $params,
                    $registry,
                )) {
                    $impliedByAnother = true;
                    break;
                }
            }
            if (!$impliedByAnother) {
                $direct[] = $sp2;
            }
        }
        return $direct;
    }

    /**
     * @param list<TypeParam> $params
     */
    private static function hasNonInvariantParam(array $params): bool
    {
        foreach ($params as $param) {
            if ($param->variance !== Variance::Invariant) {
                return true;
            }
        }
        return false;
    }


    /**
     * Class_ specializations get a single `extends` (PHP allows one parent class).
     * If multiple unrelated supers exist after direct-super filtering, the
     * lexicographically-first generated FQN wins deterministically.
     *
     * Interface_ specializations get multi-target `extends`.
     *
     * @param list<GenericInstantiation> $directSupers
     */
    private static function addImplementsEdges(ClassLike $ast, array $directSupers): void
    {
        if ($directSupers === []) {
            return;
        }
        // Deterministic ordering by generated FQN keeps the output stable
        // across runs (input order can vary depending on hash collisions and
        // file-walk traversal).
        usort(
            $directSupers,
            static fn (GenericInstantiation $a, GenericInstantiation $b): int
                => strcmp($a->generatedFqn, $b->generatedFqn),
        );

        if ($ast instanceof Class_) {
            // PHP allows a class exactly ONE parent. A specialized class that already carries a source
            // `extends` (e.g. `class ListColl<out E> extends AbstractColl<E>` → `ListColl_Book extends
            // AbstractColl_Book`) must keep it: that parent carries the inherited member bodies and the
            // source-declared `is-a` relationships. A same-template covariant super
            // (`ListColl<Book> <: ListColl<Product>`) cannot ALSO be a direct parent under single
            // inheritance, so we do not overwrite — the source parent wins, and the covariant *leaf*
            // edge is dropped (a missed `instanceof`, never a fatal; the covariant relationship still
            // holds transitively through the parent-less base chain, which is where erased members are
            // carried down). Overwriting would sever the source parent and silently drop the inherited
            // member — a class-load / undefined-method fatal. See also the specialization closer, which
            // hard-fails the rarer case where the dropped edge would itself have carried an erased impl.
            if ($ast->extends !== null) {
                return;
            }
            $ast->extends = new FullyQualified($directSupers[0]->generatedFqn);
            return;
        }
        if ($ast instanceof Interface_) {
            foreach ($directSupers as $sp) {
                $ast->extends[] = new FullyQualified($sp->generatedFqn);
            }
        }
    }
}
