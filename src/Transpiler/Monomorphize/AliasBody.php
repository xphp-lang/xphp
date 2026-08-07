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
 * A leaf is a `TypeRef` OR a `ClosureSignature`. A signature leaf erases to a bare
 * `\Closure` (carrying the signature on `ATTR_CLOSURE_SIG` for conformance), so it
 * can appear anywhere a type can — as the whole body (`Closure(int): bool`), a
 * nullable clause (`?Closure(...)` ≡ `[[sig], [null]]`), or a member of a union /
 * intersection (`Foo | Closure(...)`). Because every signature erases to the SAME
 * `\Closure`, at most one `\Closure`-erasing leaf may appear in a body (two would
 * emit a `\Closure|\Closure` / `\Closure&\Closure` PHP duplicate-type fatal).
 *
 * The single-head / dedup predicates live here (not inlined at each expander
 * consumer) so they stay in one place and carry mutation coverage.
 */
final readonly class AliasBody
{
    /**
     * @param list<list<TypeRef|ClosureSignature>> $clauses union of intersection-clauses (DNF);
     *                                                       non-empty, with no empty inner clause
     */
    public function __construct(
        public array $clauses,
    ) {
    }

    /**
     * A single (possibly-generic) head — exactly one clause with exactly one leaf that is a `TypeRef`.
     * A signature leaf is never a single head (a `\Closure` is compound, whole-slot only). Only a single
     * head may expand anywhere a plain type name can (a generic argument, `new`, `extends`/`implements`,
     * a bound); a compound body is representable only as the whole type of a slot.
     */
    public function isSingleHead(): bool
    {
        return count($this->clauses) === 1
            && count($this->clauses[0]) === 1
            && $this->clauses[0][0] instanceof TypeRef;
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
        $head = $this->clauses[0][0];
        assert($head instanceof TypeRef);

        return $head;
    }

    /**
     * The number of leaves in the whole body that erase to `\Closure` — a closure signature, or a bare
     * `\Closure` TypeRef. Every one emits the identical `\Closure` type, so a body with two of them
     * would emit a `\Closure|\Closure` / `\Closure&\Closure` PHP duplicate-type fatal and is rejected.
     */
    public function closureLeafCount(): int
    {
        $count = 0;
        foreach ($this->clauses as $clause) {
            foreach ($clause as $leaf) {
                if (self::isClosureErasing($leaf)) {
                    $count++;
                }
            }
        }

        return $count;
    }

    /**
     * Whether a leaf erases to a bare `\Closure` — a {@see ClosureSignature}, or a `\Closure`-named
     * TypeRef (case-insensitive, fully-qualified or not).
     */
    private static function isClosureErasing(TypeRef|ClosureSignature $leaf): bool
    {
        // @infection-ignore-all UnwrapLtrim -- a bare `\Closure` TypeRef may reach here fully-qualified
        // (leading backslash) or not, depending on the resolution path; the ltrim normalizes both. Its
        // removal is unobservable only when the name is already backslash-free, so it is defensive.
        return $leaf instanceof ClosureSignature
            || (!$leaf->isGeneric() && ltrim(strtolower($leaf->name), '\\') === 'closure');
    }

    /**
     * The dedup key of a leaf: every signature (and every `\Closure` TypeRef) keys to `\Closure`; a
     * plain TypeRef keys to its canonical name.
     */
    private static function leafKey(TypeRef|ClosureSignature $leaf): string
    {
        return $leaf instanceof ClosureSignature ? '\\Closure' : $leaf->canonical();
    }

    /**
     * Dedupe the leaves of an intersection clause by key, order-preserving (keep the first occurrence).
     * PHP rejects a duplicate type in an intersection ("Duplicate type … is redundant") at parse time —
     * a load fatal — and `A&A` ≡ `A`, so a composed alias that reintroduces a member
     * (`type Inner = A & B; type Outer = Inner & B`) collapses cleanly instead of emitting `A&B&B`.
     *
     * @param list<TypeRef|ClosureSignature> $clause
     * @return list<TypeRef|ClosureSignature>
     */
    public static function dedupeLeaves(array $clause): array
    {
        $seen = [];
        $unique = [];
        foreach ($clause as $leaf) {
            $key = self::leafKey($leaf);
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
     * @param list<list<TypeRef|ClosureSignature>> $clauses
     * @return list<list<TypeRef|ClosureSignature>>
     */
    public static function dedupeClauses(array $clauses): array
    {
        $seen = [];
        $unique = [];
        foreach ($clauses as $clause) {
            $keys = array_map(static fn (TypeRef|ClosureSignature $leaf): string => self::leafKey($leaf), $clause);
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
