# Generics

This document covers how `xphp` implements generics: what the compiler emits,
what's supported today, the naming scheme for generated classes, and the
trade-offs the monomorphization model accepts.

For the broader project context, see the [README](../../../README.md).

---

## Why monomorphization, not erasure

`xphp` specializes generics at compile time -- every `Box<Plastic>` expands to
a distinct, fully-typed class file with `Plastic` baked into every signature.

The Zend Engine then sees plain `php` classes and runs them without any awareness
that `xphp` existed.

The consequence: `instanceof T`, `T::class`, `is_a($x, T::class)` all work at
runtime because `T` resolves to the concrete type at specialization time.

For comparison, Rust does also apply monomorphization. Kotlin and TypeScript
don't do that, and there they have no runtime safety.

Variance annotations based on monomorphization become real subtype edges between
specialized classes -- that's in the [roadmap](../../roadmap.md).

---

## How it works

The `xphp compile` command goes through the following phases

### 1. Parse + collect

- Each `.xphp` file is tokenized via PHP's own `token_get_all`-equivalent.
- A custom scanner walks the token stream with depth tracking, picks out
  `class X<...>` and `Name<...>` clauses (handles arbitrarily nested brackets),
  strips them to plain PHP, hands the cleaned source to `nikic/php-parser`.
- Generic metadata is reattached to AST nodes as attributes.

### 2. Fixed-point specialization

For every concrete instantiation in the registry, clone the template AST and
substitute every `T` reference with the concrete type.

The substitution can introduce new instantiations. For example a
`Wrapper<Plastic>` body referencing `Box<T>` -> register `Box<Plastic>`

Then it will loop until no new entries appear, bail at depth 16.

### 3. Rewrite

Each `Name` node carrying generic args is replaced with a `FullyQualified`
reference to the specialized class. Generic class definitions are stripped from
the target output.

### 4. Emit

- specialized classes go to
  `<cache>/Generated/<template-FQCN-as-path>/T_<hash>.php`
- rewritten user files go to the `<target>` directory, preserving the source's
  PSR-4 layout.

---

## Runtime behavior

Reflection reports the real type and `TypeError` is fired when the specialized
class is used incorrectly:

```php
// ok
$box = new \XPHP\Generated\App\Model\Box\T_0364b272c7219329(new Food());
(new ReflectionProperty($box::class, 'item'))->getType()->getName();
// that prints "Food", the actual class
// not "mixed", not "T"

// error
$box = new \XPHP\Generated\App\Model\Box\T_0364b272c7219329(new Drink());
// TypeError: Argument #1 ($item) must be of type Food, Drink given
```

---

## What works

### Generic classes, interfaces, and traits

All three `ClassLike` shapes are templates. `interface Container<T>` and
`trait HasItem<T>` flow through the same specialization pipeline as
`class Box<T>`.
The only difference at emit time: traits are stripped (PHP can't `instanceof`
a trait), while classes and interfaces both leave behind an empty marker --
see "
`instanceof` on the original template" below.

### Type parameters

Any arity (`Box<T>`, `List<Box<T>>`, `Map<K, V>`, ...). Scalars and class names
mix freely.

### Nesting

`Box<List<Plastic>>` at arbitrary depth. Each level produces its own
specialization (`List_<...>` and `Box_<List_<...>>`).

### All type-hint positions

Properties, parameters, return types, `new` expressions. Property
`public Box<T> $b;` and return `function f(): Box<T>` both work.

`?T` (and any other nullable type-hint of a generic param) is preserved across
specialization: `function first(): ?T` on a `Collection<User>` instance becomes
`function first(): ?User`. Reflection reports the concrete class and
`allowsNull() === true`.

### `T[]` array-type sugar

The native PHP type system can't express "array of T" -- only the
unparameterized
`array` -- so `xphp` lowers the documentation-friendly `T[]` (and `Name[]` for
any class name, plus chained `T[][]`) directly to `array` during the compile
step.

```php
class Collection<T> {
    private T[] $items;                  // -> private array $items;
    public function set(T[] $items): void; // -> public function set(array $items): void;
    public function all(): T[];          // -> public function all(): array;
}
```

The element-type enforcement still happens wherever the type parameter appears
in a position PHP can enforce -- typically the variadic `T ...$items`
constructor
parameter, or any single-element `T $x` setter.

### Transitive specialization

if `class Wrapper<T> { public Box<T> $b; }` is instantiated as
`Wrapper<Plastic>`, the compiler discovers and specializes `Box<Plastic>` too,
via a fixed-point loop with a 16-iteration depth cap to abort recursive types.

### Type-parameter bounds

