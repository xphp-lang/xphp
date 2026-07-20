# 22. Bounds are upper-only — express a widening operation with a sibling bound, not a lower bound

- Status: Accepted — 2026-07

## Context and Problem Statement

Every bound xphp supports is an **upper** bound — a single leaf, intersection, union, DNF, or
F-bound all constrain the concrete type to be a *subtype* of the bound, and
`Registry::checkBounds` verifies *concrete <: bound*. There is no way to say the opposite: "`S`
must be a **supertype** of `X`" (Scala's declaration-site lower bound, `[S >: T]`).

That dual has one concrete, sound use: a **widening reduce** (a `reduce`/`fold`-to-supertype on a
covariant collection). The accumulator/result `S` may be *wider* than the element `E`, because the
first element seeds the accumulator — so the element must be assignable to it (`E <: S`). On a
covariant `List<out E>` the element type is fixed by the class, so a member would need `S` bounded
*below* by `E` — `<S : super E>`, the exact dual of the shipped upper-bound-referencing-enclosing
`contains<U : E>` ([ADR-0018](0018-grounding-method-generic-bounds-on-enclosing-type-parameters.md)).
The question is whether to add that lower bound.

## Decision Drivers

- **Correctness and safety first.** Bounds gate soundness; a second bound *direction* doubles the
  reasoning surface of the bound checker (representation, parser, verdict, diagnostics).
- Don't add syntax/surface without a clear, otherwise-unmet need.
- Prefer expressing a capability with an existing mechanism over inventing new syntax.

## Considered Options

- **A — Keep bounds upper-only; express widening with a sibling bound.** A *widening* operation is
  written as a **static** generic with a sibling **upper** bound `<S, T : S>` (both `S` and `T`
  free; `T : S` makes the element a subtype of the accumulator, so `S` is a supertype of `T`). This
  already compiles and bound-checks today.
- **B — Add a supertype bound against an enclosing class parameter (`<S : super E>`).** Scala's
  route; the exact dual of `<U : E>`. Grounding is identical (substitute `E` with the receiver's
  argument); only the leaf verdict flips to *bound <: concrete*. Minimal new syntax; unblocks the
  operation as a **fluent member**.
- **C — General lower bounds** (`<S : super AnyType>`, sibling or concrete; leaf/intersection/union).
  The full dual family; larger surface, most of it with no current consumer.
- **D — Add extension functions.** Kotlin's actual mechanism: its `reduce` is `<S, T : S>` on an
  *extension* receiver, which is what makes both parameters free. Broad payoff, but a large feature
  of its own.

## Decision Outcome

Chosen: **A — bounds stay upper-only.** The widening operation is already expressible and works
today as the static sibling-bound form; nothing is blocked. A supertype/lower bound (option B) would
add only one thing on top of that — **fluent-member** ergonomics on a fixed-element covariant class —
and that does not currently clear the bar: the need is low-severity with a working alternative, and a
new bound direction is new surface in a soundness-critical checker. Option B is kept as a roadmap
possibility (Discovery), not built now. Options C and D are out of scope — C has no consumer beyond
B's case, and extension functions (D) are a separate, larger feature we are not pursuing.

The Kotlin precedent reinforces this: **Kotlin has no lower bounds either.** It reaches the widening
reduce with `<S, T : S>` on an extension function — xphp already has the `<S, T : S>` half, so the
capability (minus the member-call sugar) is present today.

### Consequences

- Good: the bound checker stays single-direction — one soundness surface, no new grammar, no new
  diagnostic. The widening capability is available now, and expressed consistently with every other
  bound.
- Trade-off: a widening `reduce`/`fold`/`scan` on a covariant collection must be a **static helper**
  (`Reducing::reduceOrNull::<Product, Book>($list, $op)`) — an extra, restated element type argument
  and a non-fluent call — rather than a member (`$list->reduceOrNull::<Product>(…)`).
- Trade-off: if member fluency later clears the bar, delivering it means introducing bound direction
  (option B). That work is tracked on the roadmap rather than designed out.

### Confirmation

`TypeParam::$bound` carries no direction — it is documented as the *upper* bound expression, and
`Registry::evaluateBound` resolves a leaf as `isSubtype(concrete, bound)` only. The bound grammar
(`XphpSourceParser::parseBoundExpr`) has no `super` / `>:` production, so a `<S : super E>` clause is
a compile-time **parse error**. The supported widening form — the sibling upper bound `<S, T : S>` —
is documented in [type bounds](../syntax/type-bounds.md#no-supertype-lower-bounds).

## Pros and Cons of the Options

### A — Upper-only, sibling bound for widening

- Good: no new bound direction in a soundness-critical checker; capability available today; uniform
  with the rest of the bound surface.
- Bad: the widening operation is a static helper, not a fluent member — an extra restated type
  argument at the call site.

### B — Supertype bound against an enclosing parameter (`<S : super E>`)

- Good: makes the widening operation a natural fluent member; small, symmetric extension that reuses
  the existing enclosing-parameter grounding.
- Bad: introduces bound *direction* as a first-class concept (representation, parser, verdict,
  diagnostic wording) for a low-severity need with a working alternative.

### C — General lower bounds

- Good: the complete dual of the upper-bound family.
- Bad: the largest surface, and everything beyond option B's case has no consumer.

### D — Extension functions

- Good: Kotlin's actual solution; broadly useful well beyond this one operation.
- Bad: a large feature in its own right; disproportionate to the need here.

## More Information

- [ADR-0018](0018-grounding-method-generic-bounds-on-enclosing-type-parameters.md) — grounding a
  method-generic **upper** bound that references an enclosing class parameter (`contains<U : E>`),
  the dual this decision declines to mirror downward.
- [ADR-0005](0005-nominal-erased-bound-checking.md) — nominal, erased bound checking.
- [Type bounds](../syntax/type-bounds.md), and the roadmap [Discovery → type system breadth](../roadmap.md#type-system-breadth).
- Scala's declaration-site lower bound `def reduce[S >: T](op: (S, T) => S): S`; Kotlin's
  `public inline fun <S, T : S> Iterable<T>.reduce(operation: (acc: S, T) -> S): S` (upper bound on
  an extension, no lower bound).
