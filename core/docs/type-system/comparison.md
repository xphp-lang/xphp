# Type-system comparison

Baseline: `xphp` currently has generic classes / interfaces / traits, free
generic functions and method-scoped generic functions (static-call sites only),
single upper bounds validated at compile time, nested generics with fixed-point
specialization, and a marker-interface trick for `instanceof`.

The compilation model is **monomorphization** -- same as Rust, different from
Kotlin and Typescript. That last point matters a lot for what's easy vs hard to
implement.

## Overview

| Feature                   | xphp                      | TS              | Kotlin      | Rust                |
|---------------------------|---------------------------|-----------------|-------------|---------------------|
| Generic classes/ifaces    | ✅                        | ✅              | ✅          | ✅                  |
| Generic functions/methods | ✅ (static-call only)     | ✅              | ✅          | ✅                  |
| Upper bounds              | ✅ (single)               | ✅              | ✅          | ✅                  |
| Multiple bounds           | ❌                        | ✅              | ✅          | ✅                  |
| Default type params       | ❌                        | ✅              | ✅          | ✅                  |
| Declaration-site variance | ❌                        | ✅              | ✅          | (via PhantomData)   |
| Use-site variance         | ❌                        | ❌              | ✅          | n/a                 |
| Reified at runtime        | ⚠️ (AOT, partial)          | ❌              | ✅ (inline) | ✅ (monomorphic)    |
| Generic type aliases      | ❌                        | ✅              | ✅          | ✅                  |
| F-bounded recursion       | ❌ (bound is bare string) | ✅              | ✅          | ✅                  |
| Wildcard / `*`            | ❌                        | ✅ (`unknown`)  | ✅          | n/a                 |
| Variadic generics         | ❌                        | ✅              | ❌          | ⚠️ tuples            |
| Generic enums / sums      | ❌                        | ✅              | ✅          | ✅                  |
| Per-arg specialization    | ❌                        | ❌              | ❌          | ⚠️ nightly           |
| Associated types          | ❌                        | ❌              | ❌          | ✅                  |
| `instanceof OriginalFqn`  | ✅ (via marker interface) | n/a             | n/a         | n/a                 |

p.s. `n/a` meaning it doesn't fit the language's design.

## Tier 1 gaps

These are obvious gaps with clear value.

### 1. Variance annotations

TypeScript (`in` / `out`), Kotlin (`in` / `out`), Rust (implicit via lifetimes
and `PhantomData`).

```php
// covariant
class Producer<out T> {
    public function get(): T { /*... */ }
}   

// contravariant
class Consumer<in T>  {
    public function set(T $x) { /* ... */ }
}  
```

Currently, every `Box<Banana>` and `Box<Fruit>` is unrelated even when
`Banana extends Fruit`.

With marker interfaces the only commonality is the erased `Box`.

Adding `out T` would let the compiler emit
`Box_<Banana> implements Box_<Fruit>` when `Banana <: Fruit` -- a real subtype
relationship at the specialized FQN level.

Monomorphization already produces per-specialization classes; wiring up the
right `implements` chains is mostly a hierarchy lookup at specialization time.

### 2. Default type parameters

TypeScript, Kotlin and Rust support it.

```php
class Cache<K = string, V = mixed> { /* ... */ }

/* @var Cache<string, mixed> $default */
$default = new Cache();

/* @var Cache<string, mixed> $userCache */
$userCache = new Cache<int, User>();
```

Trivial to add: the scanner already parses param entries; just allow `= TypeRef`
after the (optional) bound. At call sites with fewer args than params, fill in
defaults.

### 3. Multiple bounds

- TypeScript: `T extends A & B`
- Kotlin: `where T : A, T : B`
- Rust: `T: A + B`

```php
class Sortable<T: \Stringable + \Countable> { /* ... */ }
```

Bound validation (`Registry::checkBounds`) already loops per param at both the
class-instantiation and method-call sites; trivially extends to loop per
(param, bound) pair. The parser is the only real change.

### 4. Instance-method generic calls

Currently: only `Util::method<T>(...)` (static call on a non-generic enclosing
class) is supported. `$obj->method<T>(...)` requires knowing the static type of
`$obj` to pick the receiver class. With strict typing on parameters and
properties, the static type is usually known at the call site; the unsolved part
is the dispatch table when `$obj` is a union / intersection / interface.

Tracked in `core/roadmap.md` under "Next". The LSP already substitutes
type-args at instance call sites for hover / signature help / inlay hints --
that's display-only inference; the compiler-level lowering for runtime dispatch
is the gap.

### 5. Reified type parameters

A headline Kotlin feature. Rust gets the same effect "for free" via
monomorphization -- the type IS known at codegen time.

```php
function decode<T>(string $json): T {
    $data = json_decode($json, true);
    return new T(...$data);   // T is concrete in the specialized body
}
```

