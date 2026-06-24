# 18. Grounding a method-generic bound that references an enclosing class type parameter

- Status: Accepted — 2026-06

## Context and Problem Statement

A covariant collection `class Box<+E>` cannot take `E` in a parameter position, so an
element-consuming method (`contains`, `indexOf`, an immutable `withAdded`) classically falls back to
`mixed`. The *sound* spelling is a **method-level** type parameter bounded by the class parameter —
`public function contains<U : E>(U $value): bool` — so the argument is constrained to a subtype of
the element type while the covariant `+E` never enters a parameter position (the same shape Hack
uses for the element-search methods on its covariant `ConstVector`). `U` is invariant, so this is **not** method-level
variance ([ADR-0014](0014-variance-markers-are-class-level-only.md)) — it only needs the enclosing
`E` to be resolved.

But the bound `E` was evaluated against the literal type-parameter name: `isSubtype("Banana", "E")`
treats `"E"` as a phantom class, always returns false, and rejects *valid* code
(`Box<Fruit>::contains<Banana>` with `Banana <: Fruit`) with a misleading
*"Banana does not extend/implement E"*. Bound checking is otherwise nominal and erased
([ADR-0005](0005-nominal-erased-bound-checking.md)); the missing piece is grounding `E` to the
receiver's concrete type argument before the check.

## Decision Drivers

- Let a covariant collection expose element-consuming methods with a *real* element-typed parameter
  instead of `mixed`, soundly.
- Never false-reject *provably-valid* code — determine the receiver's element type wherever it's
  statically knowable (the misleading `"does not extend/implement E"` rejection must go) — but never
  silently accept an *unverifiable* bound either: prove it or fail the build, never defer to runtime.
- Keep the existing nominal/erased bound check; add grounding, don't replace it.
- Cover the shape real libraries use: methods declared on a generic **interface/base** and inherited
  by concrete collections.

## Decision Outcome

Chosen: **at a method-generic call site, ground each bound that names an enclosing class type
parameter against the receiver's concrete type arguments, then run the existing bound check — and
when the receiver's argument genuinely can't be determined, fail the build rather than skip the
check.** This is *ground or fail*, driven by the project's non-negotiable principles
[#2 Maximum Runtime Safety](../../README.md#2-maximum-runtime-safety) (never let an unverified bound
through, and never defer the check to runtime) and
[#1 Zero Runtime Penalty](../../README.md#1-zero-runtime-penalty) (the check is a compile-time fact,
the emitted code carries nothing).

- **Determination floor — maximise what's knowable first.** The receiver's type arguments are
  recovered from flow typing and threaded up the parameterized `extends`/`implements` chain to the
  method's **declaring** class (so a method inherited from `Collection<+E>` grounds against an
  `ArrayList<Fruit>` receiver). The covered receiver shapes:
  - a parameter or `$this->prop` of declared generic type (`Box<Fruit> $b`);
  - a `new Box::<Fruit>()` local (and a closure-`use` capture of one);
  - a value whose type comes from a **method return**, a **chained call**, or a `self`/`static`
    factory (`$x = $repo->getBox(); $x->...`, `$repo->getBox()->...`);
  - a **branch** whose every arm assigns the *same* parameterised type (the arms agree → the element
    type survives the merge).
  A bound that references a **sibling** parameter rather than the receiver's element type is grounded
  against the supplied argument the same way, both at the class level (`class Pair<T, U : T>`) and at
  the method level (`<U, V : U>`, grounded against the call's own turbofish arguments).
- **The residual is a compile error.** A bound leaf that is still a bare type parameter after
  grounding — the argument genuinely couldn't be determined (a raw/unparameterised receiver, a branch
  whose arms construct different types, a static call with no instance) — is reported as
  `xphp.bound_unprovable` with an actionable remedy ("bind the receiver to a typed local"). It is
  **never** dropped to "unbounded" and **never** checked against the phantom name. In `xphp check`
  the diagnostic is collected; in `xphp compile` it aborts the build.
- A bound that does **not** name an enclosing/sibling parameter — a real class, or an F-bounded
  `Comparable<T>` leaf — is untouched and checked exactly as before.

### Consequences

- Good: the one place a covariant collection degraded to `mixed` now has a sound, element-typed
  parameter; `Box<Fruit>::contains<Banana>` is accepted and `Box<Fruit>::contains<Rock>` is
  rejected with the bound shown **grounded** (`Fruit`), not `E`.
- **Ground or fail, never silently accept.** Where the receiver's argument can't be determined, an
  unprovable bound is a *compile error*, not a silent accept and not a runtime check. This is the
  whole point: a covariant generics library must not let an unverified element-type constraint reach
  emitted PHP. The determination floor above keeps the error rare — it fires only on receivers that
  carry no recoverable element type — and the message names the fix.
- **Compound bounds fail whole.** A method bound like `<U : \Stringable & E>` whose `E` can't be
  grounded fails the **entire** bound rather than checking half of it — the checkable `\Stringable`
  operand isn't silently dropped, and the unprovable `E` operand isn't silently accepted. The user
  grounds the receiver and the whole bound (both operands) is then checked.
