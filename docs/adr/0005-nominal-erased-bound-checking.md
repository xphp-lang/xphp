# 5. Nominal, erased bound checking

- Status: Accepted — 2026-06

## Context and Problem Statement

Type-parameter bounds (`class Box<T: \Stringable>`, intersections, unions, DNF,
F-bounded `T: Comparable<T>`) need to be checked at compile time: does the
concrete argument satisfy the bound? Answering this requires a model of the type
hierarchy. But the compiler only sees the `.xphp` files it's given — a real
project also references vendor classes and hand-written `.php` classes the
compiler never parses. So for many concrete arguments the compiler genuinely
*cannot know* the answer. The design question is what to do with that ignorance.

## Decision Drivers

- Correctly accept satisfied bounds and reject violated ones for types the
  compiler can see.
- Don't break valid code that references types outside the compiled source set.
- Keep the model simple and predictable.

## Considered Options

- **Nominal ancestor map with a three-valued result** — build a map of
  declared-class → direct ancestors; `isSubtype` returns `true` / `false` /
  `null` (unknown) and lets the caller decide what "unknown" means.
- **Reject the unknown case** (conservative) — anything unprovable fails.
- **Accept the unknown case** (permissive) — anything unprovable passes silently.
- **Require every referenced type to be in the source set.**

## Decision Outcome

Chosen: a **nominal ancestor map with a three-valued `isSubtype`**. A
`TypeHierarchy` records each declared class/interface/trait and its direct
ancestors (plus a small whitelist of common built-in interfaces); satisfaction is
a transitive walk over that map. Crucially `isSubtype` returns a *nullable* bool:
`true` (proven), `false` (disproven — the type is known and lacks the bound), or
`null` (the type isn't in the known set, so neither verdict is justified). Bound
combinators propagate the three values (intersection: any `false` → `false`, all
`true` → `true`, else unknown; union dually). Generic arguments on the bound
itself are erased for this check — comparison is by name.

### Consequences

- Good: bounds are enforced for everything the compiler can see, without breaking
  projects that legitimately reference vendor / plain-`.php` types.
- Good: the three-valued result keeps the policy decision (what to do when unknown)
  at the call site rather than baked into the hierarchy.
- Trade-off: an unprovable-but-actually-violating bound can slip through to a
  runtime `TypeError` instead of a compile error. That's the cost of not requiring
  a closed world; the value-flow gap is exactly what the PHPStan layer
  ([ADR-0009](0009-phpstan-over-compiled-output.md)) is positioned to close.
- Trade-off: nominal erasure means bound-argument arity isn't checked — a deliberate
  boundary, not an oversight.

### Confirmation

[`TypeHierarchy`](../../src/Transpiler/Monomorphize/TypeHierarchy.php) and the bound
combinators ([`BoundIntersection`](../../src/Transpiler/Monomorphize/BoundIntersection.php),
[`BoundUnion`](../../src/Transpiler/Monomorphize/BoundUnion.php)); see
[Type bounds](../syntax/type-bounds.md).

## Pros and Cons of the Options

### Nominal map, three-valued

- Good: precise where it can be; honest where it can't; simple.
- Bad: lets unprovable violations reach runtime.

### Reject unknown

- Good: maximally strict.
- Bad: breaks every reference to a vendor / plain-PHP class — defeats progressive
  enhancement.

### Accept unknown

- Good: never blocks valid code.
- Bad: silently misses real violations among unknown types.

### Require all types in source

- Good: a closed world makes every check decidable.
- Bad: incompatible with mixing `.xphp` and ordinary PHP — a non-starter.

## More Information

- [Type bounds](../syntax/type-bounds.md), [Caveats](../caveats.md).
- [ADR-0009](0009-phpstan-over-compiled-output.md) closes the value-flow gap with a
  full type checker.
