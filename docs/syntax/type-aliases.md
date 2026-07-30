# Type aliases

A type alias gives a name to a type — generic or not — so you can write
it once and reuse it. It's a **compile-time substitution**: the alias is
expanded into its body before specialization and has no runtime
existence, so the emitted PHP never mentions the alias name.

```php
type Pair<A, B> = Dict<A, Bag<B>>;   // generic alias
type UserId     = Ident;             // non-generic alias (a plain class)
type UserMap    = Pair<int, User>;   // a concrete instantiation of another alias
type Num        = int|string;        // union body
type MaybeUser  = ?User;             // nullable body
```

## Example

```php
<?php
declare(strict_types=1);

namespace App;

type Pair<A, B> = Dict<A, Bag<B>>;
type UserId     = Ident;

class Service {
    public function pair(): Pair<int, User> {
        return new Pair::<int, User>(1, new Bag::<User>(new User()));
    }

    public function id(): UserId {
        return new UserId();
    }
}
```

## What gets emitted

Each use is replaced by its expanded body, then monomorphized exactly as
if you had written the body by hand. `Pair<int, User>` expands to
`Dict<int, Bag<User>>` (a real specialization); `UserId` expands to the
plain class `Ident`. The `type …` declarations themselves vanish.

```php
namespace App;

class Service {
    public function pair(): \XPHP\Generated\App\Dict\T_<hash-of-Dict-int-Bag-User> {
        return new \XPHP\Generated\App\Dict\T_<hash-…>(1, new \XPHP\Generated\App\Bag\T_<hash-of-User>(new User()));
    }
    public function id(): \App\Ident {
        return new \App\Ident();
    }
}
```

Because expansion happens before specialization, an aliased generic
records and specializes the same class an explicit type would — there is
no separate code path and no runtime cost.

## Rules

- **Declaration forms**: `type Name<A, B> = Body;` (generic) and
  `type Name = Body;` (non-generic). The parameter list is optional; the
  separator is `=`.
- **Bodies**: a single (possibly-generic) head (`Ident`, `Dict<A, B>`), a
  **union** (`int|string`), or a **nullable** (`?Box`). A single-head or
  generic body expands in **every** type position, including as a generic
  argument (`Bag<UserId>`), `new`, `extends`, and a bound. A **union /
  nullable** body expands only as the *whole* type of a parameter,
  property, return, or class-constant slot (see caveats).
- **Cross-file**: an alias declared in one file is usable in another file
  of the same build (the whole program shares one alias table).
- Aliases compose: an alias body may reference another alias
  (`type UserMap = Pair<int, User>`), and an alias may take type
  parameters used inside its body (`type Pair<A, B> = Dict<A, Bag<B>>`).
- A non-alias name of the same shape is untouched — only a declared alias
  is expanded.
- The following are compile errors (each with a stable code, reported by
  both `xphp compile` and `xphp check`):
  - `xphp.alias_cycle` — an alias defined, directly or transitively, in
    terms of itself (`type A = B; type B = A;`).
  - `xphp.alias_arity` — a use whose type-argument count differs from the
    alias's parameter count (`type P<A, B> = …;` used as `P<int>`).
  - `xphp.alias_class_collision` — an alias whose name collides with a
    class, interface, or trait of the same name (no silent shadowing).
  - `xphp.alias_duplicate` — the same alias name declared twice.
  - `xphp.alias_unsupported_body` — an intersection / DNF / closure-signature
    body (see caveats below).
  - `xphp.alias_compound_in_non_slot` — a union / nullable alias used
    outside a whole slot (see caveats below).

## Caveats

Union and nullable bodies and cross-file use all work; the remaining
limits are the body shape and the positions a compound alias can take. See
[caveats → type-alias body and position limits](../caveats.md#type-alias-body-and-position-limits)
for the details and the reasons:

- **Intersection / DNF / closure bodies** (`A&B`, `(A&B)|C`,
  `Closure(int): int`) are rejected with `xphp.alias_unsupported_body` —
  write the type directly or wrap it in a named class/interface.
- **A union / nullable alias is a whole-slot type only.** As a generic
  argument, in `new` / `extends` / a bound, or nested inside another
  union/intersection, it is `xphp.alias_compound_in_non_slot`.
- **Cross-file collision / duplicate not detected.** An alias colliding
  with a class, or the same alias declared, in a *different* file is not
  flagged (both are within one file).

## See also

- Test fixture: `test/fixture/compile/type_aliases/`
- Related: [classes and interfaces](classes-and-interfaces.md),
  [turbofish](turbofish.md)
