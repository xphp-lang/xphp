# 14. Variance markers are class-level only

- Status: Accepted — 2026-06

## Context and Problem Statement

Declaration-site variance (`out T` / `in T`) is realized as real `extends` edges between
*specialized classes*: `Producer<Banana>` extends `Producer<Fruit>`, and PHP's native type
system carries the subtype relationship. That mechanism needs a stable, nominal class
identity at each end of the edge.

Method-, function-, closure-, and arrow-scoped generics don't have one. Their
specializations are *functions* — mangled methods appended to a class, or top-level
functions, keyed by a call-site hash — not classes that can sit in an `extends` chain. So
the question is what to do when a type parameter on one of those carries a variance marker
(`function map<out U>(...)`, `$f = fn<in T>(...) => ...`): support it somehow, ignore it, or
reject it.

## Decision Drivers

- Variance must stay sound — a declared variance that isn't actually enforced is worse than
  no variance.
- Don't advertise a feature the architecture can't honor without a disproportionate change.
- Give library authors a clear, stable answer so they don't design around a feature that
  isn't coming.

## Considered Options

- **Implement method-level variance** — synthesize some stable identity for function
  specializations so a subtype relationship can be expressed. Large, and there is no natural
  PHP construct for "one function is a subtype of another."
- **Accept the markers and ignore them** — parse `out U` / `in U` on a function-scoped generic
  but emit nothing. Silently unsound: the declared variance would have no effect.
- **Reject them at parse time as a permanent boundary** — and document the rationale.

## Decision Outcome

Chosen: **reject variance markers on method / function / closure / arrow type parameters at
parse time, as a permanent design boundary.** Variance is a class-level-only feature. The
error states plainly that this is by design (a function or closure specialization has no
stable class identity to anchor a subtype `extends` edge to), and points the author at the
class-level workaround.

This is not a deferral. The cost of "implement it anyway" is high and the benefit is low:
the functional collection surface (`map<U>`, `flatMap<U>`, `groupBy<K>`, …) works correctly
as *invariant* method generics, which matches Kotlin — whose `fun <R> map(...)` is likewise
invariant. Method-level variance would be showcase richness, not a capability gap that
blocks real code.

### Consequences

- Good: the variance model stays simple and sound — every variance marker maps to a real,
  enforced `extends` edge between classes.
- Good: the boundary is explicit and documented, so library authors know to keep variance at
  the class level rather than waiting on a function-level feature.
- Trade-off: the interesting functional surface (method/closure generics) can never
  participate in variance; it stays invariant. Acceptable — it matches Kotlin and doesn't
  block correctness.

### Confirmation

The rejection is a single parse-time gate in
[`XphpSourceParser`](../../src/Transpiler/Monomorphize/XphpSourceParser.php) (the
`allowVariance` path), uniform across methods, free functions, closures, and arrow
functions, and pinned by tests for all four shapes. The boundary is documented in
[Variance](../syntax/variance.md) and [Caveats](../caveats.md).

## Pros and Cons of the Options

### Reject at parse time (permanent boundary)

- Good: sound, simple, explicit; cheap; a clear answer for library authors.
- Bad: no method-level variance (matches Kotlin; not a real blocker).

### Implement method-level variance

- Good: maximal expressiveness.
- Bad: requires inventing a stable identity for function specializations with no natural PHP
  analogue; large change for a "showcase" benefit.

### Accept and ignore the markers

- Good: no parse error.
- Bad: silently unsound — a declared variance that does nothing.

## More Information

- [ADR-0001](0001-monomorphization-over-type-erasure.md) — specializations and their class
  identities (or lack thereof for functions).
- [ADR-0013](0013-typed-constructor-parameters-on-variant-classes.md) — the class-level
  variance surface this boundary sits alongside.
- [Variance](../syntax/variance.md), [Caveats](../caveats.md).
