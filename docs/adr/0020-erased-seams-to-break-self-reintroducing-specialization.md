# 20. Erased seams to break self-reintroducing specialization

- Status: Proposed

## Context and Problem Statement

Monomorphization ([ADR-0001](0001-monomorphization-over-type-erasure.md)) stamps
out a concrete class per instantiation, driven by a fixed-point loop: each
instantiation a member's type mentions becomes a new instantiation to specialize.
For ordinary code this converges. It does **not** converge when a member's type
**re-introduces the receiver's own type family in a strictly larger form**.

The canonical shape is a covariant collection with a grouping derivation:

```
class ImmutableList<+E> {
    function groupBy<L>(callable $keyOf): ImmutableMap<L, ImmutableList<E>> { … }
}
class ImmutableMap<K, +V> implements Map<K, V> {
    function values(): OrderedCollection<V> { … }   // re-exposes V — here, a list
}
```

Specializing `ImmutableList<Item>` reaches `ImmutableMap<…, ImmutableList<Item>>`
(fine, one level). But the moment `values()` is reached, it re-exposes
`ImmutableList<ImmutableList<Item>>`, whose own `groupBy` produces
`ImmutableMap<…, ImmutableList<ImmutableList<Item>>>`, and so on — an unbounded
tower of ever-deeper nestings. Today this terminates only because the hard depth
cap ([ADR-0006](0006-bounded-specialization-depth-cap.md)) aborts the run. The
program does not compile.

A second, related failure: when the element types at two grouping sites are
subtype-related (`Book` <: `Media`), the resulting specializations are
variance-related, so a covariant override edge *should* exist on a view method —
but under single inheritance that covariant leaf edge is dropped, and the
generated overrides are incompatible at PHP class-load time
([variance caveats](../caveats.md)). Both failures share one root cause: a
generic type position that re-exposes a concrete specialization the program does
not actually need at that position.

The author *can* avoid both by hand — typing the slot as a bare interface
(`OrderedCollection`) instead of the concrete `ImmutableList<…>` — which is
exactly what mature collection libraries do. But nothing in the language *models*
that choice: it is folklore, it is easy to get wrong, and the diagnostic when you
get it wrong is a depth-cap abort, not a pointer to the seam that would fix it.

## Decision Drivers

- **Termination without refusing legitimate programs.** The depth cap guarantees
  termination but at the cost of rejecting a real, common idiom (grouping a
  covariant collection, then iterating the groups).
- **Keep monomorphization's guarantees where they apply.** Zero runtime penalty,
  native `TypeError` enforcement, and reflection fidelity ([ADR-0001](0001-monomorphization-over-type-erasure.md))
  must remain the default; any erasure must be local and opt-in, not a model
  change.
- **Make the cure explicit and discoverable.** The mechanism that breaks the cycle
  should be a named language construct the diagnostic can point at — not an
  undocumented "type it as an interface" trick.
- **Sound by construction.** Erasing a type argument widens the static type; this
  must only be allowed where widening is sound (output / covariant positions).

## Considered Options

- **An opt-in erased seam** — a marker at a single type position that compiles to
  the erased supertype (the bare interface, type argument dropped), cutting the
  specialization edge at that position only.
- **Automatic erasure on cycle detection** — when the fixed-point loop detects a
  growing family, the compiler erases the offending position itself.
- **Status quo** — the depth cap plus the folklore "type it as an interface by
  hand" workaround.
- **Whole-program erasure** — abandon per-instantiation specialization; already
  considered and rejected in [ADR-0001](0001-monomorphization-over-type-erasure.md).

## Decision Outcome

Chosen (proposed): an **opt-in erased seam**. A type position (a return type, a
field, or a parameter) may be marked so that it compiles to its **nearest
non-generic supertype with the type argument erased** — the same role Rust's
`dyn Trait` / `Box<dyn Trait>` and an interface-typed slot in an erasure-based
language play. At a seam:

1. **The emitted type hint is the erased supertype** (e.g. `OrderedCollection`,
   no specialization suffix), not the concrete instantiation.
2. **The specializer does not enqueue the erased argument from this position.**
   The fixed-point edge is cut here, so a self-reintroducing family stops growing.
   Other reachable positions may still force that instantiation; the seam only
   removes *this* edge as a source.
3. **No per-instantiation override is generated at the seam**, because the
   position is the uniform erased supertype across every specialization — which
   dissolves the covariant-override-incompatibility failure as a side effect.

The seam is the **author's deliberate tool** to break a self-reintroducing cycle;
the depth cap ([ADR-0006](0006-bounded-specialization-depth-cap.md)) remains the
involuntary backstop for cycles the author did not break. This does **not**
reverse [ADR-0001](0001-monomorphization-over-type-erasure.md): the program is
still monomorphized everywhere except the positions the author explicitly erases.

The construct is restricted, for v1, to **output / covariant positions** (return
types and covariantly-used fields), where erasing widens the static type
soundly. Contravariant and invariant positions are out of scope until the
variance interaction is worked through. The exact surface spelling (a leading
type keyword versus an attribute on the member) is left to the implementing
work; this ADR fixes the *semantics* — erase to the nearest supertype, cut the
specialization edge — not the token.

### Consequences

- Good: the grouping idiom (`groupBy` then iterate the groups via a view)
  compiles, instead of aborting at the depth cap — the cap goes back to being a
  pure runaway guard.
