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
 */
final readonly class AliasBody
{
    /**
     * @param list<list<TypeRef>> $clauses union of intersection-clauses (DNF); never empty,
     *                                     and no inner clause is empty
     */
    public function __construct(public array $clauses)
    {
    }

    /**
     * A single (possibly-generic) head — exactly one clause with exactly one leaf.
     * Only this shape may expand anywhere a plain type name can (a generic argument,
     * `new`, `extends`/`implements`, a bound); a compound body is representable only
     * as the whole type of a param / property / return / class-constant slot.
     */
    public function isSingleHead(): bool
    {
        return count($this->clauses) === 1 && count($this->clauses[0]) === 1;
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
}
