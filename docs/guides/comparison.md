# Comparison: xphp vs RFC / TypeScript / Kotlin / Rust

xphp uses **monomorphization** -- same as Rust, different from
Kotlin, TypeScript, and the PHP RFC (all three erase the type
parameter at runtime). That single choice ripples through every
other trade-off below: features like reified T and real subtype
edges between specializations come almost for free; features like
full existential wildcards or type-erasure-tolerant runtime
reflection are genuinely harder.

If you're new to the model, the short version: every distinct
`(template, args)` pair becomes its own real class at compile time.
`Box<int>` and `Box<string>` are unrelated classes that happen to
share a marker interface. Read [how it works](how-it-works.md) for the
end-to-end walk.

## Feature grid

The `RFC` column refers to
[PHP RFC: bound-erased generic types](https://wiki.php.net/rfc/bound_erased_generic_types)
— the surface xphp tracks. xphp's syntax stays compatible with the
RFC; runtime semantics diverge where monomorphization gives you more
than erasure can.

| Feature                                  | xphp                    | RFC              | TS               | Kotlin        | Rust                  |
|------------------------------------------|-------------------------|------------------|------------------|---------------|-----------------------|
| Generic classes / interfaces / traits    | ✅                      | ✅               | ✅               | ✅            | ✅                    |
| Generic functions / methods              | ✅                      | ✅               | ✅               | ✅            | ✅                    |
| Generic closures + arrow functions       | ✅                      | ✅               | ✅               | ✅            | ✅                    |
| Typed closure signatures (`Closure(int): bool`) | ✅ (erases to `\Closure`; literal conformance checked at compile time) | ✅ (runtime-lenient) | ✅ (function types) | ✅ (`(Int) -> Bool`) | ✅ (`Fn(i32) -> bool`) |
| Upper bounds                             | ✅                      | ✅               | ✅               | ✅            | ✅                    |
| Multiple bounds (intersection)           | ✅                      | ✅               | ✅               | ✅            | ✅                    |
| Union bounds + DNF                       | ✅                      | ✅               | ✅               | ❌ (intersection only via `where`) | n/a |
| F-bounded recursion (`T : Box<T>`)       | ✅                      | ✅               | ✅               | ✅            | ✅                    |
| Default type parameters                  | ✅                      | ✅               | ✅               | ✅            | ✅                    |
| Declaration-site variance (`out T` / `in T`)  | ✅                      | ✅               | ✅               | ✅            | ⚠️ inferred (lifetime-driven; PhantomData for unused type params) |
| Inner-template variance composition      | ✅                      | ✅               | ✅               | ✅            | ✅                    |
| Reified T at runtime                     | ✅ (via AOT)            | ❌ (erased)      | ❌               | ✅ (inline)   | ✅ (monomorphic)      |
| `instanceof OriginalFqn` works           | ✅                      | ✅ (trivially: only one class exists at runtime) | n/a | n/a | n/a |
| Real subtype edges between specializations | ✅                    | ❌ (erased)      | n/a              | n/a           | n/a                   |
| Generic type aliases                     | ❌                      | ❌               | ✅               | ✅            | ✅                    |
| Wildcard / `*` (use-site existential)    | ⚠️ partial (via marker) | n/a (erased)     | ⚠️ via `any` (bivariant escape hatch — loses type discipline) | ✅ (`Box<*>`) | n/a |
| Use-site variance                        | ❌                      | ❌               | ❌               | ✅            | n/a                   |
| Variadic generics                        | ❌                      | ❌               | ✅               | ❌            | ⚠️ tuples              |
| Generic enums / sum types                | ❌                      | ❌               | ✅               | ✅            | ✅                    |
| Per-arg specialization                   | ❌                      | ❌ (erasure)     | ❌               | ❌            | ⚠️ nightly             |
| Associated types                         | ❌                      | n/a              | ❌               | ❌            | ✅                    |
| `T[]` array sugar                        | ✅                      | ❌               | ✅               | ❌            | ❌                    |

`n/a` means the feature doesn't fit the language's design — there's
no comparable concept to align to. For the RFC, the `(erased)` notes
mark features that simply can't exist under bound erasure: there are
no specialized classes at runtime, so subtype edges, reified-T
operations, and a wildcard sigil all lose their meaning.

## Where the monomorphic and erasure paths diverge

Three features fall out of the monomorphization model that the
bound-erasure model trades away for its own benefits (single runtime
class per template, no transitive class explosion, simpler ABI
surface). Neither path is universally better; the trade-off lands
differently depending on what you optimize for.

### Reified T

```php
function decode<T>(string $json): T {
    $data = json_decode($json, true);
    return new T(...$data);   // T resolves to the concrete class at compile time
}

$user = decode::<User>($payload);
```

Inside the specialized body, `T` literally becomes `User`. `instanceof T`,
`T::class`, and `new T(...)` all work because there's no `T` at
runtime — just the concrete class name substituted in.

> ⚠️ Code relying on reified T won't port directly to a future
> RFC-aligned runtime. See [caveats](../caveats.md#reified-t-is-an-xphp-specific-divergence).

### Real subtype edges between specializations

```php
class Producer<out T> {
    public function get(): T { /* ... */ }
}
```

With `Banana extends Fruit`, xphp emits the specialized class
`Producer<Banana>` as a real `extends Producer<Fruit>`. PHP's native
LSP carries the relationship: a function expecting
`Producer<Fruit>` accepts a `Producer<Banana>` argument without any
runtime check.

Erasure-based runtimes can't do this because their specializations
don't exist as distinct classes.

### `instanceof OriginalFqn` works

Every generic template emits a marker interface at the original FQN.
`$x instanceof App\Box` returns true for `Box<int>`, `Box<string>`,
and any other specialization, even though they're physically
unrelated classes. You get the "polymorphic over T" mental model
without losing instance checks.

## What's missing today

The features marked ❌ in the grid above are conscious deferrals, not
oversights. Each one has a sketch of intent below.

### Generic type aliases

```php
type Result<T, E> = Success<T> | Failure<E>;
type Pair<A, B> = array{first: A, second: B};
```

Substitution at parse time; no new nominal types. Composes naturally
with PHP's existing union types. On the roadmap as a [Generic surface
item](../roadmap.md).

### Wildcard / `*` (use-site existential)

```php
function inspect(Box $b): void;     // any Box, regardless of T
```

xphp has a wildcard-*shaped* type-hint position: the bare template
name without `<>` accepts any `Box<X>` because every specialization
implements the marker interface emitted at the original FQN.

The closest analogues are Java's `Box<?>` and Kotlin's `Box<*>` —
both are use-site existential types where the type system still
knows the upper bound for `T`, so reads return that bound. xphp's
marker is weaker: there's no runtime witness for `T`, and the marker
declares no methods. You can pass instances through and
`instanceof`-check, but you can't dispatch methods on the bare
parameter.

Closing the gap means populating the marker with upper-bound-typed
read-only methods. See
[runtime semantics](runtime-semantics.md#marker-interfaces-as-wildcard-shaped-positions)
for the runtime side, and the
[roadmap](../roadmap.md#type-system-breadth) for the work that
would lift this from partial to a real existential.

> ⚠️ TypeScript has no true use-site existential. Two near-misses:
>
> - **`Box<unknown>`** is just `T = unknown` (the top type). Whether
>   it accepts a `Box<string>` depends on `Box`'s declared variance
>   (TS 4.7+'s `out T` markers), and even then only one direction.
>   Type-safe but not a wildcard.
> - **`Box<any>`** works because `any` is bivariant — it accepts any
>   `Box<X>` regardless of declared variance. This is the practical
>   "I don't care about T" sigil, but reads come back typed `any`,
>   which propagates and disables type-checking on whatever you do
>   with the value.
>
> Neither matches Java's `Box<?>` or Kotlin's `Box<*>`, which give
> bounded reads at the upper bound while keeping type discipline.
>
> Similarly, `mixed` in xphp is not a wildcard sigil — it's a regular
> scalar type. `Box<mixed>` specializes to a distinct class where
> `T = mixed` is baked into every signature, sibling to `Box<int>`
> and `Box<string>` rather than a supertype of them.

### Use-site variance

Kotlin's `Box<out T>` / `Box<in T>` at a usage site (rather than at
the declaration). The RFC doesn't include this; we follow.

### Variadic generics

```php
function pipe<...Ts, R>(callable ...$fs): Closure;
```

Correctly-typed `array_map`, builder chains, and pipelines depend on
this. Significantly more invasive than the rest of the surface.

### Generic enums / sum types

```php
enum Option<T> {
    case Some(T);
    case None;
}
```

PHP enums don't carry per-case data today, so this needs a deeper
language change. The combination of generic + sum types unlocks
pattern matching, exhaustive `match`, and monadic chaining.

### Per-arg specialization

```php
class Container<T> {
    public function dump(): string { return serialize($this->items); }

    @specialize(T = int)
    public function dump(): string { return implode(',', $this->items); }
}
```

Rust nightly's `impl<T> Foo` + `impl Foo for i32` pattern. The
monomorphization model makes it conceptually natural — the compiler
already emits a different body per `T`; the question is what syntax
selects which body.

## Choosing xphp vs the alternatives

- **You already write PHP and want generics that PHP devs understand**:
  xphp fits without forcing a runtime change.
- **You want erasure-based runtime semantics so code is forward-portable
  to a future RFC-aligned PHP runtime**: avoid reified T (see
  [caveats](../caveats.md)). Most of the surface ports cleanly.
- **You want full type-system depth (mapped/conditional/dependent types,
  associated types, variadic generics)**: TypeScript still leads here.
  xphp's [roadmap](../roadmap.md) tracks several of these as long-term
  work, but none are short-term targets.
- **You want maximum runtime performance with zero type overhead**:
  Rust's monomorphization gives you what xphp gives you, but inside a
  compiled language with no garbage collector.
