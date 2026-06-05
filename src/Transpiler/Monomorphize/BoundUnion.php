<?php

declare(strict_types=1);

namespace XPHP\Transpiler\Monomorphize;

/**
 * `A | B | ...` — any operand suffices.
 *
 * Three-way verdict combinator:
 *   - any operand `true` -> true (definite success)
 *   - all operands `false` -> false
 *   - otherwise (mix of false and null, no true) -> null (unknown)
 *
 * DNF builds as a `BoundUnion(BoundIntersection(...), BoundIntersection(...), ...)` —
 * the natural outer-OR-of-inner-ANDs nesting.
 */
final readonly class BoundUnion extends BoundExpr
{
    /** @var list<BoundExpr> */
    public array $operands;

    public function __construct(BoundExpr ...$operands)
    {
        $this->operands = $operands;
    }
}