Currently, the `Specializer` already substitutes `new T()` (the `New_` class
field is a `Name`), so this works **incidentally**. The gap is that user
code can't write `if ($x instanceof T)` and reason about it as a documented
contract. Worth promoting from "accidentally works" to "documented capability"
with `T::class`, `instanceof T`, and `is_a($x, T::class)` all guaranteed.

### 6. Generic type aliases

- TypeScript: `type Result<T, E> = ...`
- Rust: `type Result<T> = ...`
- Kotlin: `typealias`

```php
type Result<T> = Success<T> | Failure;       // <- also requires union types
type Pair<A, B> = array{first: A, second: B};
```

Currently. `xphp` doesn't have type aliases at all. Adding generic ones is
essentially a substitution at parse time -- much simpler than introducing
nominal types. Pairs well with PHP 8.0+ union types.


## Tier 2 gaps

These are useful but more invasive.

### 7. F-bounded polymorphism (recursive bounds)

TypeScript, Kotlin, Rust all have it.

```php
class Sorter<T: Comparable<T>> { /* ... */ } // T must be comparable to itself
```

Current bound parser stores `boundFqn` as a bare string. Would need to
extend to `TypeRef` so the bound can itself be generic.

### 8. Lower bounds / contravariant constraints

Kotlin (`<T: in Animal>`), Java (`<? super Cat>`).

Less common than upper bounds, but useful for callback / consumer
contracts.

### 9. Star projection / wildcard

Kotlin `Box<*>`, Java `Box<?>`. Semantically: "any `Box`, no constraint on
`T`."

The marker interface already provides something equivalent at runtime
(`instanceof Box`). Promoting this to explicit type-hint syntax (e.g.
`Box<*>` resolving to the marker) would close the type-position gap.

### 10. Variadic type parameters

TypeScript (variadic tuples), Rust (tuples), Scala.

```php
function pipe<...Ts, R>(callable ...$fs): Closure { /* ... */ }
type Tuple<...Ts> = array{...Ts};
```

Larger implementation effort, but unlocks correctly-typed `array_map`,
`pipe`, builder chains.

### 11. Specialization for specific arg types

Rust nightly's `impl<T> Foo` + `impl Foo for i32`. Monomorphization makes
this conceptually natural -- the compiler could emit a different body
when `T = int`.

```php
class Container<T> {
    public function dump(): string { return serialize($this->items); }

    // Specialization: when T is int, use a faster path.
    @specialize(T = int)
    public function dump(): string { return implode(',', $this->items); }
}
```

`xphp` pipeline could route the call to whichever specialization wins.
Novel for PHP -- Rust is the only mainstream language doing it well.

### 12. Generic enums / sum types

- Rust: `enum Option<T>`
- Kotlin: `sealed` classes
- TypeScript: discriminated unions

```php
enum Option<T> {
    case Some(T); // <- requires enum data variants
    case None;
}
```

Currently, `xphp` enums don't carry per-case data. This is a larger language 
feature, but generic + sum types together unlock pattern matching, exhaustive
`match`, and monadic chaining -- meaningful DX gains for the ecosystem.

### 13. Trait composition with generic type-params

Currently, `trait HasItem<T>` is dropped after specialization (no marker).

Specializing the trait's body when it's used by a generic class would require
trait-substitution at the AST level. Doable but invasive -- and conflicts with
PHP's "traits get inlined at compile time" semantic.

### 14. Self-type / `static` interacting with generics

PHP already has `static` as a self-type. Combining with generics:

```php
class Builder<T> {
    // returns the specialized child type
    public function set(T $x): static { /* ... */ }
}
```

The `static` part should "just work" in specializations -- worth a fixture
to lock it.

## Tier 3 Gaps

These are features supported by other languages, but may not be necessary
for `xphp`

### Conditional types

`T extends U ? X : Y` -- type-level branching.

Needs a real type-level evaluator. Typescript's domain.
 
### Mapped types

`{ [K in keyof T]: ... }` -- same; needs `keyof` and type-level iteration.

### Higher-kinded types

`M<_>` as a parameter -- even Rust doesn't have this.

### Const generics

`Array<T, N: int>` -- generic over a value. Rust's domain.

### Negative bounds

`T: !Send` -- Rust nightly, niche.

### infer keyword

Typescript conditional-type pattern extraction. Pairs with mapped & conditional
types above.

## Next opportunities

### Default type params + multiple bounds

Both are mostly parser changes, low risk, high quality-of-life.

### Variance annotations

`in` / `out` keywords. Leverages the monomorphization model uniquely. Emit the
right `implements` chains between specializations.

### Generic type aliases

Unlocks reuse, and since `xphp` doesn't have nominal types yet the design is
unconstrained.

### Reified-T as a documented contract

- `T::class`
- `instanceof T`
- `is_a($x, T::class)`

`xphp` already pays for monomorphization, this is the user-facing payoff over
Java /Kotlin.

### Instance-method generic calls

Largest single uplift for day-to-day call-site ergonomics.
