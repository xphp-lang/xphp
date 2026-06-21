# Variance

Variance markers tell the compiler how subtyping flows through a
generic parameter. `+T` declares the parameter covariant; `-T`
declares it contravariant; unmarked is invariant. With markers in
place, specializations get real `extends` chains and PHP's native
LSP carries the subtype relationship.

## Example

```php
<?php
declare(strict_types=1);

namespace App;

// Covariant: T appears in return positions only
class Producer<+T> {
    public function __construct(private T $item) {}
    public function get(): T { return $this->item; }
}

// Contravariant: T appears in parameter positions only
class Consumer<-T> {
    public function accept(T $x): void { /* ... */ }
}

// Unmarked T is invariant -- no subtype edge between specializations
class Box<T> {
    public function __construct(public T $item) {}
    public function set(T $item): void { $this->item = $item; }
}

class Fruit {}
class Banana extends Fruit {}

// With +T, this is now a valid downcast at the type-system level:
function eat(Producer<Fruit> $p): Fruit {
    return $p->get();
}
eat(new Producer::<Banana>(new Banana()));
```

## What gets emitted

For each subtype relationship `Banana extends Fruit` in your source,
the compiler emits a real `extends` edge between the corresponding
specializations:

```php
namespace XPHP\Generated\App\Producer;

class T_<hash-of-fruit> implements \App\Producer {
    public function get(): \App\Fruit { ... }
}

class T_<hash-of-banana> extends T_<hash-of-fruit> implements \App\Producer {
    public function get(): \App\Banana { ... }
}
```

PHP's native LSP handles the relationship from there — passing a
`Producer<Banana>` where a `Producer<Fruit>` is required Just Works,
including reflection and `instanceof`.

For contravariant `-T`, the edge flips: `Consumer<Fruit> extends Consumer<Banana>`.

## Rules

Position rules enforced at parse time:

| Position                              | `+T` allowed? | `-T` allowed? |
|---------------------------------------|---------------|---------------|
| Method return type                    | ✅            | ❌            |
| Method parameter                      | ❌            | ✅            |
| By-reference parameter (`T &$x`)      | ❌            | ❌            |
| Constructor parameter (plain)         | ✅            | ✅            |
| Mutable property                      | ❌            | ❌            |
| Readonly property                     | ❌            | ❌            |
| Promoted constructor property         | ❌            | ❌            |
| Bound expression                      | ❌            | ❌            |
| Default expression                    | ❌            | ❌            |

The strict-invariance rule on **properties** (mutable, readonly, and
promoted-constructor) is forced by the runtime model: xphp emits real
`extends` chains between specialised classes, and PHP enforces invariant
property types across those chains regardless of `readonly` — a covariant
property would PHP-fatal at autoload when the variance edge lands.

A **by-reference parameter** (`function f(T &$x)`) is likewise invariant: the
caller's variable is both read and written back through the reference, so it acts
as input *and* output — neither `+T` nor `-T` is sound. This holds in method,
constructor, and nested closure/arrow signatures.

A **plain (non-promoted) constructor parameter** is the exception: it may
carry `+T` / `-T` at any variance, and xphp emits it with its **real**
substituted type. A constructor parameter isn't part of the externally-visible
variance surface (a constructor is never reached through an upcast reference —
the same reason Kotlin exempts constructor parameters from variance checks), and
PHP exempts `__construct` from LSP signature checks, so the specialisations'
constructors may legitimately differ across the edge. That's what lets a
covariant immutable collection take *type-checked* construction input (see
below). A *promoted* constructor parameter is a property, so it stays strictly
invariant.

### Covariant immutable collections (typed construction)

A covariant container can take its element type in its constructor — the
backbone of a read-only `List<out T>`-style collection:

```php
class ImmutableList<+T> {
    private array $items;
    public function __construct(T ...$items) { $this->items = $items; }
    public function get(int $i): T { return $this->items[$i]; }
}

function firstProduct(ImmutableList<Product> $items): Product { return $items->get(0); }

// Covariance: an ImmutableList<Book> is accepted where ImmutableList<Product>
// is expected, because Book extends Product.
$books = new ImmutableList::<Book>(new Book(), new Book());
$p = firstProduct($books);
```

The constructor parameter keeps its real element type on every specialisation
(`Book ...$items` on `ImmutableList<Book>`, `Product ...$items` on
`ImmutableList<Product>`), and `ImmutableList<Book>` still `extends
ImmutableList<Product>` without a PHP fatal — PHP doesn't signature-check
`__construct` across the chain. (A variant class **cannot be declared `final`**:
its specializations are linked by `extends` edges, which a `final` class can't
anchor, so `final` on a `+T`/`-T` class is rejected at compile time.)

> ✅ **Construction is runtime-type-checked.** Because the constructor parameter
> keeps its real type, PHP enforces it at construction: building an
> `ImmutableList<Book>` from a non-`Book` throws a `TypeError`. You get both
> covariance *and* a real construction-time guarantee — nothing is erased. The
> one position that can't carry a real `T` is a stored **property** (PHP property
> types are invariant across the edge), so hold elements in a plain `array`/`mixed`
> backing field and expose them through a covariant `get(): T`, as above.

### Inner-template variance composition

When a generic class uses another generic class in its method
signatures, the bounds compose:

```php
// Container's X is invariant, so Container<T> reads as invariant
// regardless of T's outer variance.
class P<+T> {
    public function f(): Container<T> {}     // REJECTED
}
```

`T` appears in a covariant outer position (return), but the inner
`Container<X>` has `X` as invariant — the composed position is
invariant, so the outer `+T` is rejected. The validator walks every
generic class's method signatures, bounds, and defaults to apply this
composition.

## Caveats

- > ⚠️ **Not allowed on closures or arrows** — anonymous templates
  don't have stable identity for an `extends` chain. See
  [caveats](../caveats.md#variance-markers-are-class-level-only).

- > ⚠️ **Trait-imported methods aren't walked for variance** — a
  generic class that `use`s a trait whose method references T won't
  catch variance violations inside that trait method. Niche; see
  [caveats](../caveats.md#variance-validator-and-trait-use).

## See also

- Test fixture: `test/fixture/compile/variance_covariant_happy/`
- Test fixture: `test/fixture/compile/variance_contravariant_happy/`
- Test fixture: `test/fixture/compile/variance_with_defaults_and_bounds/`
- Related: [type bounds](type-bounds.md),
  [runtime semantics](../guides/runtime-semantics.md)
