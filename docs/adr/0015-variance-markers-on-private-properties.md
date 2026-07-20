# 15. Variance markers on private properties

- Status: Accepted — 2026-06

## Context and Problem Statement

Declaration-site variance (`out T` / `in T`) lowers to real `extends` edges between
specializations: `Producer<Banana>` extends `Producer<Fruit>` when `Banana` extends `Fruit`
and `T` is covariant ([ADR-0001](0001-monomorphization-over-type-erasure.md)). A variant
class therefore can't carry its type parameter in a position PHP would reject across that
edge.

The original rule treated **every** property as such a position: a `T`-typed property —
mutable, `readonly`, or promoted — was rejected at compile time, justified by "PHP enforces
invariant property types across an `extends` chain." So the natural covariant shape

```php
class Producer<out T> {
    public function __construct(private T $item) {}
    public function get(): T { return $this->item; }
}
```

was rejected, and authors had to hand-roll a `mixed` backing field plus a covariant
`get(): T` — which also trips the optional PHPStan-over-output pass
([ADR-0009](0009-phpstan-over-compiled-output.md)), since a `mixed` field reads as `mixed`.

The premise turned out to be too broad. PHP enforces invariant property types across the
chain **only for visible (public/protected) members**. A *private* property is not
inherited or overridden — its slot is per-declaring-scope — so PHP does **not** type-check
it across the edge. The question: should a private property be exempt from the
property-invariance rule, like a non-promoted constructor parameter already is
([ADR-0013](0013-typed-constructor-parameters-on-variant-classes.md))?

## Decision Drivers

- Soundness first — an allowed position must not produce an autoload fatal or a wrong-typed
  read at runtime.
- Don't over-restrict — rejecting a position PHP actually permits forces users into
  workarounds (a `mixed` backing field) that are both noisier and less type-safe.
- Keep the variance surface honest — only positions invisible to the externally-visible
  variance surface may differ across the edge.

## Considered Options

- **Keep rejecting all properties** — simplest rule, but over-restrictive: it bans a sound,
  natural covariant shape and forces a `mixed`-backed workaround that defeats PHPStan.
- **Allow any property (drop the invariance rule)** — unsound: a public/protected `T`
  property genuinely fatals at autoload when the variance edge lands.
- **Allow only `private` properties** — exempt a private property (declared or promoted;
  mutable or readonly), keep public/protected strictly invariant.

## Decision Outcome

Chosen: **a `private` property is variance-exempt — it may carry any variance — while
public/protected properties stay strictly invariant.** A private property is treated like a
non-promoted constructor parameter: it is invisible to the externally-visible variance
surface (it can't be read through an upcast reference), and PHP doesn't type-check its slot
across the edge, so each specialization keeps its own real-typed field (`private Banana
$item` / `private Fruit $item`) with no fatal. The Specializer emits the real substituted
type there — nothing is erased.

Soundness holds because monomorphization is total: each specialization re-emits its **own**
private field and its own accessor body, so no inherited parent method ever reads a
divergent-typed private slot of a child instance — the classic hazard is structurally
impossible.

Detection is by the **`private` visibility bit**, not the mere absence of a bit. A
`readonly`-only promoted parameter has no visibility bit and is implicitly public; an
asymmetric `public private(set)` property (PHP 8.4) is externally readable. Both are on the
visible variance surface and correctly stay strictly invariant — only a truly private slot
is exempt.

This refines, and does not supersede, [ADR-0013](0013-typed-constructor-parameters-on-variant-classes.md):
typed constructor *parameters* remain real-typed and LSP-exempt; this ADR adds that a
private promoted (or declared) *property* is likewise exempt.

### Consequences

- Good: the natural covariant single-value shape (`__construct(private T $item)` + `get():
  T`) compiles, keeps its real slot type (runtime-type-checked at construction), and is
  **PHPStan-clean** — no `mixed` backing, so the getter's return type is provable.
- Good: the rule now matches what PHP actually enforces — no position is rejected that PHP
  would have accepted.
- Trade-off: a *multi-element* collection still needs an `array` backing (many elements
  can't live in one `private T` slot), which xphp emits without a value-type annotation, so
  it still trips the optional PHPStan pass at level 6+ (the untyped `array` property has no
  iterable value type). That case is documented, not "fixed."
- Trade-off: a public/protected `T` property is still rejected — unavoidable, PHP fatals on
  it across the edge.

### Confirmation

The exemption lives in two validators — the property and promoted-parameter checks in
[`VariancePositionValidator`](../../src/Transpiler/Monomorphize/VariancePositionValidator.php)
and the inner-variance composition walk in
[`InnerVarianceValidator`](../../src/Transpiler/Monomorphize/InnerVarianceValidator.php) —
both keyed on the `private` visibility bit. It is pinned by tests: private declared/promoted
(mutable, `readonly`, inner-generic) properties compile; public/protected and the
externally-readable `public private(set)` shape stay rejected. A runtime-verify fixture
proves the covariant edge autoloads, a `Box<Banana>` flows where a `Box<Fruit>` is expected,
and construction throws `TypeError` on a wrong element; a grouped test proves the private-`T`
getter is PHPStan-clean. The boundary is documented in [Variance](../syntax/variance.md) and
[Caveats](../caveats.md).

## Pros and Cons of the Options

### Allow only `private` properties (chosen)

- Good: sound (PHP doesn't check private slots across the edge), matches PHP's real rule,
  unlocks the natural covariant shape, PHPStan-clean for single-value containers.
- Bad: multi-element collections still need an untyped `array` backing (trips the PHPStan
  pass at level 6+).

### Keep rejecting all properties

- Good: simplest rule.
- Bad: over-restrictive; forces a `mixed`-backed workaround that is noisier and defeats the
  PHPStan pass even for the single-value case PHP permits.

### Allow any property

- Good: maximal expressiveness.
- Bad: unsound — a public/protected `T` property fatals at autoload when the edge lands.

## More Information

- [ADR-0001](0001-monomorphization-over-type-erasure.md) — specializations and `extends`
  edges.
- [ADR-0013](0013-typed-constructor-parameters-on-variant-classes.md) — typed constructor
  parameters; this ADR refines its property-invariance corollary.
- [ADR-0009](0009-phpstan-over-compiled-output.md) — the PHPStan pass the `mixed` backing
  tripped.
- [Variance](../syntax/variance.md), [Caveats](../caveats.md).
