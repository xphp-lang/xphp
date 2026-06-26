<?php

declare(strict_types=1);

namespace XPHP\Transpiler\Monomorphize;

/**
 * Decides whether one specialization of a generic template is a subtype of another, per each
 * type-parameter's variance. Shared between {@see VarianceEdgeEmitter} (which turns a subtype
 * relationship into an emitted `extends` edge) and {@see SpecializationCloser} (which uses it to
 * detect when a concrete class is, by a covariant upcast, an instance of a supertype specialization
 * that carries an erased — abstract — method-generic).
 *
 * Three rules per arg-pair `(arg_i, variance_i)`:
 *   - Invariant:     arg1.canonical() == arg2.canonical()
 *   - Covariant:     isNestedSubtype(arg1, arg2)
 *   - Contravariant: isNestedSubtype(arg2, arg1)
 *
 * Scalar args are skipped — there is no PHP-level subtype relationship between scalars, so claiming
 * one would mislead the caller (the emitter would PHP-fatal at autoload; the closer would schedule a
 * spurious specialization). A mixed generic/non-generic or different-template arg-pair returns false
 * conservatively: a wrong "yes" is the expensive direction, a missed "yes" only loses a relationship
 * the compiler couldn't prove anyway.
 */
final readonly class VarianceSubtyping
{
    public function __construct(private TypeHierarchy $hierarchy)
    {
    }

    /**
     * Whether the specialization with `$args1` is a subtype of the one with `$args2`, given the
     * template's `$params`. Requires at least one arg to differ (an identical pair is not a proper
     * subtype edge — the callers filter the reflexive case upstream, but this is also defensive).
     *
     * @param list<TypeRef> $args1
     * @param list<TypeRef> $args2
     * @param list<TypeParam> $params
     */
    public function isVarianceSubtype(array $args1, array $args2, array $params, Registry $registry): bool
    {
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
            // @infection-ignore-all LogicalOr -- `||` vs `&&` are equivalent here: a scalar is never a
            // subtype of a class (and two scalars relate only by canonical equality), so whether ONE or
            // BOTH args are scalar, the canonical-equality check below yields the same verdict. The
            // mixed scalar/class case (one scalar) is rejected by both forms.
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
        // Identical args (`sp1 == sp2`) is filtered upstream, but defensively require at least one
        // differing arg before claiming a subtype edge.
        return $sawNonIdentity;
    }

    /**
     * Three-way subtype check tailored for variance reasoning.
     *
     *  - Both non-generic: delegate to {@see TypeHierarchy::isSubtype}.
     *  - Both generic of the SAME template: recurse arg-wise through THAT template's variance, so
     *    `Producer<Box<Banana>>` and `Producer<Box<Fruit>>` relate when Box has covariant T — without
     *    it, the comparison would flatten to `isSubtype('Box', 'Box') == true` and claim a relationship
     *    even when the INNER args aren't subtype-related.
     *  - Both generic of DIFFERENT templates: if `$child`'s template provably implements/extends
     *    `$parent`'s, thread `$child`'s args up to `$parent`'s template and recurse under `$parent`'s
     *    OWN variance — so a covariant container nested as a type-argument relates
     *    (`ImmutableList<Book>` as `Collection<Product>` → `ImmutableList implements Collection`, thread
     *    to `Collection<Book>`, then `Book ⊑ Product` under `Collection`'s covariant `E`). Stays
     *    conservative (no edge) unless the relationship is POSITIVELY grounded.
     *  - Otherwise (one generic, one not): conservative false.
     *
     * Trust model — the fatal-vs-missed-edge asymmetry: a wrong "yes" emits a bogus `implements` edge
     * that PHP-fatals at autoload, so emit only on a positive `isSubtype(...) === true` AND a threaded
     * arg tuple the recursion's arity guard ({@see isVarianceSubtype}'s `count()` check) accepts —
     * `resolveInheritedArgs` can return a NON-null but wrong-arity tuple (a bare or over-supplied
     * parameterized super), so that recursion is load-bearing, not inert reuse. A missed "yes" only
     * loses a relationship the compiler couldn't positively prove.
     *
     * @infection-ignore-all UnwrapLtrim -- the `ltrim('\\')` calls are no-ops: registry/canonical names
     * never carry a leading backslash (the same defensive the hierarchy collector documents), so
     * unwrapping them is equivalent. The meaningful guards — `isSubtype(...) === true`, the same- vs
     * different-template routing, and the inner recursion — stay mutation-covered by VarianceSubtypingTest.
     */
    private function isNestedSubtype(TypeRef $child, TypeRef $parent, Registry $registry): bool
    {
        if (!$child->isGeneric() && !$parent->isGeneric()) {
            return $this->hierarchy->isSubtype($child->name, $parent->name) === true;
        }
        $childName = ltrim($child->name, '\\');
        $parentName = ltrim($parent->name, '\\');
        if ($child->isGeneric() && $parent->isGeneric() && $childName === $parentName) {
            $innerDef = $registry->definition($childName);
            $innerParams = $innerDef !== null ? $innerDef->typeParams : [];
            // @infection-ignore-all ReturnRemoval -- equivalent: removing this early return falls
            // through to isVarianceSubtype() with empty params, whose arity guard
            // (count(args) !== count(params)) returns false for the same non-empty args. Defensive.
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
        // Different-template generics: emit only if child's template provably implements/extends
        // parent's. Thread child's args up to parent's template (the same helper SpecializationCloser
        // uses) and recurse under parent's OWN params. resolveInheritedArgs may return a non-null
        // wrong-arity tuple, so the recursion's count() guard is what rejects the malformed case -- never
        // trust $threaded for being merely non-null.
        if ($child->isGeneric() && $parent->isGeneric()
            && $this->hierarchy->isSubtype($childName, $parentName) === true
        ) {
            $threaded = $this->hierarchy->resolveInheritedArgs($childName, $child->args, $parentName);
            $parentDef = $registry->definition($parentName);
            if ($threaded !== null && $parentDef !== null && $parentDef->typeParams !== []) {
                return $this->isVarianceSubtype($threaded, $parent->args, $parentDef->typeParams, $registry);
            }
        }
        return false;
    }
}
