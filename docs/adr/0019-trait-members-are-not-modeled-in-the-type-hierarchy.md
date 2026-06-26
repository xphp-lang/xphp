# 19. Trait-imported members are not modeled in the type hierarchy

- Status: Accepted — 2026-06

## Context and Problem Statement

When a value is upcast to a covariant interface — `ListColl<Book>` used as
`Collection<Product>` — the element-consuming method (`contains<U : E>`) must be supplied as a
concrete member at the supertype argument. The closer satisfies it one of two ways: **inherit** it
through the covariant `extends` chain (when the body sits on a parent-less covariant base), or, when
inheritance can't carry it, **emit** it directly onto the upcast source. Both paths first locate an
emittable **class** body for the method by walking the type hierarchy's ancestors.

PHP also lets a class acquire a method body from a **trait** (`use SomeTrait;`). A trait is neither
a parent nor an interface; `TypeHierarchy` is built from `extends`/`implements` clauses and does not
record `use` edges. So a method whose only body is trait-supplied is **invisible** to the closer:
the hierarchy walk finds the method declared (abstractly, via the interface) but no class body to
inherit or copy. The question is what to do with that shape.

Faithfully modelling traits is not a small addition. It would mean tracking `use` edges, then
honouring PHP's full trait semantics — method resolution order, `insteadof` conflict resolution,
`as` aliasing/visibility changes, abstract trait methods, and trait-on-trait composition — and
threading the imported, possibly-renamed members through the same parameterised-supertype machinery
the class hierarchy already uses. That is a self-contained feature, not a tweak to the upcast closer.

## Decision Drivers

- Soundness over coverage — a missing member must fail loudly, never silently emit load-fataling
  output.
- Keep the upcast closer scoped — it reasons about the covariant `extends`/`implements` lattice;
  trait composition is a separate concern.
- Don't ship a half-modelled trait system whose partial semantics mislead more than they help.

## Considered Options

- **Partially model traits** — record `use` edges and copy the matching trait method body, ignoring
  conflict resolution / aliasing / abstract trait methods / trait composition. Cheap to start, and it
  does handle the trivial case (a single trait, one unambiguous body, whose name matches the
  interface). But it is silently wrong the moment a program uses any of the omitted semantics — and
  because the closer *synthesizes* a new member (not just keeps a runtime `use`), getting it wrong
  emits the wrong member rather than failing.

- **Fully model traits** — implement PHP's trait resolution end-to-end (method resolution order,
  `insteadof` conflict resolution, `as` aliasing and visibility changes, abstract trait methods, and
  trait-on-trait composition), threaded through the same parameterised-supertype machinery the class
  hierarchy already uses. This is what *correctly* supplying a trait body requires — partial modeling
  is unsound the instant resolution decides **which** body lands or **under what name**:

  - **`as` aliasing — the body's name differs from the interface's.** A trait method imported under an
    alias is what satisfies the interface, so the synthesized member must be found under the trait's
    *original* name and emitted under the *alias*:

    ```php
    trait SearchOps<+E> { public function locate<U : E>(U $value): int { /* scan $this->items */ } }
    interface OrderedCollection<+E> extends Collection<E> { public function indexOf<U : E>(U $value): int; }
    class ListColl<+E> extends LinkedNode<E> implements OrderedCollection<E> {
        use SearchOps<E> { locate as indexOf; }   // the alias is what satisfies indexOf
    }
    ```

    The abstract member is `indexOf` at the supertype argument; its body is the trait's `locate`. A
    name-keyed copy looks for `indexOf` in `SearchOps`, finds nothing, and leaves the member
    unimplemented — a load fatal. Only the alias map resolves it.

  - **`insteadof` — two trait bodies, only one wins.** When two `use`d traits both supply the method,
    PHP's `insteadof` picks the authoritative body; a copy with no conflict resolution emits the wrong
    algorithm (a silent correctness bug) or a duplicate:

    ```php
    trait LinearSearch<+E> { public function contains<U : E>(U $v): bool { /* O(n) scan */ } }
    trait HashSearch<+E>   { public function contains<U : E>(U $v): bool { /* hash lookup */ } }
    class FastColl<+E> extends RingBuffer<E> implements Collection<E> {
        use LinearSearch<E>, HashSearch<E> { HashSearch::contains insteadof LinearSearch; }
    }
    ```

    Only honouring `insteadof` emits `HashSearch::contains` as the supertype-argument member; a partial
    copy cannot tell which body is correct.

  Correct, but a large, self-contained feature unrelated to the upcast work, and unneeded until a real
  program hits one of these shapes.

- **Don't model traits; treat a trait-only body as a residual** — the hierarchy stays
  `extends`/`implements`-only; a covariant-upcast member with no reachable *class* body fails loudly.

## Decision Outcome

Chosen: **traits are not modelled in the type hierarchy.** `TypeHierarchy` records only
`extends`/`implements` edges. When a covariant upcast needs a member whose only body would come from
a trait, no class body is found, so the upcast is a compile error
(`xphp.unschedulable_covariant_upcast`) — the same loud, actionable failure used for the other
shapes direct emission can't ground. The remedy is to move the element-consuming body onto the
covariant base **class** (where both the inheritance and direct-emission paths can reach it), which
is also the idiomatic place for it.

This is deliberately consistent with the existing variance/bound caveat that bound and variance
rules are **not** recursively walked across trait `use` boundaries (see
[Caveats](../caveats.md#variance-validator-and-trait-use)): xphp does not currently follow generics
through traits in either direction. A trait-only covariant-upcast body falls under the same boundary
and fails the same way, rather than being a silent gap.

### Consequences

- Good: the upcast closer stays sound and small; an unsupported shape is a clear compile error with
  a one-move remedy, never emitted code that fatals at load or run time.
- Good: no partially-correct trait semantics to mislead — the boundary is uniform with the existing
  trait caveat.
- Trade-off: a library that puts an element-consuming method body in a trait and relies on it
  through a covariant upcast must relocate that body to the covariant base class. Declaring the
  method directly on the class is the supported shape.
- Reversible: if a real program needs it, modelling `use` edges (with full resolution semantics) is
  an additive change behind the same diagnostic — the failure becomes a success without any
  call-site change.

### Confirmation

Exercised by the covariant-upcast suite: a method whose body is supplied only through a trait
produces `xphp.unschedulable_covariant_upcast` rather than an emitted member, alongside the accepted
shapes where the body is on a parent-bearing class (direct emission) or a parent-less base
(inheritance). `TypeHierarchy` is built solely from `extends`/`implements` clauses; no `use` edge is
recorded.

## More Information

- [ADR-0018](0018-grounding-method-generic-bounds-on-enclosing-type-parameters.md) — grounding
  method-generic bounds on enclosing type parameters (the feature this boundary sits inside).
- [Type bounds](../syntax/type-bounds.md) — the covariant-upcast section and its residual cases.
- [Caveats](../caveats.md) — the variance/bound trait-`use` boundary this is consistent with.
- [Errors](../errors.md) — `xphp.unschedulable_covariant_upcast`.
