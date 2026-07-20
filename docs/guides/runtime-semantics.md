# Runtime semantics

What happens at runtime when xphp source compiles down to vanilla PHP.
For the surface syntax, see the [syntax tour](../syntax/index.md);
for the compile-time pipeline, see [how it works](how-it-works.md).

## Why monomorphization, not erasure

xphp specializes generics at compile time. Every `Box<Plastic>`
expands to a distinct, fully-typed class file with `Plastic` baked
into every signature. The Zend Engine then sees plain PHP classes and
runs them without any awareness that xphp existed.

The consequence: `instanceof T`, `T::class`, `is_a($x, T::class)` all
work at runtime because `T` resolves to the concrete type at
specialization time.

For comparison, Rust also applies monomorphization wholesale. Kotlin
erases at runtime except for `inline fun ... <reified T>` (which
substitutes the type at each call site, functionally like
monomorphization for the inlined call only). TypeScript erases
entirely at compile time and ships no type information. The trade-off
is runtime visibility: full reified types and real subtype edges in
the monomorphic camp, smaller compiled artifacts in the erased camp.

Variance annotations on top of monomorphization become real subtype
edges between specialized classes -- see the
[variance syntax page](../syntax/variance.md) for the user-facing
form, and the [comparison](comparison.md) for the trade-off.

## Reflection sees the real types

```php
$box = new Box::<Food>(new Food());
(new ReflectionProperty($box::class, 'item'))->getType()->getName();
// "Food" -- the concrete class, not "mixed", not "T"

$box = new Box::<Food>(new Drink());
// TypeError: Argument #1 ($item) must be of type Food, Drink given
```

PHP enforces the type at every call boundary because the specialized
class declares `Food` literally. No reflection-aware glue, no
attribute wrapping -- just PHP's native type system doing its job.

## `instanceof` on the original template

For every generic class or interface template, the compiler emits an
**empty marker interface** at the original FQN, and every
specialization implements (or extends, for interfaces) it. So
`$x instanceof App\Containers\Box` returns true for any `Box<...>`
specialization, no concrete arg list required:

```php
$x = new Box::<Plastic>();
$y = new Box::<Metal>();
$x instanceof App\Containers\Box;     // true
$y instanceof App\Containers\Box;     // true
```

Generic traits get dropped entirely -- PHP can't `instanceof` a trait,
so a marker would be useless.

## Marker interfaces as wildcard-shaped positions

The marker interface gives xphp something *shaped* like a wildcard
type-hint position. Writing the bare template name (no `<>`) accepts
any `Box<X>`:

```php
function inspect(Box $b): void {     // accepts any Box<X>
    if ($b instanceof Box) {
        // $b could be Box<int>, Box<string>, Box<User>, ...
    }
}

inspect(new Box::<int>(42));      // works
inspect(new Box::<string>('hi')); // works
```

The closest analogues elsewhere are Java's `Box<?>` and Kotlin's
`Box<*>` (the "star projection"). Both are **use-site existential
types**: the type system carries the witness for `T` so callers can
still read out values at the declared upper bound. xphp's
marker-shaped position is weaker — there is *no runtime witness*
for `T`. You can pass instances through and run `instanceof`, but
you cannot dispatch methods through the wildcard, because the marker
declares none:

```php
function leak(Box $b): mixed {
    return $b->get();        // ⚠️ Box has no get() -- the marker is empty.
}
```

TypeScript has no true use-site existential. `Box<unknown>` is the
same generic with `T = unknown` (the top type) — assignability
depends on declared variance. `Box<any>` is the practical "any Box"
sigil that works because `any` is bivariant, but it strips type
information from reads, so values pulled out are typed `any` and
the loss of discipline propagates through the rest of the code.
Neither is the analogy to reach for.

### What works today

- Passing any specialization through a bare-`Box` parameter.
- `instanceof Box` (the marker) returning true for every
  specialization.
- Storing a `Box` in a typed property / returning it from a function.

### What doesn't work today

- Method dispatch through the bare marker (because the marker is
  empty). Kotlin's `Box<*>` permits reads — `get()` returns the
  declared upper bound (`Any?` for unbounded `T`) — because Kotlin's
  star projection runs through the existential's bound. xphp's
  marker has no bound and no methods.

