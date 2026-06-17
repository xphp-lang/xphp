# Pseudo-types: `self<T>`, `static<T>`, `parent<T>`

PHP's pseudo-types (`self`, `static`, `parent`) can carry type
arguments in xphp generics. They flow through specialization without
becoming a new template — at runtime they resolve to the right
specialized class via PHP's own rules.

## Example

```php
<?php
declare(strict_types=1);

namespace App;

class Container<T> {
    public function __construct(public T $item) {}

    // self<T> in a return position
    public function with(T $newItem): self<T> {
        return new self::<T>($newItem);
    }

    // static<T> for late-static-binding
    public function fresh(T $v): static {
        return new static::<T>($v);
    }
}

class Child<T> extends Container<T> {
    public function makeParent(T $v): Container<T> {
        return new parent::<T>($v);
    }
}

$c = new Container::<int>(1);
$c2 = $c->with(2);     // returns Container<int>

$child = new Child::<string>('a');
$p = $child->makeParent('b');     // returns Container<string>
```

## What gets emitted

Pseudo-type clauses (`self<T>`, `new self::<T>()`, etc.) strip cleanly
from the source so PHP's parser sees plain `self` / `static` /
`parent`. Inside the specialized class, those names resolve to the
specialized class itself via standard PHP semantics:

```php
namespace XPHP\Generated\App\Container;

class T_<hash-of-int> implements \App\Container {
    public function __construct(public int $item) {}
    public function with(int $newItem): self {
        return new self($newItem);    // self is THIS specialized class
    }
    public function fresh(int $v): static {
        return new static($v);
    }
}
```

No marker leaks to a phantom `App\self` template — the compiler
short-circuits pseudo-types so they're never treated as user
templates.

## Rules

Two positions are supported:

- **Type-hint position**: `self<T>`, `static<T>`, `parent<T>` in
  return types, parameter types, property types.
- **Constructor turbofish**: `new self::<T>(...)`, `new static::<T>(...)`,
  `new parent::<T>(...)` at `new` expressions.

The static-method-on-pseudo-type form
(`self::method::<T>(...)`, `static::method::<T>(...)`,
`parent::method::<T>(...)`) was already supported before pseudo-type
turbofish — the marker attaches to `method`, not to the leading
pseudo-type, and the receiver short-circuits.

## Caveats

- **`parent` requires a generic parent class** — `new parent::<T>(...)`
  on a class whose parent isn't generic still strips the `<T>` and
  resolves at runtime, but the type args are effectively discarded
  for that call. Useful only when the parent is generic.
- **Late-static-binding still applies** — `new static::<T>()` resolves
  to whatever the runtime's late-static class is, exactly as for plain
  `new static()`. xphp doesn't substitute `static` away during
  specialization.
- **Receiver-type analysis sees pseudo-types as the enclosing class**
  — calls like `self::method::<T>(...)` resolve `self` to the
  enclosing class's specialization, so they pick the correct method
  template the same way `$this->method::<T>(...)` does.

## See also

- Test fixtures: `test/fixture/compile/generic_method_new_self_turbofish/`,
  `generic_method_self_with_type_args/`, and
  `generic_method_new_static_turbofish/` (exercise `self` / `static`)
- Related: [classes and interfaces](classes-and-interfaces.md),
  [turbofish](turbofish.md)
