# Type bounds

A bound constrains what concrete types satisfy a generic parameter.
xphp supports single bounds, intersection, union, full DNF, and
F-bounded (self-referential) bounds.

> **F-bounded polymorphism**: a parameter constrained by a type that
> mentions the parameter itself, like `T : Comparable<T>`. The
> concrete must be comparable specifically to other instances of
> itself. Standard in Java, Kotlin, Rust, and TypeScript.

## Example

```php
<?php
declare(strict_types=1);

namespace App;

// Single upper bound
class StringBag<T : \Stringable> {
    public function __construct(public T $value) {}
}

// Intersection
class CountedStringBag<T : \Stringable & \Countable> {
    public function __construct(public T $value) {}
}

// Union (any operand suffices)
class JsonOrString<T : \JsonSerializable | \Stringable> {
    public function __construct(public T $value) {}
}

// Disjunctive normal form: (A & B) | C
class AnyOf<T : (\JsonSerializable & \Countable) | \Stringable> {
    public function __construct(public T $value) {}
}

// F-bounded: T must be comparable to itself
class Sortable<T : Comparable<T>> {
    public function __construct(public T $value) {}
}

class User implements \Stringable {
    public function __toString(): string { return 'user'; }
}

$ok = new StringBag::<User>(new User());
// new StringBag::<int>(...)  --  would fail bound check
```

## What gets emitted

Bound checks run at compile time. They produce no runtime code —
they fail the build with a source-level error if a concrete arg
doesn't satisfy the bound:

```
Generic bound violated while instantiating App\StringBag<int>.
  type parameter T is bounded by Stringable
  but the supplied concrete type is int

  "int" does not extend/implement "Stringable".
```

The specialized class itself just has the concrete type baked in
(no extra runtime check), since PHP's own type system enforces it
once it sees `public int $value`.

## Rules

- A bound can be any valid PHP class or interface name.
- Intersection: `T : A & B` — concrete must satisfy both.
- Union: `T : A | B` — any operand suffices.
- DNF: `T : (A & B) | C` — outer OR of inner ANDs.
- F-bounded: `T : Box<T>` is allowed because the inner T sits inside
  `Box`'s args, not as a top-level leaf. `T : T` at the top level is
  rejected at parse time.
- A small built-in interface whitelist (`Stringable`, `Countable`,
  `Iterator`, `ArrayAccess`, `JsonSerializable`, `Throwable`, etc.)
  resolves without needing a source declaration.
