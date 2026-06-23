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
- Never false-reject code that compiles today — a covariant generics library can't afford it.
- Keep the existing nominal/erased bound check; add grounding, don't replace it.
- Cover the shape real libraries use: methods declared on a generic **interface/base** and inherited
  by concrete collections.

## Decision Outcome

Chosen: **at a method-generic call site, ground each bound that names an enclosing class type
parameter against the receiver's concrete type arguments, then run the existing bound check.**

- The receiver's type arguments are recovered from flow typing (a parameter's declared
  `Box<Fruit>`, a `new Box::<Fruit>()` local, a `$this->prop` of declared generic type) and
  threaded up the parameterized `extends`/`implements` chain to the method's **declaring** class, so
  a method inherited from `Collection<+E>` grounds against an `ArrayList<Fruit>` receiver.
- A bound leaf that is still a bare type parameter after grounding — the argument couldn't be
  determined (an opaque/inherited-but-unparameterized receiver, a post-branch merged receiver, or a
  `$this` call inside the still-uninstantiated template body) — has its bound **dropped for that
  call** (treated as unbounded) rather than checked against the phantom name.
- A bound that does **not** name an enclosing parameter — a real class, or an F-bounded
  `Comparable<T>` leaf — is untouched and checked exactly as before.

### Consequences

- Good: the one place a covariant collection degraded to `mixed` now has a sound, element-typed
  parameter; `Box<Fruit>::contains<Banana>` is accepted and `Box<Fruit>::contains<Rock>` is
  rejected with the bound shown **grounded** (`Fruit`), not `E`.
- Trade-off — **lenient drop is a deliberate loosening, not "always sound".** Where the receiver's
  argument can't be determined, an ungroundable bound goes from today's *hard reject* to a *silent
  accept*, so a genuine violation the compiler can't analyze is no longer caught. We accept this
  because the alternative (a hard error) re-introduces false-rejects on currently-compiling code, and
  a blanket warning is noisy (a branch-merged receiver routinely drops its arguments). A *targeted*
  `xphp check` diagnostic for the narrow "receiver known, arity correct, still ungroundable" case is
  possible future work.
- Trade-off — **compound bounds drop whole.** A method bound like `<U : Named & E>` whose `E` can't
  be grounded drops the entire bound, including the checkable `Named` operand. Narrow (it needs a
  concrete operand intersected with an ungroundable enclosing parameter) and an extension of the
  lenient-drop decision; a future refinement could drop only the ungrounded operand.
- Scope — **static methods are out.** A class type parameter is unbound in a static context, so a
  static method's enclosing-parameter bound is never grounded (it falls to lenient drop). There is no
  use case (the motivating methods are all instance methods).
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

The grounding, the inheritance threading, and the lenient fallbacks are covered end-to-end: a direct
and an **inherited** (`ArrayList<Fruit> extends Base<+E>`) accept, a multi-argument
(`Pair<K, +V>::containsValue<U : V>`) accept that grounds the right parameter, a reject whose
message shows the grounded bound, the unbounded method generic unchanged, and the lenient cases
(`$this` body, a parameter / property / closure-`use` receiver, and a branch-merge that drops
conflicting arguments). The receiver-argument threading is unit-tested for chains, diamonds
(agreeing → one grounding, conflicting → none), cycles, and arity gaps. The variance consistency is
pinned in the variance-position phase. See [type bounds](../syntax/type-bounds.md).

## More Information

- [ADR-0005](0005-nominal-erased-bound-checking.md) — the nominal, erased bound check this grounds into.
- [ADR-0014](0014-variance-markers-are-class-level-only.md) — why `U` is invariant (this is not method-level variance).
- [ADR-0004](0004-marker-interfaces-for-instanceof.md) — generic templates lower to empty markers.
- [Type bounds](../syntax/type-bounds.md), [variance](../syntax/variance.md).