`class Box<T: \Stringable> { ... }` -- each concrete arg must satisfy the
bound (extend / implement / equal it). The compiler validates bounds at
instantiation-record time, _before_ the FQCN is hashed, so error messages
reference the source-level `Box<int>`, not the obfuscated `T_<hash>`. The
hierarchy is built from every parsed source file plus a small whitelist of PHP
built-in interfaces (`Stringable`, `Countable`, `Iterator`, `ArrayAccess`,
`JsonSerializable`, `Throwable`, etc.). Concrete types the hierarchy can't
reason about (not in the source set, not a built-in) fail with a distinct
"compiler cannot prove satisfaction" message so users can either widen the bound
or include the type in the source set.

### Method-scoped generics

`function NAME<T>(...)` declared inside a class body. Each unique call-site arg
list mints one mangled specialization (`NAME_T_<hash>`) appended to the same
class; call sites rewrite to the mangled name. Specializations are deduped --
two
`identity<int>` calls share one method body.

```php
class Util {
    public static function identity<T>(T $x): T { return $x; }
}

Util::identity<int>(42);    // -> Util::identity_T_<hash-of-int>(42)
Util::identity<string>('hi'); // -> Util::identity_T_<hash-of-string>('hi')
```

MVP limits: static-call sites only (`Util::method<…>`); method must be on a
non-generic enclosing class. Bound checks on method-level type-params are
enforced at call time (same `Registry::checkBounds` machinery as class-level
bounds).

### Free generic functions

Same shape as method generics but at namespace scope. `function foo<T>(...)`
becomes one mangled function per unique arg-list, appended to the enclosing
namespace; call sites rewrite to fully-qualified mangled refs.

MVP limit: the function must live inside a `namespace { ... }` block; bare
top-level functions aren't supported yet.

### `instanceof` on the original template

For every generic class or interface template, the compiler emits an **empty
marker interface** at the original FQN,
and every specialization `implements` (or `extends`, for interfaces) it. So
`$x instanceof App\Containers\Box` returns
`true` for any `Box<…>` specialization, no concrete arg list required.

```php
$x = new Box<Plastic>();
$y = new Box<Metal>();
$x instanceof App\Containers\Box; // true
$y instanceof App\Containers\Box; // true
```

Generic traits get dropped entirely -- PHP can't `instanceof` a trait, so a
marker would be useless.

### Collision-safe naming

The compiler generates specialized classes using a hashed naming scheme:
`T_<hash>`.

The `<hash>` is a `SHA-256` digest of the canonical argument list, truncated to
`XPHP_HASH_LENGTH` (default `64` chars).

The canonical form joins the arguments with `|`, recursively encoding generic
arguments as `Name<Inner,...>`.

The resulting `FQCN` looks like this:
`\XPHP\Generated\<original-template-FQCN>\T_<hash>`

Examples:

| Source instantiation                                          | Canonical hash input                     | Generated `FQCN`                                |
|---------------------------------------------------------------|------------------------------------------|-------------------------------------------------|
| `App\Containers\Box<App\Models\Plastic>`                      | `App\Models\Plastic`                     | `\XPHP\Generated\App\Containers\Box\T_<hash1>`  |
| `App\Containers\List<App\Containers\Box<App\Models\Plastic>>` | `App\Containers\Box<App\Models\Plastic>` | `\XPHP\Generated\App\Containers\List\T_<hash2>` |

### Hash length configurable

Configured via `XPHP_HASH_LENGTH` env var (an int value between `16` and `64`,
default `64`). `16` hex chars is `64`
bits -- birthday collisions impossible at any practical scale; `64` is the full
`SHA256` digest.

The namespace mirrors the template's original `FQCN`, so two `Box` classes in
different packages cannot collide
regardless
of how their args are spelled. Hash-collision detection at recording time will
fail loudly with both colliding
instantiations, the current hash length, and a re-run command using a longer
hash.

### Why monomorphization (and what it costs)

One class file per unique instantiation. A codebase instantiating `Map<K, V>`
with `50` distinct `(K, V)` combinations produces `50` generated files. That's
the cost.

In exchange:

- **Zero runtime overhead.** OpCache compiles each specialized class once;
  subsequent instantiations are normal `new` calls.
- **Honest reflection.** `ReflectionParameter::getType()->getName()` returns the
  real concrete type -- what DI containers and serializers actually need.
- **Native `TypeError` enforcement** at every boundary, without writing one line
  of reflection-aware glue.

Type erasure (the phpdoc / attribute path) generates fewer files at the price of
re-introducing the exact problem `xphp` exists to solve. The trade is
intentional.

---

## Type-system comparison

See a [side-by-side comparison](../comparison.md) against TypeScript, Kotlin,
and Rust -- including the features `xphp` doesn't have yet.
