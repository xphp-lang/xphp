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

| Position                                | `+T` allowed? | `-T` allowed? |
|-----------------------------------------|---------------|---------------|
| Method return type                      | ✅            | ❌            |
| Method parameter                        | ❌            | ✅            |
| Mutable property                        | ❌            | ❌            |
| Readonly property                       | ❌            | ❌            |
| Constructor parameter                   | ❌            | ❌            |
| Bound expression                        | ❌            | ❌            |
| Default expression                      | ❌            | ❌            |

The strict-invariance rule on properties and constructors is forced
by the runtime model. Under bound-erasure (the RFC's path) variance
doesn't materialise as `extends` edges, so the issue doesn't arise.
xphp emits real `extends` chains between specialised classes, and
PHP enforces invariant property types and constructor signatures
across those chains regardless of `readonly` — a covariant property
would PHP-fatal at autoload when the variance edge lands.

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
