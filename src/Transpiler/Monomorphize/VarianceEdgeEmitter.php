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
 * For a template `Producer<+T>` with `Banana <: Fruit` at the PHP class level,
 * this adds `extends Producer_Fruit_<hash>` to the cloned `Producer_Banana`
 * Class_ node.
 *
 * Three rules per arg-pair `(arg_i, variance_i)`:
 *   - Invariant:    arg1.canonical() == arg2.canonical()
 *   - Covariant:    isNestedSubtype(arg1, arg2)
 *   - Contravariant: isNestedSubtype(arg2, arg1)
 *
 * Scalar args are skipped -- there's no PHP-level subtype relationship
 * between scalars, so emitting an edge would PHP-fatal at autoload.
 *
 * `isNestedSubtype` handles both leaf (non-generic) classes and nested
 * generic instantiations of the same template (recurses through the inner
 * template's variance). Different templates or mixed forms return false
 * conservatively -- a wrong edge would PHP-fatal at autoload, while a
 * missed edge only loses an `instanceof` relationship the compiler couldn't
 * prove. The bound check (`Registry::evaluateBound`) treats null verdicts
 * as "reject"; the variance check treats them as "skip edge" for that same
 * fatal-vs-missed-edge asymmetry.
 *
 * Edge shape:
 *   - Class_ specialization -> `extends <super>` (single, since PHP allows
 *     only one class inheritance). If multiple "direct" supers exist
 *     (unrelated diamond), the lexicographically-first generated FQN wins
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
    public function __construct(private readonly TypeHierarchy $hierarchy)
    {
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
                    if ($this->isVarianceSubtype(
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
                if ($this->isVarianceSubtype(
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
     * @param list<TypeRef> $args1
     * @param list<TypeRef> $args2
     * @param list<TypeParam> $params
     */
    private function isVarianceSubtype(
        array $args1,
        array $args2,
        array $params,
        Registry $registry,
    ): bool {
        if (count($args1) !== count($args2) || count($args1) !== count($params)) {
            return false;
        }
        $sawNonIdentity = false;
        foreach ($args1 as $i => $a1) {
            $a2 = $args2[$i];
            $variance = $params[$i]->variance;

            if ($a1->canonical() !== $a2->canonical()) {
                $sawNonIdentity = true;
            }

            // Scalar args never participate in variance edges.
            if ($a1->isScalar || $a2->isScalar) {
                if ($a1->canonical() !== $a2->canonical()) {
                    return false;
                }
                continue;
            }

            if ($variance === Variance::Invariant) {
                if ($a1->canonical() !== $a2->canonical()) {
                    return false;
                }
                continue;
            }
            if ($variance === Variance::Covariant) {
                if (!$this->isNestedSubtype($a1, $a2, $registry)) {
                    return false;
                }
                continue;
            }
            // Contravariant
            if (!$this->isNestedSubtype($a2, $a1, $registry)) {
                return false;
            }
        }
        // sp1 == sp2 (identical args) is filtered upstream, but defensively:
        // require at least one arg to differ before claiming a subtype edge.
        return $sawNonIdentity;
    }

    /**
     * Three-way subtype check tailored for variance edge emission.
     *
     *  - Both non-generic: delegate to TypeHierarchy::isSubtype.
     *  - Both generic of the SAME template: recurse arg-wise through THAT
     *    template's variance. This is what makes `Producer<Box<Banana>>`
     *    and `Producer<Box<Fruit>>` produce an edge when Box has covariant
     *    T -- without it, the comparison would flatten to
     *    `isSubtype('Box', 'Box') == true` and emit a wrong edge for the
     *    case where the INNER args aren't subtype-related.
     *  - Otherwise (different templates, or one generic one not):
     *    conservative false. A wrong edge would PHP-fatal at autoload.
     */
    private function isNestedSubtype(TypeRef $child, TypeRef $parent, Registry $registry): bool
    {
        if (!$child->isGeneric() && !$parent->isGeneric()) {
            return $this->hierarchy->isSubtype($child->name, $parent->name) === true;
        }
        if ($child->isGeneric() && $parent->isGeneric()
            && ltrim($child->name, '\\') === ltrim($parent->name, '\\')
        ) {
            $innerDef = $registry->definition(ltrim($child->name, '\\'));
            $innerParams = $innerDef !== null ? $innerDef->typeParams : [];
            if ($innerParams === []) {
                return false;
            }
            return $this->isVarianceSubtype(
                $child->args,
                $parent->args,
                $innerParams,
                $registry,
            );
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
            // `extends` (e.g. `class ListColl<+E> extends AbstractColl<E>` → `ListColl_Book extends
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
