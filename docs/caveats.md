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

Drop the `static` modifier, or lift the body to a named function:

```php
$f = function<T>(T $x): T { return $x; };       // works
// or
function id<T>(T $x): T { return $x; }
id::<int>(42);                                    // works
```

---

## Variance markers are class-level only

### ❌ What doesn't work

```php
function process<+T>(T $x): T { /* ... */ }     // free function
class Box<T> {
    public function map<+U>(callable $f): Box<U> { /* ... */ }     // method
}
$producer = function<+T>(): T { /* ... */ };     // closure
$arrow    = fn<+T>(T $x): T => $x;               // arrow
```

```
Variance markers `+T` / `-T` are not supported on methods, functions,
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
class Producer<+T> {
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
$x->m::<int>($arg);     // de-specializes -- not a Foo or Bar method call
```

The post-branch call drops to a non-specialized path because the
analysis can't prove a single class for `$x`.

> This is a **precision** issue, not a soundness one. xphp will NOT
> pick the wrong class — it just gives up on the specialization.

### Why

Receiver-type analysis is conservative: when `$x` is reassigned
inside a branch and the arms don't agree on a class, post-branch
calls fall back to a non-specialized path. Otherwise the compiler
could pick a class that doesn't match what the variable actually
holds at runtime.

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

class Container<+T> {     // covariant
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

## Covariant getters trip the `xphp check` PHPStan pass

### ❌ What gets flagged

```php
class Producer<+T> {
    private mixed $item;                       // backing field can't be `T` (properties are invariant)
    public function __construct(T $item) { $this->item = $item; }
    public function get(): T { return $this->item; }
}

$p = new Producer::<Banana>(new Banana());     // compiles + runs fine
```

`xphp compile` is happy and the runtime is correct, but `xphp check`'s
optional [PHPStan-over-the-compiled-output pass](errors.md#phpstan-over-the-compiled-output)
reports, on the `Producer<Banana>` specialization:

```
Method ...\Producer\T_<hash>::get() should return App\Banana but returns mixed.
[phpstan.return.type]
```

### Why

A covariant container can't store its element in a `T`-typed **property** (PHP
property types are invariant across the `extends` edge — see
[variance markers are class-level only](#variance-markers-are-class-level-only)
above and the [variance](syntax/variance.md) rules), so the backing field is
`mixed`/`array`. xphp substitutes type parameters in **signatures** (the emitted
`get(): Banana` is correct), but **not inside method bodies** — `return
$this->item` still reads a `mixed` field. PHPStan, analysing the concrete output,
sees `mixed` returned where `Banana` is declared and reports it. This is the
PHPStan pass being stricter than xphp's own generic checks, not a generics error;
it applies to any covariant getter-over-storage (including the docs' own
`ImmutableList` / `Producer` examples).

### ✅ Workaround

- Run the generic checks without the PHPStan pass: `xphp check src --no-phpstan`
  (the generics themselves still validate).
- Or scope a PHPStan ignore to the generated getter in your `phpstan.neon`
  (e.g. `ignoreErrors` on `#Method .*::get\(\) should return.*but returns mixed#`).
- Or narrow inside the getter body so PHPStan can prove the type, e.g.
  `assert($this->item instanceof Fruit); return $this->item;` (only viable when a
  concrete bound is known).

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
container keyed on (or deduplicating) arbitrary objects, bound the type
parameter on `\Hashable`, the recognized value-equality bound, and key on
`hashCode()` internally:

```php
class Map<K: \Hashable, V> {
    private array $buckets = [];
    public function set(K $k, V $v): void { $this->buckets[$k->hashCode()] = [$k, $v]; }
    public function get(K $k): V { return $this->buckets[$k->hashCode()][1]; }
}
```

xphp **recognizes** the `\Hashable` bound (so `Map<K: \Hashable, V>` and
`Set<T: \Hashable>` compile and are bound-checked) but ships **no** runtime
`Hashable` interface — it's a pure transpiler. You (or your collection
library) provide the contract, e.g.:

```php
interface Hashable {
    public function hashCode(): int|string;
    public function equals(self $other): bool;
}
```

Reference it fully-qualified (`\Hashable`) or via `use`, the same as the
built-in `\Stringable` bound. The deduping/keying logic itself is ordinary
runtime code in your container — the bound just gives it a type-checked
contract.

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
