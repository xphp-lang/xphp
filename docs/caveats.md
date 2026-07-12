# Caveats

Every shipped feature in xphp has trade-offs. This page collects the
ones you'll hit in real code, in the order they're likely to bite,
each with the underlying reason and the workaround.

Pages in the [syntax tour](syntax/) link back to specific sections
here using anchor links — search this page for the same heading text.

## `$this`-capturing arrows and closures rejected

### ❌ What doesn't work

```php
class Holder {
    public int $v = 5;
    public function go(): int {
        $f = fn<T>(T $x): int => $x + $this->v;
        return $f::<int>(2);
    }
}
```

```
Generic arrow `$f::<...>(...)` captures `$this`, which is not yet
supported. Rewrite as a method on the enclosing class, or extract
the value of $this->property into a local variable before the arrow.
```

The same error fires for generic closures whose body references
`$this`.

### Why

The closure variable rewrite produces a dispatcher closure that
forwards to top-level specialized functions. Top-level functions
can't see the enclosing class's `$this`, and PHP doesn't allow
`use ($this)` on a closure either. Carrying `$this` would need
either a method-rewriting pass (today's level) or rewriting `$this->v`
to a lifted `mixed $__xphp_this` param everywhere — a deeper
refactor that's queued up for later.

### ✅ Workaround

Either rewrite as a method on the class:

```php
class Holder {
    public int $v = 5;
    public function go(): int {
        return $this->add::<int>(2);
    }
    public function add<T>(T $x): int {
        return $x + $this->v;
    }
}
```

Or extract the value to a local before the closure:

```php
class Holder {
    public int $v = 5;
    public function go(): int {
        $base = $this->v;
        $f = fn<T>(T $x): int => $x + $base;
        return $f::<int>(2);
    }
}
```

---

## `static` closures not supported

`static` **arrows** work: `static fn<T>(T $x): T => $x` specializes
exactly like a plain arrow (an arrow can never bind `$this`, so the
`static` is inert; note the rewritten dispatcher closure is technically
non-static — observable only through `Closure::bind` or reflection).
The gap below is specific to the `static function` (closure) syntax.

### ❌ What doesn't work

```php
$f = static function<T>(T $x): T { return $x; };
$f::<int>(42);
```

```
Generic static closures cannot yet be specialized at call sites.
Rewrite the call site for `$f::<...>(...)` to use a named generic
function at file scope.
```

The same form is rejected at parse time when combined with defaults:

```php
$f = static function<T = int>(T $x): T { return $x; };
```

```
Generic parameter `T` has a default value, which is not yet supported
on static closures. Drop the `static` modifier or assign the closure
to a named function.
```

### Why

Specializing an anonymous template at its call site landed in stages.
Plain generic closures and arrows are rewritten through the dispatcher
today; `static` closures (alongside explicit `use (...)` closures) are
a still-unimplemented branch of that rewrite. It's a capability gap,
not a binding one — a `static` closure has no `$this` to begin with, so
this is unrelated to the [`$this`-capture
rejection](#this-capturing-arrows-and-closures-rejected) above. The
named-function path is already complete, so lifting the body to a
file-scope generic function side-steps it.

### ✅ Workaround

Use an arrow, drop the `static` modifier, or lift the body to a named
function:

```php
$f = static fn<T>(T $x): T => $x;               // works
$f = function<T>(T $x): T { return $x; };       // works
// or
function id<T>(T $x): T { return $x; }
id::<int>(42);                                    // works
```

---

## Closure signature types only in parameter, return, and property slots

A `Closure(int $x): bool` signature type is accepted anywhere a plain
type hint goes — a parameter, a return, a property, or nested inside
another signature. Two positions are **not** supported: a generic type
argument (`Box<Closure(int): int>`) and a generic bound
(`class C<T : Closure(int): int>`). Each is a clear compile error:

```
A Closure(...) signature type is not supported as a generic type argument
(closure signatures are allowed only in parameter, return, and property
types). Use a bare \Closure, or introduce a named type alias.
```

A signature parameter must also carry a type and cannot have a default
value — a signature describes the callable's shape, not call-time
values.

### Why

A signature erases to a bare `\Closure` before specialization, but a
generic argument or bound participates in specialization *itself*
(naming, hashing, subtype edges), where a structural type has no
identity to anchor to. Rejecting loudly keeps the cardinal rule: no
silent miscompile. Lifting these positions is on the
[roadmap](roadmap.md) as a discovery item.

### ✅ Workaround

Use a bare `\Closure` in the generic position — you lose the
compile-time conformance check but keep a working type — or wrap the
callable in a named class:

```php
class C<T : \Closure> {}                 // works: bare Closure bound
$b = new Box::<\Closure>(fn() => 1);     // works: bare Closure argument
```

See [closure types → known limitations](syntax/closure-types.md#known-limitations)
for the full list.

---

## Variance markers are class-level only

### ❌ What doesn't work

```php
function process<out T>(T $x): T { /* ... */ }     // free function
class Box<T> {
    public function map<out U>(callable $f): Box<U> { /* ... */ }     // method
}
$producer = function<out T>(): T { /* ... */ };     // closure
$arrow    = fn<out T>(T $x): T => $x;               // arrow
```

```
Variance markers `out T` / `in T` are not supported on methods, functions,
closures, or arrow functions — variance is a class-level-only feature by
design: a function or closure specialization has no stable class identity
to anchor a subtype `extends` edge to. Move the generic to a class-level
type parameter.
```

### Why

This is a **permanent design boundary**, not a pending feature. Variance turns
into real `extends` chains between specialized classes (see
[variance](syntax/variance.md)). Methods, functions, closures, and arrows
don't have a stable class identity to anchor an `extends` chain to — their
specializations are functions, not classes, so there's nothing for the subtype
edge to attach to. (This matches Kotlin, whose `fun <R> map(...)` is likewise
invariant.) Keep variance at the class level and let method-level type
parameters stay invariant.

### ✅ Workaround

Put the template on a named class and use its method:

```php
class Producer<out T> {
    public function __invoke(): T { /* ... */ }
}
```

---

## Reflection on rewritten generic closures

### ❌ What doesn't work

```php
$triple = function<A, B, C>(A $a, B $b, C $c): array { return [$a, $b, $c]; };
$triple::<int, string, bool>(1, 'two', true);

// After compile:
$ref = new ReflectionFunction($triple);
$ref->getNumberOfParameters();    // returns 2 -- the dispatcher's
                                  // (string $tag, mixed ...$args) shape,
                                  // NOT the original three.
$ref->getParameters()[0]->getName();    // returns '__xphp_tag'
```

Closure serializers (`opis/closure`,
`laravel/serializable-closure`) serialize the dispatcher closure
rather than the original body.

### Why

The variable holding the generic closure is rewritten to a
**dispatcher** closure with a fixed `(string $__xphp_tag, mixed ...$__xphp_args)`
signature. The real body lives in one or more top-level functions
keyed by the tag. Reflection only sees the dispatcher.

Pre-this-machinery, generic closures couldn't be specialized at all
(they were rejected outright), so reflection-based serializers
couldn't see anything. The 2-arg dispatcher shape is an improvement
over a hard reject, just not a transparent one.

### ✅ Workaround

If your code path serializes closures via reflection, use a named
generic function instead:

```php
function pair<K, V>(K $k, V $v): array { return [$k, $v]; }

// Now reflection sees the real `pair_T_<hash>` specialization:
$ref = new ReflectionFunction('App\\pair_T_<hash-of-(string,int)>');
$ref->getNumberOfParameters();    // 2 (the real K, V)
```

If you need the variable form for some other reason (currying, etc.),
serialize the captured state separately and reconstruct the closure
on the receiving side.

---

## Reified-T is an xphp-specific divergence

### ❌ What doesn't port

```php
function decode<T>(string $json): T {
    $data = json_decode($json, true);
    return new T(...$data);             // works in xphp
}

if ($x instanceof T) { /* ... */ }       // works in xphp
$class = T::class;                       // works in xphp
```

The same code under a future RFC-aligned (erasure-based) PHP runtime
would NOT work — `T` would be erased at runtime, so `new T(...)`,
`instanceof T`, and `T::class` all become meaningless.

### Why

xphp uses monomorphization: every `Box<int>` is a real class with
`int` baked into every signature. Inside the specialized body, `T`
literally becomes `int`, so reified-T operations Just Work.

The RFC's erasure model doesn't keep T at runtime, so any code
relying on T being a runtime value would break on the future native
PHP runtime.

### ✅ Workaround

If forward-portability to a future RFC-aligned runtime matters more
than reified-T ergonomics, avoid `instanceof T`, `T::class`, and
`new T(...)`. Pass class names as explicit arguments instead:

```php
function decode(string $json, string $class): mixed {
    $data = json_decode($json, true);
    return new $class(...$data);
}
$user = decode($payload, User::class);
```

If forward-portability isn't a concern (you're not planning to
target a future erased-T runtime), use reified-T freely — it's one
of the things monomorphization makes naturally easy.

The README's "Heads up" banner mentions this divergence too.

---

## Branching narrowing precision loss

### ❌ What's less precise than ideal

```php
$x = new Foo();
if ($cond) {
    $x = new Bar();
}
$x->m::<int>($arg);     // compile error: xphp.undetermined_receiver
```

The post-branch call **fails to compile**: the analysis can't prove a
single class for `$x`, and a turbofish call can only be specialized
against a known receiver type.

> This is a **conservatism** issue, not a soundness one. xphp will NOT
> pick the wrong class, and it will NOT emit a runtime-broken call — it
> refuses at compile time and tells you to give `$x` a known type.

### Why

Receiver-type analysis is conservative: when `$x` is reassigned inside a
branch and the arms don't agree on a class, the receiver's type is
undetermined. The generic method is stripped from its class at compile
time, so a non-specialized `$x->m(...)` would call a method that no
longer exists and fatal at runtime — so the compiler reports
`xphp.undetermined_receiver` instead of emitting it (ground or fail).

The same-arms-agree shape IS supported:

```php
$x = new Foo();
if ($cond) {
    $x = new Foo();       // same class as the other arm
}
$x->m::<int>($arg);       // specializes against Foo
```

### ✅ Workaround

Either separate the call sites by branch:

```php
if ($cond) {
    $x = new Bar();
    $x->m::<int>($arg);     // specializes against Bar
} else {
    $x = new Foo();
    $x->m::<int>($arg);     // specializes against Foo
}
```

Or use a typed local before the call:

```php
/** @var Foo|Bar $x */
$x = $cond ? new Bar() : new Foo();
// Manually call the right specialized name on each branch.
```

A future version may add union-type tracking with runtime dispatch,
but it's queued behind higher-priority work.

---

## Variance validator and trait `use`

### ❌ What doesn't get checked

```php
trait HasItem<T> {
    public function set(T $item): void { /* ... */ }
}

class Container<out T> {     // covariant
    use HasItem<T>;
    // The `set(T $item)` from the trait places T in a contravariant
    // position. The validator should reject -- but it doesn't, because
    // it doesn't walk into trait-imported methods.
}
```

The variance validator emits no error today on trait-imported method
signatures. If the trait's method places T in a forbidden position,
the violation slips through compilation.

### Why

The validator walks `ClassMethod` nodes declared directly on the
ClassLike. Trait method bodies are stitched into the class by Zend
at compile time; the AST we walk pre-stitching doesn't see them.

### ✅ Workaround

Manually audit traits used by variant generic classes. If you control
the trait, copy the body into the class directly so the validator
can see it.

---

## Covariant `array`-backed collections trip the `xphp check` PHPStan pass

> **Scope:** this affects only a *multi-element* covariant collection backed by an
> `array` field, at **PHPStan level 6 and above**. A covariant **single-value**
> container no longer hits this — store the element in a `private T` property (PHP
> doesn't type-check private slots across the `extends` edge), and the emitted
> `get(): T` over a real-typed `private T` field is PHPStan-clean at every level.
> See the [`Producer<out T>`](syntax/variance.md#example) example.

### ❌ What gets flagged

```php
class ImmutableList<out T> {
    private array $items;                       // many elements → `array` backing, not `T`
    public function __construct(T ...$items) { $this->items = $items; }
    public function get(int $i): T { return $this->items[$i]; }
}

$list = new ImmutableList::<Banana>(new Banana());   // compiles + runs fine
```

`xphp compile` is happy and the runtime is correct, but `xphp check`'s
optional [PHPStan-over-the-compiled-output pass](errors.md#phpstan-over-the-compiled-output),
**at level 6 or higher**, reports on the `ImmutableList<Banana>` specialization:

```
Property ...\ImmutableList\T_<hash>::$items type has no value type specified
in iterable type array.
[missingType.iterableValue]
```

(At level ≤5 it is clean — the missing-iterable-value-type rule only switches on at
level 6.)

### Why

A collection holds *many* elements in one field, so the backing must be an `array`
(you can't fit them in a single `private T` slot). xphp substitutes type parameters
in **signatures** (the emitted `get(): Banana` is correct) but emits the backing as
a plain `private array $items` with **no value-type annotation** — PHP has no native
typed array, and xphp doesn't synthesise a `@var Banana[]` docblock for the
specialization. From level 6 PHPStan requires a value type on every iterable, so it
flags the untyped `array` property. This is the PHPStan pass being stricter than
xphp's own generic checks, not a generics error. (The element read
`return $this->items[$i]` is `mixed`, but PHPStan reports the *property*'s missing
value type rather than the return.)

A single-value container avoids this entirely because its backing field can be a
real-typed `private T` (a private property is variance-exempt — see the
[variance](syntax/variance.md) rules), so there is no untyped `array` at all. The
limitation is specific to the `array`-backed collection shape.

### ✅ Workaround

- Run the generic checks without the PHPStan pass: `xphp check src --no-phpstan`
  (the generics themselves still validate).
- Or analyse at level ≤5 (the missing-iterable-value-type rule is off there).
- Or scope a PHPStan ignore to the generated property in your `phpstan.neon`
  (e.g. `ignoreErrors` on
  `#Property .*::\$items type has no value type specified in iterable type array#`).

The construction side is unaffected — the constructor parameter keeps its real
type and is runtime-type-checked.

---

## `T[]` is xphp-only

### ❌ What doesn't work

```php
class Map<K, V> {
    private array<K, V> $items;     // rejected
}
```

The
[PHP RFC for generics](https://wiki.php.net/rfc/bound_erased_generic_types)
explicitly bans the `array<K, V>` syntax, and xphp follows.

### Why

The RFC took a clear position against expanding `array` into a
generic. xphp matches that boundary so source written today is
forward-compatible.

### ✅ Workaround

Use the `T[]` sugar (xphp-only but innocuous) or write `array`
directly:

```php
class Map<K, V> {
    private array $items;     // works, no compile-time element check
}
```

PHP array keys are `int|string` only, so `$this->items[$k] = $v` works
only when `K` is a string/int — it **fatals for object keys**. For a
container keyed on (or deduplicating) arbitrary objects, define your own
value-equality contract as a generic interface and bound on it, keying on
`hashCode()` internally. xphp special-cases nothing here — this is the same
pattern as a `Comparable<T>` ordering bound (see [type bounds](syntax/type-bounds.md)):

```php
// Your library/app declares the contract — xphp ships no `Hashable`.
interface Hashable<T> {
    public function hashCode(): int|string;
    public function equals(T $other): bool;
}

final class Money implements Hashable {                // bare marker — no LSP friction
    public function __construct(private int $cents) {}
    public function hashCode(): int|string { return $this->cents; }
    public function equals(Money $other): bool { return $other->cents === $this->cents; }
}

// F-bounded: K must be hashable to its own kind.
class Map<K: Hashable<K>, V> {
    private array $buckets = [];
    public function set(K $k, V $v): void { $this->buckets[$k->hashCode()] = [$k, $v]; }
    public function get(K $k): V { return $this->buckets[$k->hashCode()][1]; }
}
```

Because a generic interface lowers to an **empty marker** (see ADR-0004), the
implementing class declares `equals(Money $other)` with its **concrete** type and
PHP imposes no signature constraint — exactly how a `Comparable<T>` implementer
writes `compareTo(Money $other)`. The deduping/keying logic itself is ordinary
runtime code in your container; the bound just gives it a compile-time-checked
contract. (The container above is illustrative — xphp is a transpiler and ships
no collection types.)

---

## Duplicate generic template declaration

### ❌ What doesn't work

Two `.xphp` files in the same source set both declare a generic
function (or class) with the same FQN:

```
Generic function template "App\identity" already declared (in
src/Util.xphp); duplicate declaration in src/Other.xphp.
```

(The same error fires for classes — the message uses
`Generic template` instead of `Generic function template`.)

### Why

Specialization keys every template by FQN. Two definitions with the
same name would collide on the generated specializations and silently
overwrite each other; xphp surfaces both source paths instead so you
can see what's clashing.

### ✅ Workaround

Pick one canonical location. If you genuinely need the same name in
two namespaces, put them in different namespaces — the FQN differs
and they won't collide.

---

## Invalid default expression shape

### ❌ What doesn't work

```php
class Bad<T = Box | Other> {}     // union not allowed
class Bad<T = ?Box> {}             // nullable not allowed
class Bad<T = Box & Other> {}      // intersection not allowed
```

```
Generic parameter `T` has an invalid default; only a single concrete
or generic type is allowed after `=` (no nullable or union shapes).
```

### Why

Defaults are substituted at instantiation time. Supporting union /
intersection / nullable defaults would require a runtime decision on
which arm to materialize, which isn't compatible with
monomorphization's "one specialization per concrete arg tuple" model.

### ✅ Workaround

Pick a single default and let users override it explicitly with
turbofish:

```php
class Container<T = Box> {}
// users can still write: new Container::<Other> or new Container::<NullableThing>
```

---

## Anonymous classes can't be generic

### ❌ What doesn't work

```php
$x = new class<T> {
    public T $item;
};
```

Both the RFC and xphp forbid this — `new class<T> { ... }` is a
parse error.

### Why

Anonymous classes have no name to carry through specialization. The
template-to-specialization machinery is keyed on the FQN; anonymous
classes don't have one.

### ✅ Workaround

Lift to a named class:

```php
class TempContainer<T> {
    public T $item;
}
$x = new TempContainer::<User>();
```

## Newer PHP syntax needs a matching host runtime

### ❌ What doesn't work

Running the transpiler on PHP 8.4 over a source file that uses PHP 8.5
syntax — for example the pipe operator:

```php
$slug = $title |> trim(...) |> strtolower(...);
```

```
Syntax error, unexpected '>'
```

### Why

xphp owns only the generic syntax; everything else is plain PHP. It
parses your source with `nikic/php-parser` configured for the **host**
PHP version (the one running the transpiler). On PHP 8.4 the lexer
can't tokenize 8.5-only syntax like `|>`, so the parse fails before
specialization even begins. There's nothing generic about the line —
it just never reaches the host parser's grammar.

### ✅ Workaround

Run the transpiler on a PHP version that can parse your syntax — e.g.
PHP 8.5 for the pipe operator. Plain (non-generic) code, including
newer-PHP syntax, passes straight through untouched. Note the emitted
PHP still requires a runtime that supports those features to *execute*;
the supported floor for xphp itself is PHP 8.4 (`composer.json`).

---

## Self-reintroducing specialization (list ↔ map derivations)

### ❌ What doesn't work

A derivation whose result type re-wraps the receiver's own type family in a
*growing* form does not compile once that result is instantiated:

```php
class ImmutableList<out E> {
    // Seeds a map of sub-lists: the result type reintroduces the receiver's own
    // family (ImmutableList) one level deeper.
    public function groupBy<L>(callable $keyOf): ImmutableMap<L, ImmutableList<E>> { /* ... */ }
}
class ImmutableMap<K, out V> {
    public function values(): OrderedCollection<V> { /* ... */ }   // re-exposes the value as a list
}

// Aborts as soon as the result map is instantiated: specializing it walks its view
// bodies (values()/entries() construct deeper lists) even if you never call them.
$byKey  = $list->groupBy::<string>($keyOf);   // ❌ re-seeds List → Map → List → … without bound
$bucket = $byKey->get('a');
```

```
Generic specialization did not converge (exceeded depth 16): a self-reintroducing
cycle grows without bound through App\ImmutableMap (…/ImmutableMap.xphp) and
App\ImmutableList (…/ImmutableList.xphp) — e.g. "…". Break the cycle: return a
non-self-reintroducing type from the re-exposing member (for example a
non-generic iterable), or split the derivation …
```

### Why

This is a **by-design boundary**, not a pending feature
([ADR-0020](adr/0020-diagnose-and-restructure-self-reintroducing-specialization.md)).
xphp monomorphizes — one class per instantiation
([ADR-0001](adr/0001-monomorphization-over-type-erasure.md)) — so a member that
keeps producing a strictly-deeper instantiation of its own family has no fixed
point. Specialization discovers new instantiations **structurally**, from every
member of a specialized class — return types *and* the `new` expressions in method
bodies — including members you never call. So the result map's family-re-exposing
views (`values()`/`entries()`) re-seed the cycle on their own, and retyping a
signature without also changing the body that *constructs* the deeper value does not
stop it. Termination is guaranteed by the depth cap
([ADR-0006](adr/0006-bounded-specialization-depth-cap.md)), which aborts with a
localized diagnostic naming every concrete class in the cycle and its source file.
(It surfaces at `xphp compile`; `xphp check` does not specialize, so it passes
green — compile to see the diagnostic.) Auto-erasing the cycle (a
`dyn`-style seam) is deferred, not built: every monomorphizing language provides
such an escape hatch (Rust's `dyn Trait`, JVM/HHVM erasure), but it must erase the
*constructed value*, not merely the type.

### ✅ Workaround

Give the grouped result a **view-less type** — one with no `values()`/`entries()`/
`keys()` member (and no body) that returns or constructs the receiver's family. A
result exposing only `get(key)`/`count` cannot re-seed the cycle, so the derivation
converges. In practice, host `groupBy`/`associateBy` as static generics on a plain,
non-variant helper returning that view-less result, rather than as members of the
collection — which also keeps the list template free of any map-returning member.

If you do want bucket iteration, expose it past a **non-generic seam**: a view typed
`iterable`/`array` whose body returns a plain array (no `new ImmutableList::<…>`), so
neither the signature nor the body re-introduces the family:

```php
public function valuesList(): iterable { return array_values($this->entries); }
// foreach ($byKey->valuesList() as $bucket) { /* a real list at runtime; element type is mixed */ }
```

The element type is `mixed` past that seam (re-narrow with `instanceof` where a typed
bucket is needed) — the same trade a `dyn` boundary makes. Or **split the derivation**
so the growing type is never reached through an unbounded chain.
