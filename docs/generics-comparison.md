# Generic features xphp doesn't have yet

Baseline: xphp today ships generic classes / interfaces / traits (any arity, arbitrarily nested), free + method-scoped generic functions (static-call only), single upper bounds enforced at the class **and** method/free-function level with source-level error messages, transitive fixed-point specialization, collision-safe FQCN naming, and a marker-interface trick that makes `instanceof OriginalTemplate` work for every specialization. The compilation model is **monomorphization** — same as Rust, opposite of Java/Kotlin's erasure. That last point matters a lot for what's easy vs hard to add.

Below: a quick cross-language reference, then the gaps grouped by tier with the language(s) that have each feature. Filtered for things that make sense in a PHP-targeted language — skipping Rust lifetimes, const generics over `usize`, TS template-literal types, etc.

---

## Cross-language summary

| Feature | TS | Kotlin | Rust | xphp |
|---|---|---|---|---|
| Generic classes/ifaces | ✅ | ✅ | ✅ | ✅ |
| Generic functions/methods | ✅ | ✅ | ✅ | ✅ method-scope = static-call only; free functions full |
| Upper bounds (class + method + free-function level) | ✅ | ✅ | ✅ | ✅ (single, enforced everywhere) |
| Multiple bounds | ✅ | ✅ | ✅ | ❌ |
| Default type params | ✅ | ✅ | ✅ | ❌ |
| Declaration-site variance | ✅ | ✅ | (via PhantomData) | ❌ |
| Use-site variance | ❌ | ✅ | n/a | ❌ |
| Reified via marker interface (`instanceof OriginalFqn`) | n/a | n/a | n/a | ✅ |
| Reified placeholder in generic body (`instanceof T`, `T::class`, `new T(...)`, `T::method(...)`, `is_a($x, T::class)`) | ❌ | ✅ (inline) | ✅ (monomorphic) | ✅ |
| Generic type aliases | ✅ | ✅ | ✅ | ❌ |
| F-bounded recursion | ✅ | ✅ | ✅ | ❌ (bound is bare string) |
| Wildcard / `*` | ✅ (`unknown`) | ✅ | n/a | ⚠ via marker |
| Variadic generics | ✅ | ❌ | ⚠ tuples | ❌ |
| Generic enums / sums | ✅ | ✅ | ✅ | ❌ |
| Per-arg specialization | ❌ | ❌ | ⚠ nightly | ❌ |
| Associated types | ❌ | ❌ | ✅ | ❌ |

---

## Tier 1 — Obvious gaps with clear value

### 1. Variance annotations
TypeScript (`in`/`out`), Kotlin (`in`/`out`), Rust (implicit via lifetimes & PhantomData).

```php
class Producer<out T> { public function get(): T; }   // covariant
class Consumer<in T>  { public function set(T $x); }  // contravariant
```

Today every `Box<Dog>` and `Box<Animal>` are unrelated even when `Dog extends Animal`. With marker interfaces, the only commonality is the erased `Box`. Adding `out T` would let the compiler emit `Box_<Dog> implements Box_<Animal>` whenever `Dog <: Animal` — a real subtype relationship at the specialized FQN level.

**Why high-value**: monomorphization already produces per-specialization classes; wiring up the right `implements` chains is mostly a hierarchy lookup at specialization time.

### 2. Default type parameters
TypeScript, Kotlin, Rust.

```php
class Cache<K = string, V = mixed> { ... }
$c = new Cache();        // Cache<string, mixed>
$c = new Cache<int, User>();
```

Trivial to add: scanner already parses param entries; just allow `= TypeRef` after the (optional) bound. At call-sites with fewer args than params, fill in defaults.

### 3. Multiple bounds
TypeScript (`T extends A & B`), Kotlin (`where T : A, T : B`), Rust (`T: A + B`).

```php
class Sortable<T: \Stringable + \Countable> { ... }
// or with a where clause
class Sortable<T> where T: \Stringable, T: \Countable { ... }
```

Bound validation (`Registry::validateBounds`) already loops per param; trivially extends to loop per (param, bound) pair. Parser is the only real change.

### 4. Instance-method generic calls
Method-scoped generics ship today for static call sites (`Util::identity<int>(...)`); the gap is instance calls (`$obj->method<int>(...)`), which require knowing the static type of `$obj`. Without inference, the workaround in monomorphized worlds is **declaration-site annotation**: if `$obj` is typed as `Util` in the signature, the compiler knows the receiver class. With strict typing, this covers the majority of practical cases.

### 5. Generic type aliases
TypeScript (`type Result<T,E> = ...`), Rust (`type Result<T> = ...`), Kotlin (`typealias`).

```php
type Result<T> = Success<T> | Failure;   // ← also requires union types
type Pair<A, B> = array{first: A, second: B};
```