- A concrete arg that the compiler can't reason about (not in the
  source set, not a built-in) fails with a clear "cannot prove
  satisfaction" message — widen the bound or add the type to the
  source set. The same "not in the source set" condition on a
  *variance* type argument is a non-failing warning rather than an
  error — see [variance](variance.md#unprovable-variance-edges).
- Bounds are an **invariant position** for variance markers — `+T`
  or `-T` are rejected inside a bound expression (whether as a bare
  leaf, `class Pair<+T, U : T>`, or nested, `Sortable<+T : Box<T>>`).
  See [variance](variance.md).

## Bounding a method type parameter by the enclosing class parameter

A method-level type parameter may be bounded by one of the **enclosing
class's** type parameters. This is the sound way to give a covariant
`<+E>` collection an element-consuming method without dropping to
`mixed`: the argument is constrained to a subtype of the element type,
while the covariant `+E` never enters a parameter position.

```php
class Box<+E> {
    // U is a method type parameter (invariant), bounded by the class's E.
    public function contains<U : E>(U $value): bool { /* ... */ }
}

$box = new Box::<Fruit>();
$box->contains::<Banana>(new Banana());   // OK — Banana is a subtype of Fruit
```

At the call site the bound `E` is **grounded** to the receiver's
concrete type argument (`Fruit` for a `Box<Fruit>`), then checked
like any other bound. A genuine violation
(`Box<Fruit>` then `->contains::<Rock>(...)`) is rejected, with the
message naming the grounded type (`Fruit`). The receiver's argument is
threaded through `extends`/`implements`, so a method declared on a
generic interface/base and inherited by a concrete collection grounds
the same way. The receiver's type is determined from a typed parameter
or `$this->prop`, a `new`-constructed local, a value returned by a
method or chained call (or a `self`/`static` factory), and a branch
whose arms agree on the same parameterised type.

### Ground or fail

If the receiver's type argument genuinely **can't** be determined — a
raw `Box` parameter with no type argument, a branch whose arms construct
different `Box<...>` types, or a `$this->m::<...>()` self-call inside the
class body (where `E` is the class's own parameter, abstract until the
class is instantiated) — the bound **cannot be proven**, so it is a
**compile error** (`xphp.bound_unprovable`) rather than an unchecked
call:

```php
function pick(Box $b): bool {                 // raw Box — no element type
    return $b->contains::<Banana>(new Banana());
    //     ^ cannot verify `U : E`: bind the receiver to a typed local
    //       (`Box<Fruit> $b`) so its type argument is known.
}
```

This upholds [Maximum Runtime Safety](../../README.md#2-maximum-runtime-safety):
a knowable type is never dropped, and an unprovable bound never becomes a
silent accept or a runtime check — you either ground it or the build
fails, with a message pointing at the fix. A *static* method whose bound
names a class parameter fails the same way: a class type parameter has no
value in a static context, so there is nothing to ground it to.

The `$this`-self-call case is an intentionally loud, temporary limitation —
its bound is provable per instantiation, just not yet checked there:

```php
class Box<+E> {
    public function contains<U : E>(U $value): bool { /* ... */ }

    public function probe(): bool {
        // E is the class's own parameter, abstract until Box is instantiated;
        // whether `Banana : E` holds is instance-dependent (Box<Fruit> yes,
        // Box<Rock> no), so this fails rather than risk an unchecked call.
        return $this->contains::<Banana>(new Banana());
    }
}
```

Move such a call to a context where the receiver has a concrete element type
(e.g. a free function taking `Box<Fruit> $b`) — **or make the method itself
generic and forward the parameter:**

```php
class Box<+E> {
    public function contains<U : E>(U $value): bool { /* ... */ }

    // ✅ Forwarding a method parameter compiles and runs: both methods take U
    // only as a direct input, so each lowers to one `E`-typed member per
    // instantiation and the forward resolves to it.
    public function probe<U : E>(U $value): bool {
        return $this->contains::<U>($value);
    }
}
```

A method whose enclosing-bounded parameter is used **only** as a top-level
input (`U $value`) is lowered by erasing `U` to its bound `E`: one
`contains_<Fruit>(Fruit)` member per `Box<Fruit>`, rather than one per call-site
type. So a forwarded `$this->contains::<U>()` rewrites to that member and runs.
The direct `$this->contains::<Banana>()` above (a *concrete* turbofish on
`$this`) still fails — its bound is checked only on the abstract template — but
the forwarding form is the idiomatic way to call an element-consuming method
from within the class. A parameter used anywhere else (nested `Box<U>`, a
return, `new U`) keeps the per-call lowering and a forwarded self-call to it is
still a compile error.

## Caveats

- > ⚠️ Bounds aren't checked across trait `use` boundaries — if a
  generic class `use`s a trait whose methods reference T, the
  variance / bound rules on those methods aren't recursively walked.
  Niche; covered in
  [caveats](../caveats.md#variance-validator-and-trait-use).

## See also

- Test fixture: `test/fixture/compile/bounds_happy/`
- Test fixture: `test/fixture/compile/bounds_intersection/`
- Test fixture: `test/fixture/compile/bounds_union/`
- Test fixture: `test/fixture/compile/bounds_dnf/`
- Test fixture: `test/fixture/compile/bounds_f_bounded/`
- Related: [variance](variance.md), [defaults](defaults.md)