- **Static methods fail.** A class type parameter is unbound in a static context — there is no
  instance to ground `E` against — so a static method whose bound names a class parameter is
  unprovable and fails. (The call's own method-parameter bounds, e.g. `<U, V : U>`, still ground
  against the turbofish arguments and are checked.)
- **Erasable methods are lowered, and a forwarded self-call works.** A method whose enclosing-bounded
  parameter is used *only* as a direct top-level input (`U $value`) is lowered by **erasing `U` to its
  bound `E`**: one concrete `E`-typed member per class instantiation (`contains_<Fruit>(Fruit)`), not
  one per call-site turbofish (`contains_<Banana>(Banana)`) — `<U : E>` is, after all, the
  variance-legal spelling of "an `E`-typed input". A `$this`-rooted self-call that *forwards* its
  parameter to such a method — `probe<U : E>(U $v) { return $this->contains::<U>($v); }` — therefore
  compiles and runs (the forward rewrites to the emitted member); it is the idiomatic way to call an
  element-consuming method from inside the class. The bound is still checked at the call site before
  erasure, so `Box<Fruit>::contains<Rock>` is still rejected.
- **A covariant upcast to an interface schedules its implementer.** When the erasable method is declared
  on a covariant *interface* (`Collection<+E>`) and a concrete `ListColl<Book>` is upcast to a supertype
  specialization (`Collection<Product>`), that specialization declares a *distinct* abstract erased member
  (`contains_<Product>`, separate from `contains_<Book>` — distinct names keep the covariant edge from
  narrowing a parameter). The concrete implementation is carried down the covariant chain from the
  declaring base specialized at the supertype's argument (`AbstractColl<Product>`), which the ordinary
  fixed-point loop never discovers (an upcast is a usage relationship, not substitution). A specialization
  closure step schedules it so the program loads and runs without an explicit instantiation of the
  supertype. Where the implementation can't be carried down a single covariant chain — the declaring class
  has another parent, a trait-only body, or a reordered `implements` clause — the upcast is a compile error
  (`xphp.unschedulable_covariant_upcast`), never emitted load-fataling code.
- **The residual `$this` self-calls still fail — loudly, never at runtime.** A *direct concrete*
  `$this->contains::<Banana>()` self-call (its bound is checkable only on the abstract template) fails
  with `xphp.bound_unprovable`; a forward to a *non-erasable* method (parameter used nested, in the
  return, or structurally) fails with `xphp.unspecializable_self_call`. Both are compile errors, never
  a runtime fault. (A future per-instantiation re-check could relax the direct-concrete case too, but
  the common forwarding shape is already handled by erasure.)
- Boundary unchanged — grounding resolves the enclosing parameter to the receiver's argument; the
  grounded bound is then checked nominally/erased as before. F-bounded and generic-argument bound
  checking are unaffected.
- Variance — making a bare-type-parameter bound leaf a first-class type-parameter reference also
  lets the variance phase see it: a covariant/contravariant class parameter used as the **bare** leaf
  of a sibling class parameter's bound (`class Pair<+T, U : T>`) is now flagged, consistently with the
  already-rejected inner-argument case (`Sortable<+T : Box<T>>`) and the documented rule that bounds
  are an invariant position. The supported method-level shape (`contains<U : E>`, where `U` is a
  *method* parameter) is unaffected.

### Confirmation

The grounding, the inheritance threading, the determination floor, and the hard-fail are covered
end-to-end: a direct and an **inherited** (`ArrayList<Fruit> extends Base<+E>`) accept, a
multi-argument (`Pair<K, +V>::containsValue<U : V>`) accept that grounds the right parameter, a reject
whose message shows the grounded bound, the determined-receiver cases (parameter / property /
closure-`use` / method-return / chain / `self`-`static` / branch-arms-agree) accepting or rejecting on
the grounded type, and the unprovable cases (a raw generic parameter, a branch whose arms disagree, a
static class-parameter bound, and a *direct concrete* `$this` self-call) failing with
`xphp.bound_unprovable` — both thrown in `compile` and collected in `check`. The erasure lowering is
exercised by executing the compiled output (a forwarding self-call, an inherited member, a covariant
chain, a multi-class-param `Map<K, +V>`), and a forward to a *non-erasable* method fails with
`xphp.unspecializable_self_call`. A sibling-parameter bound (`class Pair<T, U : T>`) is
unit-tested accept/reject with the grounded sibling shown, and a method-own sibling bound (`<U, V : U>`)
is grounded against the turbofish arguments. The receiver-argument threading is unit-tested for chains,
diamonds (agreeing → one grounding, conflicting → none), cycles, and arity gaps. The variance
consistency is pinned in the variance-position phase. See [type bounds](../syntax/type-bounds.md).

## More Information

- [ADR-0005](0005-nominal-erased-bound-checking.md) — the nominal, erased bound check this grounds into.
- [ADR-0014](0014-variance-markers-are-class-level-only.md) — why `U` is invariant (this is not method-level variance).
- [ADR-0004](0004-marker-interfaces-for-instanceof.md) — generic templates lower to empty markers.
- [Type bounds](../syntax/type-bounds.md), [variance](../syntax/variance.md).
