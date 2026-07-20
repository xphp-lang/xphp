<?php

declare(strict_types=1);

namespace XPHP\Transpiler\Monomorphize;

/**
 * A type appearing as a leaf inside a closure signature — a closure parameter
 * type or a closure return type (see {@see ClosureSignature}).
 *
 * A signature leaf is a recursive sum because a closure signature can itself be
 * a parameter or return type: `Closure(Closure(int): int): int`. A plain
 * `TypeRef` cannot hold that nesting, so the leaf is its own small hierarchy
 * beside `TypeRef`, mirroring the rationale in {@see BoundExpr} — `TypeRef` is
 * kept as a single `name + isScalar + isTypeParam` slot that the canonicalisation
 * and substitution machinery depend on, and the compound/nested shapes live here.
 *
 * Concrete arms:
 *   - `SigTypeRef`      — a scalar / class / type-parameter / pseudo-type leaf,
 *                         wrapping a `TypeRef` so the existing substitution +
 *                         `TypeHierarchy` machinery is reused unchanged.
 *   - `SigClosure`      — a nested `ClosureSignature`.
 *   - `SigUnion`        — an `A|B` (or nullable `?A` ≡ `A|null`) leaf.
 *   - `SigIntersection` — an `A&B` leaf.
 *   - `SigRaw`          — a defensive fallback for a leaf that couldn't be
 *                         structured (a target-side DNF, an unresolved member, an
 *                         intersection carrying a scalar); accepted gradually.
 */
abstract readonly class SigType
{
}
