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
 *   - `SigTypeRef`  — a scalar / class / type-parameter / pseudo-type leaf,
 *                     wrapping a `TypeRef` so the existing substitution +
 *                     `TypeHierarchy` machinery is reused unchanged.
 *   - `SigClosure`  — a nested `ClosureSignature`.
 *
 * A later work item adds `SigUnion` / `SigIntersection` for `A|B` / `A&B` leaves;
 * introducing the abstract base now keeps that a purely additive extension rather
 * than a schema break across the substitution visitor.
 */
abstract readonly class SigType
{
}
