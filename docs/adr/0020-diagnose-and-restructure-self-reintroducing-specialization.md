# 20. Diagnose and restructure self-reintroducing specialization (erased seam deferred)

- Status: Accepted — 2026-06

## Context and Problem Statement

Monomorphization ([ADR-0001](0001-monomorphization-over-type-erasure.md)) is a
fixed-point loop: each instantiation a member reaches becomes a new instantiation
to specialize. It does not converge when a member **re-introduces the receiver's
own type family in a strictly larger form**. The canonical shape is a covariant
collection with a grouping derivation:

```
class ImmutableList<out E> {
    function groupBy<L>(callable $keyOf): ImmutableMap<L, ImmutableList<E>> { … }
}
class ImmutableMap<K, out V> implements Map<K, V> {
    function values(): OrderedCollection<V> {                 // re-exposes V — here, a list
        return new ImmutableList::<V>(...);
    }
}
```

Reaching `values()` exposes `ImmutableList<ImmutableList<Item>>`, whose own
`groupBy` produces a deeper map, and so on — an unbounded tower. Today this
terminates only because the depth cap
([ADR-0006](0006-bounded-specialization-depth-cap.md)) aborts the run, so the
group-then-iterate idiom does not compile. A related case — grouping at
subtype-related element types (`Book` <: `Media`) — compiles but fatals at PHP
class-load on an incompatible covariant override
([variance caveats](../caveats.md)).

A controlled experiment settled how the cycle is actually driven. Three
restructurings of `values()` were compiled under a memory/time cap:

| `values()` return type | `values()` body | Result |
|---|---|---|
| `iterable` (non-generic) | returns a plain `array` (no nested generic) | **compiles** |
| `iterable` (non-generic) | still `new ImmutableList::<V>(...)` | **towers** |
| bare `OrderedCollection` (no arg) | still `new ImmutableList::<V>(...)` | **towers** |

The finding: **the member's body drives the tower, not its return type.** Erasing
only the declared type changes nothing; the cycle breaks only when the body stops
constructing the strictly-larger value. This is decisive for the design — it means
the author can already break the cycle today, and that any compiler-side "erase
the type at this position" feature would have to reach into the body, not just the
signature.

## Decision Drivers

- **Termination is already guaranteed** by the depth cap; the open question is the
  author's experience when they hit it, not soundness.
- **Don't add language surface or machinery without strong evidence it is needed**
  — the experiment shows the cycle is breakable with existing constructs.
- **The author needs an actionable path**, not just an abort.
- **Keep monomorphization's guarantees** ([ADR-0001](0001-monomorphization-over-type-erasure.md))
  intact for all code that does not opt out.

## Considered Options

- **A precise diagnostic plus author-side restructuring** — no new language
  feature; tell the author exactly where the cycle is and let them break it.
- **An opt-in erased seam now** — a `dyn`-style marker that compiles a position to
  its erased supertype and cuts the specialization edge.
- **Automatic erasure on cycle detection** — the compiler erases the offending
  position itself.
- **Whole-program erasure** — already considered and rejected in
  [ADR-0001](0001-monomorphization-over-type-erasure.md).

## Decision Outcome

Chosen: a **precise diagnostic plus author-side restructuring**. The cheapest
sound option, justified directly by the experiment: the author can break the cycle
**today, with no new feature**, by restructuring the member's body so it does not
construct the strictly-larger value — return the groups as a non-generic
`iterable`/`array`, or split the derivation. The runtime values are unchanged
(still concrete lists); only the static element type is given up past that
boundary, which is the unavoidable cost of stopping the expansion.

The work this decision authorizes:

1. **Keep the terminating depth cap** ([ADR-0006](0006-bounded-specialization-depth-cap.md)).
2. **Improve the diagnostic** to (a) fire in `check`, not only `compile` — today
   `check` never specializes, so it passes green and `compile` then aborts — and
   (b) name the offending member and source position, not just the runaway type
   family.
3. **Document the restructuring** as the supported cure.

The restructuring, concretely:

```php
// BEFORE — towers: the body re-wraps V (a list) into a deeper list.
public function values(): OrderedCollection<V> {
    return new ImmutableList::<V>(...\array_values($this->entries));
}

// AFTER — compiles: return the groups as a non-generic iterable; no deeper spec.
public function values(): iterable {
    return \array_values($this->entries);   // elements are still ImmutableList<Item> at runtime
}
```

Callers iterate the result (`foreach`) and read each group at runtime; the static
element type is `mixed` past the seam, re-narrowed with an `instanceof` where a
typed view is needed.

