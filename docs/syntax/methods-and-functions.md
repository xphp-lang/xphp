# Methods and functions

Generic methods and free functions declare type parameters between
the name and the `(`. Each unique call-site arg tuple produces one
specialized body.

## Example

```php
<?php
declare(strict_types=1);

namespace App;

class Util {
    // Generic static method
    public static function identity<T>(T $x): T {
        return $x;
    }

    // Generic instance method
    public function pair<A, B>(A $a, B $b): array {
        return [$a, $b];
    }
}

// Generic free function (namespaced or bare top-level)
function swap<A, B>(A $a, B $b): array {
    return [$b, $a];
}

$a = Util::identity::<int>(42);
$u = new Util();
$p = $u->pair::<string, int>('age', 30);
$s = swap::<int, string>(1, 'one');
```

## What gets emitted

Each unique arg tuple mints one mangled body in the same scope. Call
sites rewrite to the mangled name:

```php
class Util {
    public static function identity_T_3a9f...(int $x): int {
        return $x;
    }
    public static function identity_T_b82e...(string $x): string {
        return $x;
    }
    public function pair_T_c4d1...(string $a, int $b): array {
        return [$a, $b];
    }
}
function swap_T_e5f3...(int $a, string $b): array {
    return [$b, $a];
}

// Rewritten call sites:
Util::identity_T_3a9f...(42);
$u->pair_T_c4d1...('age', 30);
swap_T_e5f3...(1, 'one');
```

## Rules

- Generic methods work on **non-generic** and **generic** enclosing
  classes alike.
- Generic methods work on **static** and **instance** receivers.
  Nullsafe instance calls (`$obj?->m::<T>()`) are supported too.
- Generic free functions work both inside `namespace { ... }` blocks
  and at bare top-level (no enclosing namespace).
- Two `::<int>` calls share one specialized body — duplicate arg
  tuples dedupe.
- Receiver-type analysis picks the right specialization when the
  receiver is `$this`, a typed param, a typed property, or a local
  `$x = new Foo()` assignment.

## Caveats

- > ⚠️ Branching narrowing precision: if `$x` is reassigned inside a
  branch and the arms disagree on the class, post-branch calls drop
  to a non-specialized path rather than picking a possibly-wrong
  class. See
  [caveats](../caveats.md#branching-narrowing-precision-loss).

- > ⚠️ Receiver-type tracking only follows local-scope assignments
  and parameter types. A `$this->prop` that flows through a getter
  doesn't propagate its concrete class to later call sites.

## See also

- Test fixture: `test/fixture/compile/generic_method/`
- Test fixture: `test/fixture/compile/generic_function/`
- Related: [closures and arrows](closures-and-arrows.md),
  [turbofish](turbofish.md)