Closing the gap means populating the marker with upper-bound-typed
read-only methods so the bare-name form can genuinely dispatch.
That's tracked under
[roadmap → type system breadth → wildcards via marker methods](../roadmap.md#type-system-breadth).

### What's NOT a wildcard

`mixed` is a regular scalar type, not a wildcard sigil:

```php
function takeAnything(Box<mixed> $b): void { /* ... */ }

takeAnything(new Box::<int>(42));    // FAILS at the type boundary
// Argument #1 ($b) must be of type Box_T_<hash-of-mixed>,
// Box_T_<hash-of-int> given.
```

`Box<mixed>` specializes to its own distinct class where `T = mixed`
is baked into every signature. It's a sibling of `Box<int>` and
`Box<string>`, not a supertype of them. If you want "any Box",
write the marker form: `function takeAnything(Box $b): void`.

## Collision-safe naming

Specialized classes use a hashed naming scheme: `T_<hash>` where the
hash is a SHA-256 digest of the canonical argument list, truncated to
`XPHP_HASH_LENGTH` (default 64 chars).

The canonical form joins arguments with `|`, recursively encoding
generic arguments as `Name<Inner,...>`.

The resulting FQCN looks like
`\XPHP\Generated\<original-template-FQCN>\T_<hash>`.

| Source instantiation                                          | Canonical hash input                     | Generated FQCN                                  |
|---------------------------------------------------------------|------------------------------------------|-------------------------------------------------|
| `App\Containers\Box<App\Models\Plastic>`                      | `App\Models\Plastic`                     | `\XPHP\Generated\App\Containers\Box\T_<hash1>`  |
| `App\Containers\List<App\Containers\Box<App\Models\Plastic>>` | `App\Containers\Box<App\Models\Plastic>` | `\XPHP\Generated\App\Containers\List\T_<hash2>` |

### Hash length is configurable

Set the `XPHP_HASH_LENGTH` env var (16–64, default 64). 16 hex chars
is 64 bits — birthday collisions infeasible at any practical scale.
64 is the full SHA-256 digest.

The namespace mirrors the template's original FQCN, so two `Box`
classes in different packages cannot collide regardless of how their
args are spelled. Hash-collision detection at recording time fails
loudly with both colliding instantiations, the current hash length,
and a re-run command using a longer hash.

### Free functions and constants bind the template's namespace

Because a specialized body moves into `XPHP\Generated\…`, an
unqualified free-function call or constant read inside it (`helper($x)`,
`FACTOR`) would, under PHP's normal rules, look in the *generated*
namespace and then fall back to global — never the template's own
namespace. The compiler prevents this: every unqualified free-function
call and constant fetch that the template's compilation unit defines
(directly, in another file of the same namespace, or via a
`use function` / `use const` import, single or grouped) is
fully-qualified to that symbol before the class is emitted. Built-in
functions, magic constants, and any name the unit does not define are
left untouched, so PHP's global resolution still applies to them. A
specialization therefore calls exactly the free functions and constants
its template did.

## What monomorphization costs

One class file per unique instantiation. A codebase instantiating
`Map<K, V>` with 50 distinct `(K, V)` combinations produces 50
generated files. That's the cost.

In exchange:

- **Zero runtime overhead.** OpCache compiles each specialized class
  once; subsequent instantiations are normal `new` calls.
- **Honest reflection.** `ReflectionParameter::getType()->getName()`
  returns the real concrete type — what DI containers and serializers
  actually need.
- **Native `TypeError` enforcement** at every boundary, without
  writing one line of reflection-aware glue.
- **Real subtype edges** between specializations when variance markers
  are declared (`Producer<Banana>` actually `extends Producer<Fruit>`).

Type erasure (the PHPDoc / attribute path) generates fewer files at
the price of re-introducing the exact problem xphp exists to solve.
The trade is intentional.

## What this means for serializers and reflection-based tools

DI containers, serializers (Symfony / JMS / Spatie), and validators
that inspect parameter types see the concrete class -- they Just Work.

For specialized generic *closures* there's one subtlety: the variable
holding the closure is rewritten to a dispatcher closure with a
fixed-shape signature. Reflection-based closure serializers
(`opis/closure`, `laravel/serializable-closure`) see the dispatcher
shape rather than the original closure body. See the
[caveats page](../caveats.md#reflection-on-rewritten-generic-closures)
for the workaround.
