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
- A generic method declared on a base class is callable through
  inheritance — see below.

## Inheritance

A generic method declared on a base (or abstract) class is callable via
turbofish on a **subclass** receiver, for instance, static, and nullsafe
calls. Resolution walks the receiver's ancestor chain (nearest first), and
the specialization is emitted **once** on the declaring class, so every
subclass inherits the single body. A subclass that redeclares the method
shadows the inherited one.

```php
abstract class AbstractCollection<T> {
    // A generic helper shared by every concrete collection.
    public function wrap<U>(U $value): U {
        return $value;
    }
}
class Collection<T> extends AbstractCollection<T> {}

$c = new Collection::<int>();
// `wrap` isn't declared on Collection — it resolves to
// AbstractCollection::wrap, specialized once on the base and inherited:
$s = $c->wrap::<string>('hi');
```

A turbofish call to a generic method that exists on neither the receiver
nor any of its ancestors is a **compile-time error**
([`xphp.unresolved_generic_call`](../errors.md#diagnostic-codes)), caught at
build time instead of fataling at runtime with "Call to undefined method".

> Resolution follows `extends`/`implements` ancestors. A generic method
> reached only through a `use`d trait is not resolved through inheritance.

## Caveats

- > ⚠️ Branching narrowing precision: if `$x` is reassigned inside a
  branch and the arms disagree on the class, the receiver's type is
  undetermined and a post-branch turbofish call is a compile error
  (`xphp.undetermined_receiver`) rather than a silently de-specialized
  call. See
  [caveats](../caveats.md#branching-narrowing-precision-loss).

- > ⚠️ Receiver-type tracking follows declared parameter and property
  types, `$this`, local `new` assignments, a value returned by a
  **method** or chained call (`$x = $repo->get(); $x->m::<T>()`), and a
  branch whose arms agree. A value from a **free function** (`$x = make()`)
  isn't tracked — give such a local a typed parameter/property hop, or
  the turbofish call fails as an undetermined receiver.

## See also

- Test fixture: `test/fixture/compile/generic_method/`
- Test fixture: `test/fixture/compile/generic_function/`
- Test fixture: `test/fixture/compile/generic_method_through_inheritance/`
- Test fixture: `test/fixture/compile/generic_static_method_through_inheritance/`
- Related: [closures and arrows](closures-and-arrows.md),
  [turbofish](turbofish.md)
