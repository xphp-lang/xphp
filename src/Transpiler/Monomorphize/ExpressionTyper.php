<?php

declare(strict_types=1);

namespace XPHP\Transpiler\Monomorphize;

use PhpParser\Node\Expr;

/**
 * Resolves the concrete static {@see TypeRef} of an argument expression, for the benefit of
 * {@see TypeInference}. It is the single seam through which the pure unifier reaches the two
 * kinds of type knowledge it needs:
 *
 *  - self-contained shapes — literals (`5` → int) and `new X(...)` — which {@see LiteralTyper}
 *    resolves without any surrounding context;
 *  - flow-dependent shapes — a `$var`, `$this->prop`, or a call return — which only the
 *    monomorphizer's receiver/scope tracker can answer, and only inside a method body.
 *
 * Returning null means "cannot determine a concrete type here"; the inference driver then leaves
 * that argument's parameter unconstrained (which, if it was the only witness for a required type
 * parameter, makes the whole inference fall back to today's explicit-turbofish requirement). An
 * implementation must never invent a type it cannot prove — an unknown expression is null, never a
 * guess.
 */
interface ExpressionTyper
{
    /** The concrete static type of `$expr`, or null when it cannot be determined. */
    public function typeOf(Expr $expr): ?TypeRef;
}
