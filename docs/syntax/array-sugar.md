# `T[]` array sugar

xphp accepts the documentation-friendly `T[]` notation as shorthand
for "array of T". It lowers to plain `array` at compile time. This is
an xphp-specific convenience — the
[PHP RFC for generics](https://wiki.php.net/rfc/bound_erased_generic_types)
explicitly bans the `array<K, V>` syntax, so xphp doesn't accept that.

## Example

```php
<?php
declare(strict_types=1);

namespace App;

class Collection<T> {
    private T[] $items = [];

    public function set(T[] $items): void {
        $this->items = $items;
    }

    public function all(): T[] {
        return $this->items;
    }

    // Two-dimensional sugar also works
    public function grid(): T[][] {
        return [$this->items, $this->items];
    }
}
```

## What gets emitted

`T[]` lowers to plain `array` in every position. The element-type
enforcement still happens wherever T appears in a position PHP can
enforce — usually a variadic param (`T ...$items`) or single-element
setter (`T $x`):

```php
namespace XPHP\Generated\App\Collection;

class T_<hash-of-int> implements \App\Collection {
    private array $items = [];
    public function set(array $items): void { $this->items = $items; }
    public function all(): array { return $this->items; }
    public function grid(): array { return [$this->items, $this->items]; }
}
```

## Rules

- `T[]`, `Name[]`, and chained `T[][]`, `T[][][]`, ... all lower to
  plain `array`.
- Mixing concrete and generic: `User[]` lowers to `array` (with the
  same enforcement caveat).
- `array<K, V>` (RFC-banned form) is not accepted.
- The sugar is a parser convenience; it doesn't affect
  monomorphization (no extra specializations from it).

## Caveats

- Element-type enforcement only happens where T appears outside the
  `[]` sugar. If your generic API exposes `T[]` everywhere and never
  takes a single `T`, runtime type checks won't catch a mistyped
  element. Add a variadic `__construct(T ...$items)` or a `set(T $x)`
  setter to anchor enforcement.

## See also

- Test fixture: `test/fixture/compile/array_sugar/`
- Related: [classes and interfaces](classes-and-interfaces.md)
