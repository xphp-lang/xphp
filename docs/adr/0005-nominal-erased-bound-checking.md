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
- Distinguish a *definite* violation from "can't tell", so the error message is
  accurate and actionable rather than misleading.
- Keep the model simple and predictable.

## Considered Options

The mechanism for modelling the hierarchy, and the policy for the "can't tell"
case, are really one choice:

- **A nominal ancestor map with a three-valued result, rejecting anything not
  proven** — build a map of declared-class → direct ancestors; `isSubtype`
  returns `true` / `false` / `null` (unknown); the bound check passes only on
  `true` and rejects both `false` and `null`, using the distinction to emit a
  different message for each.
- **A two-valued check** that collapses unknown into `false` — also rejects
  unprovable types, but can't tell the user *why* (everything is "does not
  implement").
- **Accept the unknown case** (permissive) — anything unprovable passes silently.
- **Require every referenced type to be in the source set** (a closed world).

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

The bound check then passes **only on `true`**. A `false` is reported as a
definite violation (*"X does not extend/implement the bound"*); a `null` is also
reported, but with a distinct, actionable message (*"X is not in the analysed
source set, so the compiler cannot prove it satisfies the bound — add it to the
sources, or relax the bound"*). So the policy on "can't tell" is **conservative
rejection**, and the three-valued result exists precisely so that rejection can
be explained accurately instead of being conflated with a real violation.

### Consequences

- Good: bounds are enforced for everything the compiler can see; an unprovable
  type produces a clear, distinct error at `check`/`compile` time — never a silent
  pass and never a misleading "does not implement" for a type the compiler simply
  couldn't see.
- Good: the three-valued result keeps the policy (what to do when unknown) at the
  call site rather than baked into the hierarchy.
- Trade-off: the policy is conservative — a vendor or plain-`.php` type that *would*
  satisfy the bound at runtime is still rejected when the compiler can't see it,
  because it can't be proven. The remedy is to include that type in the analysed
  sources or to widen/drop the bound. (Same closed-world limitation as the
  undeclared-type check in [ADR-0010](0010-undeclared-type-and-arity-validation.md).)
- Trade-off: nominal erasure means bound-argument arity isn't checked — a deliberate
  boundary, not an oversight.

### Confirmation

[`TypeHierarchy`](../../src/Transpiler/Monomorphize/TypeHierarchy.php) and the bound
combinators ([`BoundIntersection`](../../src/Transpiler/Monomorphize/BoundIntersection.php),
[`BoundUnion`](../../src/Transpiler/Monomorphize/BoundUnion.php)); see
[Type bounds](../syntax/type-bounds.md).

## Pros and Cons of the Options

### Nominal map, three-valued, reject-unless-proven

- Good: precise where it can be; rejects the unknown case with an accurate,
  actionable message; simple.
- Bad: conservative — rejects vendor / plain-`.php` types it can't see, even valid
  ones (remedy: add them to the sources, or relax the bound).

### Two-valued (collapse unknown into `false`)

- Good: also rejects unprovable types; even simpler.
- Bad: can't distinguish "definitely violates" from "can't tell", so the user gets
  a misleading "does not implement" for a type the compiler merely couldn't see.

### Accept unknown

- Good: never blocks valid code.
- Bad: silently misses real violations among unknown types — unsound.

### Require all types in source

- Good: a closed world makes every check decidable.
- Bad: incompatible with mixing `.xphp` and ordinary PHP — a non-starter.

## More Information

- [Type bounds](../syntax/type-bounds.md), [Caveats](../caveats.md).
- This check covers *bound satisfaction* only. Value-flow type errors *inside*
  generic bodies are a separate concern handled by
  [ADR-0009](0009-phpstan-over-compiled-output.md) (PHPStan over the compiled
  output), not by this hierarchy.