**The erased seam is deferred, not rejected.** A first-class `dyn`-style seam
(emit the erased supertype *and* erase the body's constructed value) remains a
viable future ergonomic improvement — it would let the author keep writing the
natural generic signature instead of hand-erasing. It is deferred because the
experiment shows it is a deeper, costlier transformation than a type annotation
(it must reach into the body), and the manual restructuring already covers the
need. If demand for the ergonomics appears, this ADR's prior-art section is the
starting point.

### Consequences

- Good: no new language surface; the smallest change that resolves the trap; it is
  how mature collection libraries already cope.
- Good: monomorphization's guarantees ([ADR-0001](0001-monomorphization-over-type-erasure.md))
  stay intact everywhere — nothing is silently erased.
- Good: the same restructuring that breaks the tower also removes the
  covariant-override load fatal at that position, since the erased member returns a
  uniform non-generic type.
- Trade-off: a legitimate idiom (group, then iterate via a *typed* view) does not
  compile as written; the author must restructure and accept the loss of the
  static element type past the boundary.
- Trade-off: the precision loss is manual and per-site rather than expressed by a
  single marker — the ergonomic gap the deferred seam would close.

### Confirmation

The failure modes are pinned by characterization fixtures and tests so a
regression that turned the controlled abort into a hang, an OOM, or a
silently-wrong build is caught: `test/fixture/compile/reachable_groupby_then_values`,
`…_then_entries`, and `…_subtype_elements`, exercised by
[`SpecializationTowerBoundaryTest`](../../test/Transpiler/Monomorphize/SpecializationTowerBoundaryTest.php).
The terminating message lives in
[`Compiler`](../../src/Transpiler/Monomorphize/Compiler.php). The diagnostic
improvements (check-time detection, member naming) are the authorized follow-up.

## Pros and Cons of the Options

### Precise diagnostic + author restructuring

- Good: cheapest; no new surface; restructuring works today; keeps every
  monomorphization guarantee.
- Bad: the natural typed-view idiom must be hand-rewritten; precision loss is
  expressed per-site, not declaratively.

### Opt-in erased seam now

- Good: the author keeps the natural generic signature; precision loss is one
  explicit marker.
- Bad: not just a type annotation — must erase the body's constructed value too
  (the experiment proves a signature-only change does not converge); new surface to
  parse, validate (output-position only), and teach, for a need the restructuring
  already meets. Deferred.

### Automatic erasure on cycle detection

- Good: nothing new to write.
- Bad: silent, non-local precision loss; output depends on detection order, so it
  is non-deterministic; erases a position the author may have wanted concrete.
  Rejected.

### Whole-program erasure

- Good: no tower can form; no override fatal.
- Bad: forfeits reified types, native `TypeError` enforcement, and reflection
  fidelity — the reasons [ADR-0001](0001-monomorphization-over-type-erasure.md)
  chose monomorphization. Rejected there.

## More Information

- [ADR-0001](0001-monomorphization-over-type-erasure.md) — monomorphization is the
  model; nothing here erases it.
- [ADR-0006](0006-bounded-specialization-depth-cap.md) — the depth cap that
  guarantees termination; this decision improves the experience around it.
- [ADR-0014](0014-variance-markers-are-class-level-only.md) — variance is
  class-level; relevant to any future output-position seam.
- [Caveats](../caveats.md) — the variance-edge-under-single-inheritance limit.

### Prior art for the deferred seam

If the erased seam is ever built, it is not novel — it is the established escape
hatch in monomorphizing languages, and the prior art also confirms the
body-erasure requirement found above.

**Rust — `dyn Trait` / `Box<dyn Trait>` (trait objects).** Rust monomorphizes by
default; a trait object is the opt-in *erased* alternative. The docs call
`dyn Trait` an **"erased type"** — concrete type unknown at compile time, accessed
through a fat pointer to a vtable
([Rust Reference: Trait objects](https://doc.rust-lang.org/reference/types/trait-object.html),
[Rust Book §18.2](https://doc.rust-lang.org/book/ch18-02-trait-objects.html)). It
"enables polymorphism without monomorphization, which directly avoids the
monomorphization recursion limit." Crucially, erasure happens **at the value** —
the body boxes the concrete value (`Box::new(x) as Box<dyn Trait>`), exactly the
body-level step the experiment showed is required; `impl Trait` (opaque but still
monomorphized) is *not* the seam. The same indirection breaks infinitely-sized
recursive types
([Rust Book §15.1](https://doc.rust-lang.org/book/ch15-01-box.html)).

**JVM-hosted and HHVM generics — whole-program erasure.** Kotlin/Java and Hack
erase generics outright — one runtime class per generic, the type argument dropped
([Hack & HHVM: Type Erasure](https://docs.hhvm.com/hack/generics/type-erasure/)).
A seam would do the same at a single chosen position rather than program-wide.
