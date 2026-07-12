# Classes and interfaces

Generic classes, interfaces, and traits declare type parameters in
angle brackets after the name. Each unique instantiation produces its
own real class at compile time.

## Example

```php
<?php
declare(strict_types=1);

namespace App;

class Box<T> {
    public function __construct(public T $item) {}
    public function get(): T { return $this->item; }
}

interface Container<T> {
    public function add(T $item): void;
    public function all(): array;
}

trait HasItem<T> {
    public T $item;
}

$intBox = new Box::<int>(42);
$strBox = new Box::<string>('hello');
```

## What gets emitted

The template `Box<T>` produces an empty marker interface at the
original FQN, plus one specialized class per concrete arg:

```php
// dist/App/Box.php (marker)
namespace App;
interface Box {}

// cache/Generated/App/Box/T_<hash-of-int>.php
namespace XPHP\Generated\App\Box;
class T_3a9f... implements \App\Box {
    public function __construct(public int $item) {}
    public function get(): int { return $this->item; }
}
```

The `new Box::<int>(42)` call site rewrites to
`new \XPHP\Generated\App\Box\T_3a9f...(42)`. From the user's
perspective the syntax is `new Box::<int>(42)`; the long FQN is an
implementation detail.

## Rules

- Type parameters are simple identifiers (`T`, `K`, `V`,
  `TElement`). No leading `$`.
- Any arity works: `Box<T>`, `Map<K, V>`, `Triple<A, B, C>`.
- Type parameters can appear anywhere a type can — properties,
  method params, return types, `new` expressions, type hints.
- Nesting works at arbitrary depth: `Box<List<T>>`, `Map<K, List<V>>`.
- Anonymous classes can't be generic — `new class<T> { ... }` is a
  syntax error.

## Caveats

- **Marker-interface `instanceof`** — `$x instanceof App\Box`
  returns true for every `Box<...>` specialization. This is an
  intentional convenience; see
  [runtime semantics](../guides/runtime-semantics.md#instanceof-on-the-original-template).
- **Traits don't get markers** — `instanceof SomeTrait` doesn't work
  in PHP, so generic traits are dropped from the emit after
  specialization.
- **Generic traits work with `insteadof` / `as`** — a conflict
  resolution block (`use A<int>, B<int> { A::m insteadof B; B::m as
  bm; }`) rewrites its operand names to the same specializations as
  the `use` list, so the adapted class loads and runs. A bare operand
  that matches two different specializations of one trait (`use
  A<int>, A<string>`) can't be disambiguated in an adaptation clause
  and is rejected.
- **Variance edges** — when `out T` or `in T` is declared, specializations
  get real `extends` chains. See [variance](variance.md).

## See also

- Test fixture: `test/fixture/compile/box_generic/`
- Test fixture: `test/fixture/compile/generic_interface/`
- Test fixture: `test/fixture/compile/nested_instantiation/`
- Test fixture: `test/fixture/compile/generic_trait_adaptation/`
- Related: [methods and functions](methods-and-functions.md),
  [turbofish](turbofish.md)
