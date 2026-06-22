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

$box = new Box::<Product>();
$box->contains::<Book>(new Book());   // OK — Book is a subtype of Product
```

At the call site the bound `E` is **grounded** to the receiver's
concrete type argument (`Product` for a `Box<Product>`), then checked
like any other bound. A genuine violation
(`Box<Book>` then `->contains::<Product>(...)`) is rejected, with the
message naming the grounded type (`Book`). The receiver's argument is
threaded through `extends`/`implements`, so a method declared on a
generic interface/base and inherited by a concrete collection grounds
the same way.

When the receiver's argument can't be determined statically — an opaque
receiver, or a `$this->m::<...>()` call inside the class body, where `E`
has no concrete value yet — the bound is **left unchecked** for that
call rather than reported as a spurious violation. (Bounding a *static*
method's type parameter by the class parameter is likewise unchecked: a
class type parameter has no value in a static context.)

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
