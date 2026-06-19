# 13. Variance-erased constructor parameters

- Status: Accepted — 2026-06

## Context and Problem Statement

Declaration-site variance (`+T` / `-T`) lowers to real `extends` edges between
specializations: `Producer<Banana>` actually extends `Producer<Fruit>` when `Banana`
extends `Fruit` and `T` is covariant. PHP enforces **invariant** constructor parameter
types across an `extends` chain — a subclass constructor whose parameter type differs
from its parent's is a fatal error at autoload. So a covariant class that takes its type
parameter in a constructor (`class ImmutableList<+T> { public function __construct(T ...$items) }`)
would emit `Producer<Banana>::__construct(Banana ...)` extending `Producer<Fruit>::__construct(Fruit ...)`
— two different signatures across the edge — and PHP-fatal the moment both specializations load.

The position rules therefore forbade a variance-marked type parameter in a constructor
parameter (and in any property). But a covariant immutable collection — Kotlin's
`List<out T>`, the backbone of an immutability-first collections library — fundamentally
wants `T`-typed construction input. The question: can a covariant/contravariant class
accept its type parameter in a constructor without the autoload fatal?

## Decision Drivers

- Support `T`-typed construction on a covariant/contravariant class (so the headline
  variance feature and a type-checked construction *surface* coexist).
- Keep every specialization's emitted signature LSP-compatible across the `extends` chain
  — no PHP fatal.
- Do not relax the cases that are genuinely unsafe (properties, where PHP's invariance is
  unavoidable and a covariant property really would fatal).

## Considered Options

- **Keep forbidding `T` in a constructor** — status quo; a covariant collection can't take
  typed construction input.
- **Permit it and emit a concrete `T`-typed constructor** — fatals at autoload (the very
  problem above).
- **Permit `T` in a non-promoted constructor parameter and emit it variance-*erased*** —
  the parameter type on every specialization is the type parameter's bound (if a single
  non-generic type) else `mixed`, so all specializations' `__construct` signatures are
  byte-identical and LSP-safe.
- **A compiler-recognized immutable factory** (`static from(T ...): self`) — the same
  erasure idea behind a named constructor rather than `__construct`.

## Decision Outcome

Chosen: **permit a non-promoted constructor parameter to carry `+T` / `-T`, and emit it
variance-erased** (the bound if it is a single non-generic leaf, else `mixed`). Because the
erased type is identical on every specialization, the constructor signature is the same on
both ends of every variance edge, so PHP never fatals. Covariance (the runtime edge) and a
typed construction *source surface* are both preserved.

A corollary falls out: a `final` class cannot be a parent in an `extends` edge, so **`final`
is stripped from variant-class specializations**. These are internal generated classes —
user code references the marker interface or the turbofish call site, never the generated
names — so dropping `final` there is invisible; it is preserved on invariant-class
specializations.

The relaxation is deliberately narrow. **Properties stay strictly invariant** — including a
*promoted* constructor parameter, which is a property and would fatal across the edge. A
**non-bare** type-parameter in a constructor (`?T`, `Box<T>`, `T|X`) is **not** erased
(erasure only applies to a bare single-segment type-parameter) and is still rejected,
because it would not be chain-identical and would fatal. The set of parameters the emitter
erases is exactly the set the validators let through — the variance-position check permits
all variances on a plain constructor parameter of a variant class, and the inner-variance
check rejects every non-erasable `T`-bearing shape that slips past it.

### Consequences

- Good: a covariant immutable collection can take `T`-typed construction input and remain
  usable contravariantly through subtyping (`ImmutableList<Banana>` where
  `ImmutableList<Fruit>` is expected), with no autoload fatal.
- Trade-off: because the emitted parameter is erased to `mixed`/the bound, PHP performs **no
  runtime element-type check** at construction, and the compiler does not (yet) statically
  check the supplied arguments at the call site — so the typed construction boundary is
  compile-time-best-effort, not a runtime guarantee. A stricter call-site check is a
  separate, harder analysis left for later.
- Trade-off: the mutable case is unchanged — a `T`-typed property (mutable, `readonly`, or
  promoted) is still rejected, because PHP's property invariance across the edge is
  unavoidable. Users hand-roll a `mixed`/`array` backing field plus a covariant `get(): T`.

### Confirmation

The relaxation is in `VariancePositionValidator::checkMethod` (a plain constructor parameter
of a variant class is allowed at any variance; a promoted one stays invariant) and
`InnerVarianceValidator` (the erased bare-type-param constructor parameters are skipped; every
other `T`-bearing shape is still walked and rejected). The erasure and the `final`-strip are in
[`Specializer::specialize`](../../src/Transpiler/Monomorphize/Specializer.php). A runtime test
compiles a covariant `ImmutableList<+T>` with a `T`-typed constructor and asserts that
`ImmutableList<Banana>` is usable where `ImmutableList<Fruit>` is expected with **no** autoload
fatal, alongside structural tests for the bounded, mixed-variance, contravariant, two-parameter,
and invariant-kept-`final` cases, and rejection tests for the non-erasable shapes.

## Pros and Cons of the Options

### Variance-erased non-promoted constructor parameter

- Good: preserves both covariance and a typed construction surface; the erasure point is
  localized to the specializer; no autoload fatal; mutable case untouched.
- Bad: construction is not runtime-type-checked (erased to `mixed`/bound); call-site
  argument checking is deferred.

### Concrete `T`-typed constructor

- Good: would give a real runtime check.
- Bad: PHP-fatals at autoload across the variance `extends` chain — not viable.

### Keep forbidding `T` in a constructor

- Good: simplest; no change.
- Bad: a covariant immutable collection cannot take typed construction input at all.

### Compiler-recognized factory

- Good: same erasure benefit, expressed as a named constructor.
- Bad: a strictly weaker subset of the same mechanism with extra surface; less ergonomic
  than `__construct`.

## More Information

- [ADR-0001](0001-monomorphization-over-type-erasure.md) — why specializations (and their
  `extends` edges) exist at all.
- [Variance](../syntax/variance.md) — the position rules, the covariant-construction
  pattern, and the not-runtime-checked caveat. [Caveats](../caveats.md).
- [`Specializer`](../../src/Transpiler/Monomorphize/Specializer.php),
  [`VariancePositionValidator`](../../src/Transpiler/Monomorphize/VariancePositionValidator.php),
  [`InnerVarianceValidator`](../../src/Transpiler/Monomorphize/InnerVarianceValidator.php).
- [ADR-0014](0014-variance-markers-are-class-level-only.md) — why variance stays at the
  class level (the related boundary).
