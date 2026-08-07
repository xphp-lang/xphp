<?php

declare(strict_types=1);

namespace XPHP\Transpiler\Monomorphize;

/**
 * A type-alias body in disjunctive normal form: a union of intersection-clauses.
 * Each inner list is an intersection (`A & B`); the outer list is the union
 * (`… | …`). So a single head is `[[X]]`, a flat union `[[A], [B]]`, an
 * intersection `[[A, B]]`, a DNF `[[A, B], [C]]`, and `?X` desugars to
 * `[[X], [null]]`.
 *
 * DNF is the only compound shape PHP can emit without distribution, and the alias
 * machinery never distributes: a body — or an expansion — that would require
 * `(A|B)&C → (A&C)|(B&C)` is rejected loudly instead
 * (`xphp.alias_compound_needs_distribution`).
 *
 * The same shape carries a raw (post-parse) body and a resolved one; the leaves
 * are `TypeRef`s either way. The two-condition single-head predicate lives here
 * (not inlined at each expander consumer) so it stays in one place and carries
 * mutation coverage.
 *
 * A **closure-signature** body (`type Handler = Closure(int): bool;`) is the one
 * shape a DNF cannot represent: it sets {@see $signature} (and carries no clauses).
 * Like every other compound, it is usable only as the whole type of a slot, where
 * it erases to a bare `\Closure` carrying the signature for conformance checking.
 */
final readonly class AliasBody
{
    /**
     * @param list<list<TypeRef>> $clauses  union of intersection-clauses (DNF); non-empty (with no
     *                                       empty inner clause) UNLESS this is a closure-signature body
     * @param ?ClosureSignature   $signature set for a closure-signature body; then $clauses is empty
     */
    public function __construct(
        public array $clauses,
        public ?ClosureSignature $signature = null,
    ) {
    }

    /**
     * A closure-signature body — erases to `\Closure` with the signature carried for conformance.
     * A DNF (clause) body never sets this.
     */
    public function isClosureSignature(): bool
    {
        return $this->signature !== null;
    }

    /**
     * A single (possibly-generic) head — exactly one clause with exactly one leaf. A closure-signature
     * body is never a single head. Only a single head may expand anywhere a plain type name can (a
     * generic argument, `new`, `extends`/`implements`, a bound); a compound body is representable only
     * as the whole type of a param / property / return / class-constant slot.
     */
    public function isSingleHead(): bool
    {
        return $this->signature === null && count($this->clauses) === 1 && count($this->clauses[0]) === 1;
    }

    public function isCompound(): bool
    {
        return !$this->isSingleHead();
    }

    /**
     * The sole leaf of a single-head body. Caller must have checked {@see isSingleHead()}.
     */
    public function head(): TypeRef
    {
        return $this->clauses[0][0];
    }

    /**
     * Dedupe the leaves of an intersection clause by canonical name, order-preserving (keep the first
     * occurrence). PHP rejects a duplicate type in an intersection ("Duplicate type … is redundant")
     * at parse time — a load fatal — and `A&A` ≡ `A`, so a composed alias that reintroduces a member
     * (`type Inner = A & B; type Outer = Inner & B`) collapses cleanly instead of emitting `A&B&B`.
     *
     * @param list<TypeRef> $clause
     * @return list<TypeRef>
     */
    public static function dedupeLeaves(array $clause): array
    {
        $seen = [];
        $unique = [];
        foreach ($clause as $leaf) {
            $key = $leaf->canonical();
            if (!isset($seen[$key])) {
                // @infection-ignore-all TrueValue -- $seen is a presence set read via isset(); the
                // stored value (true vs false) is never inspected, so it is unobservable.
                $seen[$key] = true;
                $unique[] = $leaf;
            }
        }
        return $unique;
    }

    /**
     * Dedupe union clauses by their order-independent member set, order-preserving. PHP rejects a
     * duplicate union arm the same way; `A|A` ≡ `A` and `A&B | B&A` ≡ `A&B`.
     *
     * @param list<list<TypeRef>> $clauses
     * @return list<list<TypeRef>>
     */
    public static function dedupeClauses(array $clauses): array
    {
        $seen = [];
        $unique = [];
        foreach ($clauses as $clause) {
            $keys = array_map(static fn (TypeRef $leaf): string => $leaf->canonical(), $clause);
            sort($keys);
            $key = implode('&', array_unique($keys));
            if (!isset($seen[$key])) {
                // @infection-ignore-all TrueValue -- $seen is a presence set read via isset(); the
                // stored value (true vs false) is never inspected, so it is unobservable.
                $seen[$key] = true;
                $unique[] = $clause;
            }
        }
        return $unique;
    }
}
