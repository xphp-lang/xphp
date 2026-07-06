<?php

declare(strict_types=1);

namespace XPHP\Transpiler\Monomorphize;

/**
 * Decides whether a candidate closure signature conforms to a target
 * `Closure(...)` type, using the same variance the engine enforces for an
 * inherited method against its prototype (RFC §2.4): parameters contravariant,
 * return covariant, by-reference exact, arity compatible.
 *
 * The check is one-directional: it only ever reports a *provable* violation.
 * Anything unproven — an unresolved class, a still-abstract type parameter, an
 * untyped (⇒ `mixed`) leaf, a not-yet-structured union/intersection ({@see
 * SigRaw}), a pseudo-type the leaf table does not decide — is accepted. This
 * mirrors the RFC's runtime leniency ("lenient while unresolved, decide when
 * provable, never falsely reject") and, for the static model, keeps a build from
 * failing on code a stricter analyzer could not prove wrong.
 *
 * Pure: no I/O, no diagnostics. {@see ClosureConformanceValidator} wires it to
 * source sites and turns a returned {@see ClosureConformanceViolation} into a
 * diagnostic.
 */
final readonly class ClosureSignatureConformance
{
    /**
     * The four *true* scalar type names. `true`/`false` are normalized to `bool`
     * before comparison. These are the only leaves whose mismatch (by name) is a
     * provable violation on its own.
     */
    private const TRUE_SCALARS = ['int', 'string', 'float', 'bool'];

    /**
     * Reserved-word leaves that are `isScalar`-flagged (they live in
     * {@see XphpSourceParser::SCALAR_TYPES}) but are NOT true scalars — tops,
     * bottoms, pseudo-types, and late-static leaves. `TypeHierarchy::isSubtype`
     * returns a hard `false` for these against a non-equal bound, so routing them
     * through it would false-reject; the engine treats them as gradual (accept).
     */
    private const GRADUAL_LEAVES = [
        'mixed', 'object', 'void', 'never', 'null',
        'array', 'iterable', 'callable', 'self', 'static', 'parent',
    ];

    public function __construct(private TypeHierarchy $hierarchy)
    {
    }

    /**
     * Structural conformance only — arity and by-reference. These rules are
     * independent of how any type parameter grounds, so they can be checked once
     * at an abstract generic template. Returns the first violation or null.
     */
    public function checkStructural(ClosureSignature $candidate, ClosureSignature $target): ?ClosureConformanceViolation
    {
        return $this->structural($candidate, $target);
    }

    /**
     * Full conformance — structural plus the contravariant-parameter /
     * covariant-return leaf relations. Returns the first violation or null
     * (conforms, or the mismatch is not provable ⇒ gradually accepted).
     */
    public function check(ClosureSignature $candidate, ClosureSignature $target): ?ClosureConformanceViolation
    {
        $structural = $this->structural($candidate, $target);
        if ($structural !== null) {
            return $structural;
        }

        // Parameters: contravariant. Each candidate parameter must be the same as
        // or WIDER than the target's — i.e. the target's type must be a subtype of
        // the candidate's. Only positions the target defines are constrained.
        $targetParams = $target->params;
        $candidateParams = $candidate->params;
        foreach ($targetParams as $i => $targetParam) {
            $candidateParam = $candidateParams[$i]
                ?? $this->variadicTailOf($candidateParams);
            if ($candidateParam === null) {
                continue;
            }
            if ($this->provablyNotSubtype($targetParam->type, $candidateParam->type)) {
                return new ClosureConformanceViolation(
                    ClosureConformanceViolation::KIND_PARAM_TYPE,
                    sprintf(
                        'parameter %d: %s is not wider than %s',
                        $i + 1,
                        self::display($candidateParam->type),
                        self::display($targetParam->type),
                    ),
                );
            }
        }

        // Return: covariant. The candidate's return must be the same as or
        // NARROWER than the target's — candidate return must be a subtype of the
        // target return. An absent return on either side is gradual (top).
        if ($candidate->return !== null && $target->return !== null
            && $this->provablyNotSubtype($candidate->return, $target->return)
        ) {
            return new ClosureConformanceViolation(
                ClosureConformanceViolation::KIND_RETURN_TYPE,
                sprintf(
                    'return type: %s is not a subtype of %s',
                    self::display($candidate->return),
                    self::display($target->return),
                ),
            );
        }

        return null;
    }

    private function structural(ClosureSignature $candidate, ClosureSignature $target): ?ClosureConformanceViolation
    {
        $targetRequired = self::requiredArity($target);
        $candidateHasVariadic = self::hasVariadic($candidate);
        $candidateMax = $candidateHasVariadic ? PHP_INT_MAX : count($candidate->params);
        $candidateRequired = self::requiredArity($candidate);

        // Too few: the candidate cannot accept every argument the target requires.
        if ($candidateMax < $targetRequired) {
            return new ClosureConformanceViolation(
                ClosureConformanceViolation::KIND_ARITY_TOO_FEW,
                sprintf('expects at least %d parameter(s), candidate accepts at most %d', $targetRequired, $candidateMax),
            );
        }

        // Requires too many: the candidate demands arguments the target does not
        // guarantee (a legal minimal call on the target would under-supply it).
        if ($candidateRequired > $targetRequired) {
            return new ClosureConformanceViolation(
                ClosureConformanceViolation::KIND_ARITY_REQUIRES_MORE,
                sprintf('requires %d parameter(s) but the target guarantees only %d', $candidateRequired, $targetRequired),
            );
        }

        // A variadic target may pass unboundedly many arguments; only a variadic
        // candidate can absorb them.
        if (self::hasVariadic($target) && !$candidateHasVariadic) {
            return new ClosureConformanceViolation(
                ClosureConformanceViolation::KIND_VARIADIC_REQUIRED,
                'target is variadic; candidate must declare a variadic parameter to absorb the tail',
            );
        }

        // By-reference: exact, per position the target defines.
        foreach ($target->params as $i => $targetParam) {
            $candidateParam = $candidate->params[$i] ?? self::variadicTailOf($candidate->params);
            if ($candidateParam === null) {
                continue;
            }
            if ($candidateParam->byRef !== $targetParam->byRef) {
                return new ClosureConformanceViolation(
                    ClosureConformanceViolation::KIND_BYREF,
                    sprintf(
                        'parameter %d: by-reference-ness must match exactly (target %s, candidate %s)',
                        $i + 1,
                        $targetParam->byRef ? 'by-ref' : 'by-value',
                        $candidateParam->byRef ? 'by-ref' : 'by-value',
                    ),
                );
            }
        }

        return null;
    }

    /**
     * True only when it is *provable* that `$sub` is not a subtype of `$super`
     * (⇒ a violation). Every unproven or gradual case returns false (accept).
     *
     * A nested closure leaf ({@see SigClosure}) recurses: `$sub`'s params must be
     * contravariant and its return covariant relative to `$super` — i.e. `$sub`
     * conforms as a candidate to `$super` as a target.
     */
    private function provablyNotSubtype(SigType $sub, SigType $super): bool
    {
        // A raw (unstructured) leaf on either side stays gradual — a target-side DNF
        // `(A&B)|C`, an unresolved member, or an intersection carrying a scalar all
        // fall back to SigRaw and are accepted here.
        // @infection-ignore-all LogicalOr ReturnRemoval — equivalent: a SigRaw on
        // either side yields false down EVERY downstream path anyway (the compound
        // arms recurse to leaf pairings; a raw against a leaf bottoms out at the
        // defensive non-SigTypeRef guard, against a closure super at the
        // closure-vs-leaf mismatch — all false). The early return only states the
        // gradual rule directly; the accept BEHAVIOUR is pinned by the DNF-group
        // and raw-leaf accept tests.
        if ($sub instanceof SigRaw || $super instanceof SigRaw) {
            return false;
        }

        // ---- Compound leaves. Decompose the SUB side FIRST: doing the super side
        // first would false-reject `int|string <: string|int` (the resulting
        // `∧ᵥ∨ᵤ` over-approximates the sound `∨ᵤ∧ᵥ`). ----

        // sub = union: `A|B <: Y` ⟺ EVERY member <: Y ⇒ provably-not iff SOME member is.
        if ($sub instanceof SigUnion) {
            foreach ($sub->members as $member) {
                if ($this->provablyNotSubtype($member, $super)) {
                    return true;
                }
            }
            return false;
        }

        // sub = intersection: soundly `A&B <: Y` ⟺ `A<:Y ∨ B<:Y`, but an intersection
        // of incompatible members is uninhabited (`never`, a subtype of everything)
        // and there is no inhabitation check here — decomposing would false-reject.
        // Kept gradual; the inhabited-intersection reject is a tracked follow-up.
        // @infection-ignore-all — equivalent: a SigIntersection sub yields false down
        // EVERY downstream path anyway. A leaf super reaches the defensive non-SigTypeRef
        // guard below (false); a closure super resolves false at the closure-vs-leaf branch
        // (the intersection sub is no SigClosure); a compound super's arms recurse on this
        // same SigIntersection sub against each leaf member, and each of those recursions
        // bottoms out at one of those false results, so the union AND / intersection OR both
        // resolve to false too. The explicit early return only states the rule directly.
        // The accept BEHAVIOUR is pinned by the sub-intersection accept tests.
        if ($sub instanceof SigIntersection) {
            return false;
        }

        // super = union: `X <: A|B` ⟺ X <: SOME member ⇒ provably-not iff vs EVERY member.
        if ($super instanceof SigUnion) {
            foreach ($super->members as $member) {
                if (!$this->provablyNotSubtype($sub, $member)) {
                    return false;
                }
            }
            return true;
        }

        // super = intersection: `X <: A&B` ⟺ X <: EVERY member ⇒ provably-not iff vs SOME.
        if ($super instanceof SigIntersection) {
            foreach ($super->members as $member) {
                if ($this->provablyNotSubtype($sub, $member)) {
                    return true;
                }
            }
            // @infection-ignore-all — no member proved a violation; the defensive
            // non-SigTypeRef guard below returns the same false for this SigIntersection
            // super, so removing this explicit gradual return is equivalent.
            return false;
        }

        if ($sub instanceof SigClosure && $super instanceof SigClosure) {
            return $this->check($sub->signature, $super->signature) !== null;
        }

        // A closure vs. a plain leaf: a closure value is provably not a true
        // scalar; anything else (object / callable / Closure / a class / gradual)
        // is accepted.
        if ($sub instanceof SigClosure || $super instanceof SigClosure) {
            $leaf = $sub instanceof SigTypeRef ? $sub : ($super instanceof SigTypeRef ? $super : null);
            return $leaf !== null && $this->classify($leaf->type) === 'scalar';
        }

        // @infection-ignore-all — defensive: SigRaw, every compound (union/
        // intersection), and both SigClosure cases are handled above, so by here
        // both operands are always SigTypeRef; this guard never fires.
        if (!$sub instanceof SigTypeRef || !$super instanceof SigTypeRef) {
            return false;
        }

        $subKind = $this->classify($sub->type);
        $superKind = $this->classify($super->type);

        if ($subKind === 'gradual' || $superKind === 'gradual') {
            return false;
        }
        // @infection-ignore-all — the `&& scalar` second operand is equivalent: a
        // scalar-vs-class pair falls through to the mixed-kind `return true` below
        // with the identical verdict, so weakening this conjunction changes nothing.
        if ($subKind === 'scalar' && $superKind === 'scalar') {
            return self::normalizeScalar($sub->type->name) !== self::normalizeScalar($super->type->name);
        }
        if ($subKind === 'class' && $superKind === 'class') {
            // Provable only when BOTH classes are known to the hierarchy:
            //  - An undeclared class on either side leaves the relation unprovable;
            //    `isSubtype(knownChild, undeclaredParent)` returns a hard `false`
            //    (the BFS never reaches the unknown), which must NOT read as a
            //    proven non-subtype or out-of-source code would be false-rejected.
            if (!$this->hierarchy->isDeclared($sub->type->name)
                || !$this->hierarchy->isDeclared($super->type->name)
            ) {
                return false;
            }
            // A BUILT-IN target is normally unprovable-as-`false`: the hierarchy
            // models only ancestor edges scanned from source, so `isSubtype`
            // returns `false` for a real relation like `Exception <: Throwable`
            // (or any user class whose ancestry passes through a built-in).
            // EXCEPTION — a candidate whose ENTIRE ancestry is closed-world user
            // code cannot reach any built-in over unmodeled edges, so `false` IS
            // a proof there... unless PHP adds the edge implicitly at runtime:
            //  - `Stringable` is auto-implemented by any class with __toString()
            //    (methods are not modeled — stay gradual);
            //  - `UnitEnum` / `BackedEnum` are enum-implicit (enums carry these
            //    edges explicitly since the collector models them, so their chain
            //    is never built-in-free — this arm is a safety net).
            if ($this->hierarchy->isBuiltin($super->type->name)) {
                // @infection-ignore-all UnwrapLtrim — resolved TypeRef names arrive
                // backslash-free from resolveAgainstContext on both sides; the trim
                // is defensive parity with isBuiltin's own normalization.
                $superName = ltrim($super->type->name, '\\');
                if (in_array($superName, ['Stringable', 'UnitEnum', 'BackedEnum'], true)
                    || !$this->hierarchy->hasClosedUserAncestry($sub->type->name)
                ) {
                    return false;
                }
            }
            return $this->hierarchy->isSubtype($sub->type->name, $super->type->name) === false;
        }

        // Mixed scalar-vs-class: a true scalar is provably not a class, and a class
        // is provably not a scalar.
        return true;
    }

    /**
     * `scalar` (a true scalar), `class` (a resolved user/builtin class name), or
     * `gradual` (a top/bottom/pseudo/late-static leaf, or a still-abstract type
     * parameter) — for which no mismatch is ever provable.
     */
    private function classify(TypeRef $ref): string
    {
        if ($ref->isTypeParam) {
            return 'gradual';
        }
        $name = self::normalizeScalar($ref->name);
        if (in_array($name, self::TRUE_SCALARS, true)) {
            return 'scalar';
        }
        if (in_array($name, self::GRADUAL_LEAVES, true)) {
            return 'gradual';
        }
        return 'class';
    }

    private static function normalizeScalar(string $name): string
    {
        // @infection-ignore-all — ltrim/strtolower are defensive: closure-sig leaf
        // TypeRefs arrive already unqualified and lowercased from the resolver, so
        // dropping either normalization is unobservable for real inputs.
        $name = strtolower(ltrim($name, '\\'));
        return $name === 'true' || $name === 'false' ? 'bool' : $name;
    }

    /**
     * The number of parameters a signature *requires* — the leading run of
     * parameters that are neither optional (a candidate literal's defaulted
     * parameter) nor variadic. A `Closure(...)` type has no defaults, so for a
     * target this is simply its non-variadic parameter count.
     */
    private static function requiredArity(ClosureSignature $sig): int
    {
        $count = 0;
        foreach ($sig->params as $param) {
            if ($param->variadic || $param->optional) {
                break;
            }
            $count++;
        }
        return $count;
    }

    private static function hasVariadic(ClosureSignature $sig): bool
    {
        foreach ($sig->params as $param) {
            if ($param->variadic) {
                return true;
            }
        }
        return false;
    }

    /**
     * The variadic parameter of a list, if the position being matched runs past
     * the fixed parameters into a variadic tail (which absorbs the rest).
     *
     * @param list<ClosureSignatureParam> $params
     */
    private static function variadicTailOf(array $params): ?ClosureSignatureParam
    {
        foreach ($params as $param) {
            if ($param->variadic) {
                return $param;
            }
        }
        return null;
    }

    private static function display(SigType $type): string
    {
        if ($type instanceof SigTypeRef) {
            return $type->type->toDisplayString();
        }
        if ($type instanceof SigClosure) {
            return 'Closure(...)';
        }
        if ($type instanceof SigUnion) {
            return implode('|', array_map(self::display(...), $type->members));
        }
        if ($type instanceof SigIntersection) {
            return implode('&', array_map(self::display(...), $type->members));
        }
        return $type instanceof SigRaw ? $type->raw : '?';
    }
}
