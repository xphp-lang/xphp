<?php

declare(strict_types=1);

namespace XPHP\Transpiler\Monomorphize;

/**
 * Bound expression on a generic type parameter.
 *
 * Bound positions are the ONLY place in xphp where the source parser sees an
 * intersection / union shape (`T : A & B`, `T : A | B`, DNF `T : (A & B) | C`).
 * PHP's native intersection / union types in parameter / return positions go
 * through nikic's parser directly — xphp's scanner never touches them.
 *
 * Three concrete subtypes:
 *   - `BoundLeaf`        — a single class/interface reference (possibly with
 *                          generic args for F-bounded forms like `Box<T>`).
 *   - `BoundIntersection` — `A & B & ...`; every operand must satisfy.
 *   - `BoundUnion`        — `A | B | ...`; any operand suffices.
 *
 * Nesting `BoundUnion(BoundIntersection(A, B), C)` builds `(A & B) | C` (DNF).
 *
 * The leaf carries a `TypeRef` rather than a bare FQN so F-bounded recursion
 * (`T : Comparable<T>`) shares the existing TypeRef substitution machinery.
 *
 * `TypeRef` itself is NOT widened to carry compound shapes — the rest of the
 * pipeline (Specializer, CallSiteRewriter, GenericMethodCompiler) treats
 * `TypeRef` as a single concrete-or-type-param slot, and adding `isUnion` /
 * `isIntersection` flags would break the `name + isScalar + isTypeParam`
 * invariant the existing canonicalisation + hashing depend on. Keeping
 * `BoundExpr` separate isolates the schema explosion to just the bound
 * positions.
 */
abstract readonly class BoundExpr
{
}