xphp doesn't have type aliases AT ALL today. Adding generic ones is essentially a substitution at parse time — much simpler than introducing nominal types. Pairs well with PHP 8.0+ union types.

---

## Tier 2 — Useful, more invasive

### 7. F-bounded polymorphism (recursive bounds)
TS/Kotlin/Rust all have it.

```php
class Sorter<T: Comparable<T>> { ... }   // T must be comparable to itself
```

Current bound parser stores `boundFqn` as a bare string. Would need to extend to `TypeRef` so the bound can itself be generic.

### 8. Lower bounds / contravariant constraints
Kotlin (`<T: in Animal>`), Java (`<? super Cat>`).

Less common than upper bounds, but useful for callback/consumer contracts.

### 9. Star projection / wildcard
Kotlin `Box<*>`, Java `Box<?>`. Semantically: "any `Box`, no constraint on `T`."

The marker interface already provides something equivalent at runtime (`instanceof Box`). Promoting this to explicit type-hint syntax (e.g. `Box<*>` resolving to the marker) would close the type-position gap.

### 10. Variadic type parameters
TypeScript (variadic tuples), Rust (tuples), Scala.

```php
function pipe<...Ts, R>(callable ...$fs): Closure { ... }
type Tuple<...Ts> = array{...Ts};
```

Larger implementation effort, but unlocks correctly-typed `array_map`, `pipe`, builder chains.

### 11. Specialization for specific arg types
Rust nightly's `impl<T> Foo` + `impl Foo for i32`. Monomorphization makes this conceptually natural — the compiler could emit a different body when `T = int`.

```php
class Container<T> {
    public function dump(): string { return serialize($this->items); }

    // Specialization: when T is int, use a faster path.
    @specialize(T = int)
    public function dump(): string { return implode(',', $this->items); }
}
```

xphp's pipeline could route the call to whichever specialization wins. This is novel for PHP — Rust is the only mainstream language doing it well.

### 12. Generic enums / sum types
Rust (`enum Option<T>`), Kotlin (sealed classes), TS (discriminated unions).

```php
enum Option<T> {
    case Some(T);    // ← also requires PHP enum data variants
    case None;
}
```

PHP enums today don't carry per-case data. This is a larger language feature, but generic + sum types together unlock pattern matching, exhaustive `match`, and monadic chaining — meaningful DX gains for the ecosystem.

### 13. Trait composition with generic type-params
Today `trait HasItem<T>` is dropped after specialization (no marker). Specializing the trait's body when it's `use`'d by a generic class would require trait-substitution at the AST level. Doable but invasive — and conflicts with PHP's "traits get inlined at compile time" semantic.

### 14. Self-type / `static` interacting with generics
PHP already has `static` as a self-type. Combining with generics:

```php
class Builder<T> {
    public function set(T $x): static { ... }   // returns the specialized child type
}
```

The `static` part should "just work" in specializations — worth a fixture to lock it.

---

## Tier 3 — TS/Rust have it, but probably skip for PHP

- **Conditional types** (`T extends U ? X : Y`) — type-level branching. Needs a real type-level evaluator. TS's domain.
- **Mapped types** (`{ [K in keyof T]: ... }`) — same, needs `keyof` and type-level iteration.
- **Higher-kinded types** (`M<_>` as a parameter) — even Rust doesn't have this.
- **Const generics** (`Array<T, N: int>`) — generic over a value. Rust's domain.
- **Lifetime parameters** — N/A for PHP.
- **Higher-ranked trait bounds** (`for<'a> Fn(...)`) — lifetime-coupled, N/A.
- **Negative bounds** (`T: !Send`) — Rust nightly, niche.
- **`infer` keyword** — TS conditional-type pattern extraction. Pairs with mapped/conditional, skip with them.

---

## Next opportunities

Aligned with `roadmap.md`'s **Next** horizon (Type system depth + Generic surface). In rough priority order:

1. **Default type params + multiple bounds** — both are mostly parser changes, low risk, high quality-of-life.
2. **Variance annotations (`in`/`out`)** — leverages the monomorphization model uniquely; emit the right `implements` chains between specializations.
3. **F-bounded recursion** (`T: Comparable<T>`) — promote `boundFqn` from bare string to `TypeRef` so the bound can itself be generic.
4. **Instance-method generic calls** on a typed receiver — declaration-site annotation gives the compiler the receiver class without inference.
5. **Generic type aliases** — unlocks reuse, and since xphp doesn't have nominal types yet the design is unconstrained.

The combination unique to xphp is **(2) plus the already-shipped reified-T (Tier 1 #5)**: PHP would become the only mainstream PHP-shaped language with Rust-style reified-and-monomorphized generics _plus_ variance — a differentiator the community can point to.
