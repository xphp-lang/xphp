# Type aliases

A type alias gives a name to a type — generic or not — so you can write
it once and reuse it. It's a **compile-time substitution**: the alias is
expanded into its body before specialization and has no runtime
existence, so the emitted PHP never mentions the alias name.

```php
type Pair<A, B> = Dict<A, Bag<B>>;   // generic alias
type UserId     = Ident;             // non-generic alias (a plain class)
type UserMap    = Pair<int, User>;   // a concrete instantiation of another alias
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

- **Two forms**: `type Name<A, B> = Body;` (generic) and
  `type Name = Body;` (non-generic). The parameter list is optional; the
  separator is `=`.
- The alias expands in **every type position** — parameter, return,
  property, `new`, turbofish argument, `extends`/`implements`, and as a
  **generic argument** of another type (`Bag<UserId>`).
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
  - `xphp.alias_unsupported_body` — see caveats below.

## Caveats

Aliases are intentionally a small, safe first step. See
[caveats → type aliases](../caveats.md#type-aliases-are-file-local-and-single-head)
for the details and the reasons:

- **File-local.** An alias is usable only within the file that declares
  it (and only within its declaring namespace). Cross-file / importable
  aliases are not supported yet.
- **Single-head bodies.** The body must be a single class or generic type
  (`Dict<A, B>`, `Ident`, `Bag<int>`). A union, intersection, nullable, or
  closure-signature body (`int|string`, `?Box`, `Closure(int): int`) is
  rejected with `xphp.alias_unsupported_body` — use a bare type or a named
  class.
- **Same-file collision detection.** An alias colliding with a class
  declared in *another* file is not detected.

## See also

- Test fixture: `test/fixture/compile/type_aliases/`
- Related: [classes and interfaces](classes-and-interfaces.md),
  [turbofish](turbofish.md)
