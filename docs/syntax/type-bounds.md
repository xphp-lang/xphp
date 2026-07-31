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

- A bound can be any valid PHP class or interface name, or a
  [type alias](type-aliases.md) that resolves to one (`type Named = Face;
  T : Named` checks against `Face`; a union alias becomes a union bound).
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
- Bounds are an **invariant position** for variance markers — `out T`
  or `in T` are rejected inside a bound expression (whether as a bare
  leaf, `class Pair<out T, U : T>`, or nested, `Sortable<out T : Box<T>>`).
  See [variance](variance.md).

## Bounding a method type parameter by the enclosing class parameter

A method-level type parameter may be bounded by one of the **enclosing
class's** type parameters. This is the sound way to give a covariant
`<out E>` collection an element-consuming method without dropping to
`mixed`: the argument is constrained to a subtype of the element type,
while the covariant `out E` never enters a parameter position.

```php
class Box<out E> {
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

A **direct, concrete** `$this`-self-call (a hardcoded turbofish type on `$this`)
is an intentionally loud limitation — its bound is checkable only once the class
is instantiated, so for now it fails (the *forwarding* form below is the way to
make it compile and run):

```php
class Box<out E> {
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
class Box<out E> {
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

### Element-typed methods on a covariant interface

The method may be declared on a covariant **interface** and called through a
covariant **upcast** — the shape a collections library uses:

```php
interface Collection<out E> {
    public function contains<U : E>(U $value): bool;
}
abstract class AbstractColl<out E> implements Collection<E> {
    public function __construct(private E ...$items) {}
    public function contains<U : E>(U $value): bool { /* ... */ }
}
class ListColl<out E> extends AbstractColl<E> {}

function anyProduct(Collection<Product> $c): bool {
    return $c->contains::<Product>(new Product());
}
$books = new ListColl::<Book>(new Book());   // Book <: Product
anyProduct($books);                           // OK — upcast to Collection<Product>
```

Each interface specialization declares its own erased member
(`Collection<Book>` has `contains_<Book>`, `Collection<Product>` has
`contains_<Product>` — distinct, so the covariant edge never narrows a
parameter). When the element-consuming body sits on a **parent-less covariant
base** that passes its type parameters straight to the interface — the
`AbstractColl<out E> implements Collection<E>` shape above — the implementation is
**inherited** through the covariant chain.

When inheritance can't carry it — the implementing class has another `extends`
parent, implements only a *parent* of the interface, or reorders the
`implements` clause — the member is instead emitted **directly** onto the upcast
source. Its bounded parameter widens to the supertype argument (`U → Product`),
while the body reads the source's own element type (`E → Book`); that split is
sound because the source's element is a subtype of the supertype, so reading the
instance's own backing state through the widened parameter is type-safe. A class
upcast to several supertypes gets one such member each (distinct mangled names —
no redeclaration).

> The body reads at the source's element type, not the supertype. For a method
> whose body inspects `E` structurally — `return $value instanceof E;` — the
> directly-emitted member tests against the source's concrete element (`Book`),
> the runtime instance's actual element type.

A handful of shapes have no sound emittable member and remain a compile error
(`xphp.unschedulable_covariant_upcast`) rather than a runtime fault — ground or
fail:

- **No class body** — the method is truly abstract or its only body is supplied
  through a trait (trait-imported members aren't modelled in the type hierarchy;
  see [ADR-0019](../adr/0019-trait-members-are-not-modeled-in-the-type-hierarchy.md)).
- **The return type names the element parameter** — e.g. `first<U : E>(U $fallback): E`.
  Direct emission would return the widened (supertype) value through the narrower
  element return type, a runtime `TypeError`. (Such a method still works through the
  inheritance path, which grounds the whole member at one argument.)
- **Parameters bounded by different enclosing parameters** — `pick<U : E, V : F>`.
  No single member can be derived for a non-uniform bound.

## No supertype (lower) bounds

Every bound above is an **upper** bound: it constrains the concrete type to be a
*subtype* of the bound. There is no *lower* bound — no way to say "`S` must be a
**supertype** of `X`" (Scala's `[S >: T]`). A `<S : super E>` clause is a **parse
error**, by design ([ADR-0022](../adr/0022-bounds-are-upper-only.md)).

This matters for one shape: a **widening** operation on a covariant collection —
a `reduce` / `fold`-to-supertype whose accumulator/result `S` may be *wider* than
the element (the first element seeds the accumulator, so the element must be
assignable to it). Express it as a **static** generic with a **sibling upper
bound**, where the element parameter `T` is bounded above by the accumulator `S`:

```php
final class Reducing {
    // T : S — the element is a subtype of the accumulator S (so S is a supertype of T).
    public static function reduceOrNull<S, T : S>(Closure(S $acc, T $x): S $op, T ...$items): ?S
    { $a = null; foreach ($items as $i) { $a = $a === null ? $i : $op($a, $i); } return $a; }
}
class Product {}
class Book extends Product {}

// Accumulate Books into a Product accumulator — the widening `T : S` expresses.
Reducing::reduceOrNull::<Product, Book>(fn(Product $a, Book $x): Product => $a, new Book());  // ✓
// T = Product is not a subtype of S = Book — rejected (xphp.bound_violation):
Reducing::reduceOrNull::<Book, Product>(fn(Book $a, Product $x): Book => $a, new Product());  // ✗
```

Because both `S` and `T` are free type parameters, the ordinary upper bound
`T : S` carries the whole widening relationship — the same way Kotlin types its
`reduce` (`<S, T : S>`), just as a static helper rather than an extension method.
The one thing this can't do is live as a *fluent member* of a fixed-element
`List<out E>`: there is no free `T` to bound against the class's `E`, so a member
would need a lower bound (`<S : super E>`), which xphp does not have. Making that
widening operation a member (`$list->reduceOrNull::<Product>(…)`) is a roadmap
possibility, not a current feature — see [ADR-0022](../adr/0022-bounds-are-upper-only.md)
and the roadmap [Discovery → type system breadth](../roadmap.md#type-system-breadth).

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