- Good: the covariant-override-incompatibility load fatal disappears at any seam,
  because the overridden member returns the erased supertype uniformly.
- Good: the cure is a named construct a diagnostic can name — when a family
  trips the depth cap, the compiler can point at the position to erase.
- Trade-off: an erased value loses its static type argument — reading through the
  seam sees the supertype's (wider, possibly `mixed`) member types, exactly as
  `dyn` / erasure trade precision for flexibility. This is why it is opt-in and
  per-position, never automatic.
- Trade-off: a new type-position construct to parse, validate (variance-aware),
  document, and teach.

### Confirmation

To be enforced, when implemented, by: a position-erasure pass in
[`src/Transpiler/Monomorphize/`](../../src/Transpiler/Monomorphize/Compiler.php)
that emits the erased supertype and removes the seam as a specialization source;
accept fixtures under `test/fixture/compile/` that compile the
group-then-iterate idiom to a bounded set of specializations and run it; and a
fixture asserting a subtype-related grouping with an erased view loads without an
override-compatibility fatal. The variance validator must reject a seam in a
non-output position.

## Pros and Cons of the Options

### Opt-in erased seam

- Good: breaks the cycle exactly where the author intends; local; keeps
  monomorphization everywhere else; dissolves the override fatal for free.
- Bad: precision loss at the seam; new construct to build and teach.

### Automatic erasure on cycle detection

- Good: no new surface syntax; "just works".
- Bad: silent, non-local precision loss; output depends on the loop's detection
  order, so it is non-deterministic and hard to predict; erases a position the
  author may have wanted concrete. Rejected.

### Status quo (depth cap + hand-typed interface)

- Good: nothing new to build.
- Bad: a common, legitimate idiom does not compile; the workaround is folklore;
  the diagnostic is an abort, not a pointer to the fix.

### Whole-program erasure

- Good: no tower can ever form; no override fatal.
- Bad: forfeits reified types, native `TypeError` enforcement, and reflection
  fidelity — the reasons [ADR-0001](0001-monomorphization-over-type-erasure.md)
  chose monomorphization. Rejected there.

## More Information

- [ADR-0001](0001-monomorphization-over-type-erasure.md) — monomorphization is the
  default; this seam is a local, opt-in exception, not a reversal.
- [ADR-0006](0006-bounded-specialization-depth-cap.md) — the depth cap that
  currently catches these towers; this seam is how an author avoids tripping it.
- [ADR-0014](0014-variance-markers-are-class-level-only.md) — variance is a
  class-level property; the seam's output-position restriction builds on it.
- [Caveats](../caveats.md) — the variance-edge-under-single-inheritance limit the
  seam also addresses.
### Prior art

This mechanism is not novel to xphp; it is the established escape hatch in every
monomorphizing language. The seam imports it at a single chosen position rather
than program-wide.

**Rust — `dyn Trait` / `Box<dyn Trait>` (trait objects).** Rust monomorphizes
generics by default (static dispatch); a trait object is the opt-in *erased*
alternative. The Rust documentation calls `dyn Trait` an **"erased type"** —
"an object that implements a specific trait, but whose underlying concrete type
is not known at compile time" — accessed through a fat pointer to a vtable
([Rust Reference: Trait objects](https://doc.rust-lang.org/reference/types/trait-object.html),
[Rust Book §18.2](https://doc.rust-lang.org/book/ch18-02-trait-objects.html)).
Crucially, it "enables polymorphism **without monomorphization**, which directly
avoids the monomorphization recursion limit" and produces no per-type code. A
self-reintroducing generic that towers under static dispatch:

```rust
// Static dispatch: each call instantiates T at a strictly deeper type, so the
// compiler must emit wrap::<Item>, wrap::<Vec<Item>>, wrap::<Vec<Vec<Item>>>, …
fn wrap<T: std::fmt::Debug>(x: T) {
    println!("{:?}", x);
    wrap(vec![x]); // T -> Vec<T> each level
}
// error: reached the recursion limit while instantiating `wrap::<Vec<Vec<…>>>`
```

is fixed by erasing the value behind a trait object — the seam — so no new
instantiation is generated per level:

```rust
fn wrap(x: Box<dyn std::fmt::Debug>) {
    println!("{:?}", x);
    // x is type-erased behind `dyn`; the expansion stops here.
}
```

The same indirection breaks infinitely-sized recursive *types*
(`enum List { Cons(i32, Box<List>) }` — [Rust Book §15.1](https://doc.rust-lang.org/book/ch15-01-box.html),
[Rust By Example: Returning `dyn`](https://doc.rust-lang.org/rust-by-example/trait/dyn.html)).
Note that Rust's `impl Trait` is *opaque but still monomorphized* — it is **not**
the erased seam; only `dyn` erases. xphp's seam is the `dyn` analogue: erase to
the supertype at one position, keep monomorphization everywhere else.

**JVM-hosted and HHVM generics — whole-program erasure.** Kotlin/Java and Hack
erase generics outright — one runtime class per generic, the type argument
dropped ([Hack & HHVM: Type Erasure](https://docs.hhvm.com/hack/generics/type-erasure/)).
xphp does the same thing the seam does, but at *one chosen position* instead of
for the entire program — which is why monomorphization's guarantees survive
everywhere else.
