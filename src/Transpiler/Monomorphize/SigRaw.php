<?php

declare(strict_types=1);

namespace XPHP\Transpiler\Monomorphize;

/**
 * A signature leaf that was scanned and erased but could NOT be structured into a
 * {@see SigUnion} / {@see SigIntersection} / {@see SigTypeRef} — the defensive
 * fallback. Flat unions/intersections/nullables now structure, so this remains
 * only for the residual shapes: a target-side DNF `(A&B)|C` (the token scanner
 * doesn't split nested parens), a member that fails to resolve, or an
 * intersection carrying a scalar member. The engine treats it as gradual (accept),
 * so it only has to survive erasure without losing the bytes for the diagnostic.
 */
final readonly class SigRaw extends SigType
{
    public function __construct(
        public string $raw,
    ) {
    }
}
