# `Closure(...)` signature types

xphp accepts a **closure signature type** — `Closure(int $x, string $y):
bool` — anywhere a type hint is allowed (a parameter, a return, a
property). It documents the shape of the callable a slot expects, then
**erases to a bare `\Closure`** at compile time, so the emitted PHP is
ordinary code that any PHP runtime accepts.

```php
<?php
declare(strict_types=1);

namespace App;

// A factory that returns an int-to-int closure.
function adder(int $by): Closure(int $x): int {
    return fn(int $x): int => $x + $by;
}
```

compiles to:

```php
<?php
declare(strict_types=1);

namespace App;

function adder(int $by): \Closure {
    return fn(int $x): int => $x + $by;
}
```

The parameter names inside the signature are documentation only (exactly
like a real closure's parameter names); only the types, order, by-reference
markers, and arity carry meaning.

A signature may be nullable (`?Closure(int): int`), may omit the return
(`Closure(int $x)` — any return accepted), and may nest
(`Closure(Closure(int): int $f): int`).

## Conformance checking

Where a closure **literal** is returned against a `Closure(...)` return
type, xphp checks that the literal actually conforms, using the same
variance PHP enforces when an inherited method overrides its prototype:

- **Parameters are contravariant** — each parameter of the literal must be
  the same as or **wider** than the target's.
- **The return is covariant** — the literal's return must be the same as or
  **narrower** than the target's.
- **By-reference-ness is exact**, and **arity must be compatible** (the
  literal must accept every argument the target guarantees, and require no
  more than the target guarantees).

```php
function makeAdder(): Closure(int $x): int {
    return fn(int $x): int => $x + 1;      // ✓ conforms
}

function makeBroken(): Closure(int $x): int {
    return fn(string $x): int => 0;        // ✗ compile error:
                                           //   parameter 1 is not wider than int
}
```

A mismatch is a compile error (`xphp compile` fails; `xphp check` reports
`xphp.closure_conformance`). See [errors](../errors.md).

### Contravariance and covariance, concretely

Scalars can't show *why* the rules point the way they do — `int` and `string`
are unrelated, so neither is "wider" than the other. A class hierarchy makes it
visible. Take:

```php
class LivingThing {}
class Animal extends LivingThing {}
class Dog extends Animal {}
class Cat extends Animal {}
```

and a slot that wants **a function taking a `Dog` and returning some `Animal`**:

```php
function pipeline(): Closure(Dog $d): Animal {
    return /* one of the literals in the table below */;
}
```

The insight is that the slot *owns both ends of the call*: it will only ever
**hand the closure a `Dog`**, and it promises its own caller **an `Animal`
back**.

- **Parameters are contravariant — a *wider* parameter is safe.** The closure is
  only ever called with a `Dog`, so a closure that accepts any `Animal` (or even
  any `LivingThing`) copes fine — a `Dog` *is* an `Animal`. A closure that
  demands a *narrower* or *sibling* type can't: it would be handed a `Dog` it
  refuses.
- **The return is covariant — a *narrower* return is safe.** The caller only
  relies on getting *some* `Animal` back, so a closure returning a `Dog` (a kind
  of `Animal`) satisfies it. One returning a mere `LivingThing` doesn't — the
  caller could get a `LivingThing` that isn't an `Animal`.

| Returned literal        | Parameter                | Return                     | Verdict |
|-------------------------|--------------------------|----------------------------|---------|
| `fn(Dog $d): Animal`    | exact                    | exact                      | ✓ accepted |
| `fn(Animal $a): Animal` | **wider** (contravariant)| exact                      | ✓ accepted |
| `fn(Dog $d): Dog`       | exact                    | **narrower** (covariant)   | ✓ accepted |
| `fn(Animal $a): Dog`    | wider                    | narrower                   | ✓ accepted |
| `fn(Cat $c): Animal`    | sibling — not wider      | —                          | ✗ `parameter 1: Cat is not wider than Dog` |
| `fn(Dog $d): LivingThing` | exact                  | wider — not narrower       | ✗ `return type: LivingThing is not a subtype of Animal` |

(The real diagnostics name the classes fully-qualified; short names are used
here for readability.)

If your instinct says *"if I expect a `Dog`, I shouldn't have to handle a
`Cat`"* — that's exactly right, and it's *why* `fn(Cat $c)` is rejected: the slot
will pass a `Dog`, and a `Cat`-handler can't take it. The very same instinct is
what makes the *wider* `fn(Animal $a)` **safe**, not unsafe: a handler for any
`Animal` never chokes on the `Dog` it's given. Contravariance widens what's
accepted on the way *in*; covariance narrows what's promised on the way *out*.

> This is the **same** variance PHP already enforces when a subclass overrides a
> method — a wider parameter and a narrower return are exactly what PHP permits
> in an override. It's a *structural, per-signature* check, and it is distinct
> from the *declaration-site* `out T` / `in T` class variance in
> [Variance](variance.md), which instead wires real `extends` edges between whole
> specializations. Same underlying principle — subtyping flows one way through
> inputs and the other through outputs — different mechanism.

### It only rejects a *provable* mismatch

The check is deliberately one-directional: it never rejects code it cannot
prove wrong. A parameter or return that is untyped (⇒ `mixed`), a class the
source set doesn't declare, a still-abstract generic type parameter, a
`self`/`static`/`parent`/`object`/`iterable`/`callable` leaf, or a nullable /
union / intersection type is **accepted**. A built-in supertype (a
`Closure(): \Throwable` target) is accepted whenever the candidate could
genuinely satisfy it — a subclass of a built-in (`\Exception`), an enum against
an interface it implements, a `__toString` class against `\Stringable`, or any
class with an unknown ancestor. Only a candidate whose **entire declared
ancestry is user code with no built-in anywhere** is provably unrelated to a
built-in target, and only that is rejected. This mirrors the RFC's runtime
leniency — lenient while unresolved, decide only when provable.

Only the return-position "factory" pattern above is checked, because that is
the one place a closure literal statically meets a `Closure(...)` target: a
default value cannot be a closure (PHP requires a constant expression), and a
closure passed through a variable or a call argument is checked gradually
(accepted).

## Generic signatures

A closure signature may reference the enclosing type parameters, and each is
**grounded** against the concrete type argument when the class specializes:

```php
class Registry<T> {
    public function factory(): Closure(T $value): bool {
        return fn(int $value): bool => $value > 0;
    }
}

new Registry::<int>();     // target grounds to Closure(int): bool — the literal conforms
new Registry::<string>();  // target grounds to Closure(string): bool — the same literal
                           //   is now rejected: int is not wider than string
```

## Union, intersection, and nullable members

A flat union `A|B`, intersection `A&B`, or nullable `?A` inside a signature is
variance-checked member by member, following PHP's own subtyping:

- a union in a **return** conforms when the literal's return fits **some** member
  (`Closure(): int|string` accepts a `fn(): int`);
- a union in a **parameter** requires the literal to accept **every** member
  (`Closure(int|string $x)` handed `fn(int $x)` fails — the literal can't take a
  string);
- an intersection is checked where it is the expected (super) type — a value must
  satisfy **every** member.

As everywhere, an unprovable member keeps the whole leaf gradual: a union or
intersection that mentions an unresolved class, a type parameter, or a
pseudo-type is accepted rather than falsely rejected.

An intersection used as an incoming (parameter) type also stays gradual
(accepted): an intersection of unrelated types is uninhabited, so rejecting it
would be unsound.

A **DNF** signature type (a parenthesised mix such as `(A&B)|C`) is accepted in
every position — parameter, return, nested — and behaves like any other
unresolvable leaf: it erases with the rest of the signature and stays
**gradual** (never variance-checked member by member, never the cause of a
rejection). Arity, by-ref-ness, and the other structured slots around a DNF
leaf are still checked as usual. Structuring a DNF into variance-checked
members is a possible future refinement.

An **array-sugar** leaf (`Closure(Item[] $items): int`, `Closure(): int[]`)
lowers to `array` inside a signature — the same lowering `T[]` gets everywhere
else in xphp — and participates in conformance as `array` (a gradual leaf, so
it can only ever widen acceptance; the arity around it is still checked). The
`Name<Args>[]` combination remains unsupported, in signatures as elsewhere.

## See also

- [Variance](variance.md) — declaration-site `out T` / `in T` class variance:
  the same "inputs one way, outputs the other" principle, applied to whole
  specializations via real `extends` edges rather than per-signature.
- [Closures and arrows](closures-and-arrows.md) — the generic
  `function<T>(...)` / `fn<T>(...)` forms these signatures describe.
- [Errors](../errors.md) — the `xphp.closure_conformance` diagnostic.
- Test fixtures: `test/fixture/check/closure_conformance/`,
  `test/fixture/compile/closure_conformance_grounded_reject/`.
