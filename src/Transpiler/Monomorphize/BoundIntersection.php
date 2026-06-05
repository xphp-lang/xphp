<?php

declare(strict_types=1);

namespace XPHP\Transpiler\Monomorphize;

/**
 * `A & B & ...` — every operand must satisfy.
 *
 * Three-way verdict combinator (over `TypeHierarchy::isSubtype` results):
 *   - any operand `false` -> false (definite failure)
 *   - all operands `true` -> true
 *   - otherwise (mix of true and null, no false) -> null (unknown)
 */
final readonly class BoundIntersection extends BoundExpr
{
    /** @var list<BoundExpr> */
    public array $operands;

    public function __construct(BoundExpr ...$operands)
    {
        $this->operands = $operands;
    }
}
