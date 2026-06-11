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
  source set.
- Bounds are an **invariant position** for variance markers — `+T`
  or `-T` are rejected inside a bound expression. See
  [variance](variance.md).

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
