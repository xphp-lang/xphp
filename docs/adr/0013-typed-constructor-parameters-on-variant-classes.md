# 13. Typed constructor parameters on variant classes

- Status: Accepted — 2026-06

## Context and Problem Statement

Declaration-site variance (`+T` / `-T`) lowers to real `extends` edges between
specializations: `Producer<Banana>` actually extends `Producer<Fruit>` when `Banana`
extends `Fruit` and `T` is covariant. A covariant immutable collection — Kotlin's
`List<out T>`, the backbone of an immutability-first collections library — wants to take
its element type as **construction input**: `class ImmutableList<+T> { public function
__construct(T ...$items) }`. The question is whether a variant class can accept its type
parameter in a constructor across the variance `extends` edge, and with what type.

An earlier exploration assumed PHP enforces **invariant** constructor parameter types
across an `extends` chain — i.e. that `ImmutableList<Banana>::__construct(Banana ...)`
extending `ImmutableList<Fruit>::__construct(Fruit ...)` would fatal at autoload — and so
proposed emitting the parameter *variance-erased* (to the bound, else `mixed`). **That
premise is false.** PHP exempts `__construct` from LSP signature-compatibility checks: a
child constructor may have a completely different parameter list from its parent's with no
error. (Verified empirically, both directions.) So no erasure is needed.

## Decision Drivers

- Let a covariant/contravariant class take `T`-typed construction input.
- Keep the type information real — a generic is worth most when the concrete type is
  enforced. Avoid silently widening a declared `T` to `mixed`.
- Stay sound: a declared variance must not let an unsound subtype edge through.
- Don't fatal at autoload.

## Considered Options

- **Forbid `T` in a constructor** — status quo before this; a covariant collection can't
  take typed construction input at all.
- **Emit the parameter variance-erased** (bound, else `mixed`) — chain-identical, but
  based on the false fatal premise; throws away the real type and the runtime check.
- **Emit the parameter with its real substituted type** — relies on PHP's `__construct`
  LSP exemption; keeps the real type and a runtime check.

## Decision Outcome

Chosen: **permit a non-promoted constructor parameter to carry `+T` / `-T`, emitted with
its real substituted type.** A constructor parameter is *variance-position-exempt* — a
constructor is never reached through an upcast reference, so it isn't part of the
externally-visible variance surface (the same reason Kotlin allows `out T` in a
constructor). And because PHP doesn't LSP-check `__construct` across the chain, the
specializations' constructors may legitimately differ (`Banana ...$items` on the child,
`Fruit ...$items` on the parent). The covariant edge holds, autoload is clean, **and
construction is runtime-type-checked** — building an `ImmutableList<Banana>` from a
non-`Banana` throws a `TypeError`.

The `final`-strip corollary stands: a `final` class can't be a parent in an `extends`
edge, so `final` is dropped from variant-class specializations (internal generated
classes; invisible to user code).

The relaxation is narrow. **Properties stay strictly invariant** — mutable, `readonly`,
and *promoted* constructor parameters (which are properties). PHP makes property types
invariant across an `extends` chain (`Type of Child::$item must be …`), so a `T`-typed
property genuinely fatals — there is no way to carry a real `T` there. Such a property is
rejected at compile time (not erased); store elements in a plain `array`/`mixed` backing
field and expose them through a covariant `get(): T`. Non-bare shapes in a constructor
parameter (`?T`, `Box<T>`, `T|X`) are not yet supported and stay rejected.

### Consequences

- Good: a covariant immutable collection takes `T`-typed construction input that is
  enforced at runtime, remains usable covariantly (`ImmutableList<Banana>` where
  `ImmutableList<Fruit>` is expected), and never fatals at autoload.
- Good: nothing is erased — the declared type survives into the emitted signature.
- Trade-off: a `T`-typed property (mutable/`readonly`/promoted) is still rejected, because
  PHP property invariance across the edge is unavoidable. Users hand-roll a `mixed`/`array`
  backing field plus a covariant `get(): T`.
- Trade-off: richer constructor-parameter shapes (`?T`, `Box<T>`, `T|X`) aren't supported
  yet, only a bare variance-marked type parameter.

### Confirmation

`VariancePositionValidator::checkMethod` allows a plain constructor parameter of a variant
class at any variance (a promoted one stays invariant); `InnerVarianceValidator` skips a
bare variance-marked constructor parameter (`isExemptVariantCtorParam`) and still rejects
the non-bare shapes. [`Specializer::specialize`](../../src/Transpiler/Monomorphize/Specializer.php)
substitutes the real type into the constructor parameter — no erasure step. Tests compile a
covariant `ImmutableList<+T>` and assert each specialization's constructor keeps its real
element type, that the chain autoloads with **no** fatal, that an `ImmutableList<Banana>`
**throws** on a non-`Banana` element, and that the contravariant constructor chain
autoloads and constructs equally cleanly.

## Pros and Cons of the Options

### Real-typed constructor parameter (chosen)

- Good: keeps the real type and a runtime check; no autoload fatal; localized to the
  specializer (just normal substitution).
- Bad: properties still can't carry a real `T`; non-bare constructor shapes not yet supported.

### Variance-erased constructor parameter

- Good: chain-identical signatures.
- Bad: rests on a false fatal premise; discards the real type and the runtime check for no
  benefit.

### Forbid `T` in a constructor

- Good: simplest.
- Bad: a covariant collection can't take typed construction input at all.

## More Information

- [ADR-0001](0001-monomorphization-over-type-erasure.md) — why specializations (and their
  `extends` edges) exist at all.
- [ADR-0014](0014-variance-markers-are-class-level-only.md) — why variance stays at the
  class level (the related boundary).
- [Variance](../syntax/variance.md) — the position rules and the typed-construction pattern.
