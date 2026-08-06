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
  **union** (`int|string`), a **nullable** (`?Box`), an **intersection**
  (`A & B`), or a **DNF** — a union of intersections (`(A & B) | C`). A
  single-head or generic body expands in **every** type position, including
  as a generic argument (`Bag<UserId>`), `new`, `extends`, and a bound. A
  **compound** body (union / nullable / intersection / DNF) expands only as
  the *whole* type of a parameter, property, return, or class-constant slot
  (see caveats) — except as a **bound**, where an intersection is all-of
  (`type B<T : A & Named>`) and a union is any-of.
- **Parameters** may carry **defaults** and **bounds**, like a generic
  class: `type P<A, B = A> = Dict<A, B>;` (a use may omit trailing
  defaulted arguments — `P<int>` fills `B = A = int`), and
  `type B<T : Named> = Bag<T>;` (a use whose argument does not satisfy the
  bound is a compile error, the same `xphp.bound_violation` a class
  instantiation raises). A bound may itself name an alias — `type Named =
  Face; type B<T : Named>` checks against `Face`.
- **File-local**: an alias is visible only in the file that declares it,
  like a PHP `use` alias. To share a vocabulary, declare the alias in each
  file that uses it (a zero-cost substitution), or reference the underlying
  type directly (see caveats).
- Aliases compose: an alias body may reference another alias
  (`type UserMap = Pair<int, User>`), and an alias may take type
  parameters used inside its body (`type Pair<A, B> = Dict<A, Bag<B>>`).
- A non-alias name of the same shape is untouched — only a declared alias
  is expanded.
- The following are compile errors (each with a stable code, reported by
  both `xphp compile` and `xphp check`):
  - `xphp.alias_cycle` — an alias defined, directly or transitively, in
    terms of itself (`type A = B; type B = A;`).
  - `xphp.alias_arity` — a use whose type-argument count is outside the
    alias's accepted range (`type P<A, B> = …;` used as `P<int>`; with a
    default the range widens — `type P<A, B = A>` accepts one or two).
  - `xphp.bound_violation` — a use whose argument does not satisfy a
    parameter's bound (`type B<T : Named> = …;` used as `B<int>`).
  - `xphp.alias_class_collision` — an alias whose name collides with a
    class, interface, or trait of the same name (no silent shadowing).
  - `xphp.alias_duplicate` — the same alias name declared twice.
  - `xphp.alias_unsupported_body` — a closure-signature body (see caveats
    below).
  - `xphp.alias_compound_in_non_slot` — a compound alias (union / nullable /
    intersection / DNF) used outside a whole slot (see caveats below).
  - `xphp.alias_compound_needs_distribution` — a union nested inside an
    intersection (`(A|B)&C`), which would require distribution; rewrite it in
    DNF (`(A&C)|(B&C)`).
  - `xphp.alias_scalar_in_intersection` — a scalar or built-in member in an
    intersection (`int & A`), which PHP forbids.

## Caveats

An alias is **file-local by design** (like a `use` alias). The remaining
limits are the body shape and the positions a compound alias can take. See
[caveats → type-alias body and position limits](../caveats.md#type-alias-body-and-position-limits)
for the details and the reasons:

- **File-local.** An alias is visible only in its own file — declare it in
  each file that uses it, or reference the underlying type directly. (Because
  scoping is per-file there is no cross-file collision/duplicate to detect;
  same-file ones *are* caught.)
- **Closure bodies** (`Closure(int): int`) are rejected with
  `xphp.alias_unsupported_body` — write the type directly or wrap it in a
  named class/interface.
- **No distribution.** A union nested inside an intersection (`(A|B)&C`, or an
  intersection member that expands to a union) is
  `xphp.alias_compound_needs_distribution` — rewrite it in DNF. A scalar in an
  intersection (`int & A`) is `xphp.alias_scalar_in_intersection`.
- **A compound alias is a whole-slot type only.** A union / intersection /
  nullable / DNF alias as a generic argument, in `new` / `extends`, or nested
  inside another compound is `xphp.alias_compound_in_non_slot`. (As a *bound*
  it does expand — an intersection all-of, a union any-of.)

## See also

- Test fixture: `test/fixture/compile/type_aliases/`
- Related: [classes and interfaces](classes-and-interfaces.md),
  [turbofish](turbofish.md)
