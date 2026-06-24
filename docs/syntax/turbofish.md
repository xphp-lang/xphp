# Turbofish: call-site type arguments

xphp uses the **turbofish** operator `::<T>` to attach type arguments
to a call site. The operator works in five shapes, all
whitespace-sensitive: there must be no space between `::` and `<`.

This syntax tracks the
[PHP RFC: bound-erased generic types](https://wiki.php.net/rfc/bound_erased_generic_types)
proposal, so source written for xphp today is forward-compatible with
a future RFC-aligned native PHP runtime.

## Example

```php
<?php
declare(strict_types=1);

namespace App;

// 1. Constructor (with `new`)
$box = new Box::<int>(42);

// 2. Static method call
$x = Util::identity::<int>(7);

// 3. Free function call
$p = pair::<int, string>(1, 'a');

// 4. Instance method call (regular + nullsafe)
$names = $users->map::<string>(fn(User $u): string => $u->name);
$result = $obj?->process::<Order>($payload);

// 5. Variable turbofish (a closure or arrow assigned to a variable)
$id = fn<T>(T $x): T => $x;
$y  = $id::<int>(42);

// All five shapes accept the empty turbofish when every type-param
// has a default:
$cache = new Cache::<>;          // pads to <string, mixed>
$any   = Util::any::<>();        // method with all-defaulted params
$f     = $closure::<>($arg);     // closure with defaulted T
```

## What gets emitted

The turbofish strips from the cleaned source so PHP's parser sees a
normal call. The compiler then rewrites the call to point at the
right specialization:

```php
// new Box::<int>(42)  ->
new \XPHP\Generated\App\Box\T_<hash-of-int>(42);

// Util::identity::<int>(7)  ->
Util::identity_T_<hash-of-int>(7);

// pair::<int, string>(1, 'a')  ->
\App\pair_T_<hash-of-(int,string)>(1, 'a');

// $users->map::<string>($fn)  ->
$users->map_T_<hash-of-string>($fn);

// $id::<int>(42)  ->  (variable turbofish goes through the dispatcher)
$id('T_<hash-of-int>', 42);
```

## Rules

- **Whitespace-sensitive**: `Foo:: <T>` is **not** a turbofish; the
  scanner requires `::<` to be adjacent.
- **Anchored to a name**: `Foo<T>(...)` (no `::`) is a PHP-side
  ambiguity (`Foo < T` could be comparison) and is rejected.
- All call-site shapes can carry empty turbofish `::<>` when the
  template is all-defaulted.
- Bare `new Foo;` (no `(` or `::<>`) also works for all-defaulted
  class templates — see [defaults](defaults.md).

## Receiver-type analysis (instance methods)

For instance-method turbofish `$x->m::<T>(...)`, the compiler needs to
know what class `$x` holds to pick the right method template. The
receiver's type is determined from:

1. A declared type: a typed parameter, a typed property (`$this->prop`),
   or `$this`.
2. A local assigned from `new Foo()`, a method return, a chained call,
   or a `self`/`static` factory.
3. A branch whose arms all agree on the same class.

If the analysis can't prove a single class — `$x` is an untyped
`foreach` variable, or it's reassigned across a branch whose arms
disagree — the turbofish call **can't be specialized**. The generic
method is stripped from its class, so a non-specialized call would fatal
at runtime ("undefined method"); rather than emit that, the compiler
reports a **compile-time error**
([`xphp.undetermined_receiver`](../errors.md#diagnostic-codes)). Give the
receiver a statically-known type.

Once the receiver class is known, the method is resolved through its
**inheritance chain** (nearest ancestor first), so a generic method
declared on a base class is callable on a subclass receiver. The same
holds for the static (`Sub::m::<T>()`) and nullsafe (`$x?->m::<T>()`)
shapes. See
[methods and functions → inheritance](methods-and-functions.md#inheritance).

A turbofish call whose generic method can't be resolved on the receiver
or any of its ancestors is a **compile-time error**
([`xphp.unresolved_generic_call`](../errors.md#diagnostic-codes)) rather
than a silent pass-through that fatals at runtime.

## Caveats

- > ⚠️ **Branching narrowing precision** — receiver-type analysis
  conservatively refuses to ground `$x` after it's reassigned across
  branches whose arms disagree; a turbofish call there is a compile
  error (`xphp.undetermined_receiver`), not a silent de-specialization.
  See [caveats](../caveats.md#branching-narrowing-precision-loss).

## See also

- Test fixture: `test/fixture/compile/generic_method/`
- Test fixture: `test/fixture/compile/generic_function/`
- Test fixture: `test/fixture/compile/nested_instantiation/`
- Related: [methods and functions](methods-and-functions.md),
  [closures and arrows](closures-and-arrows.md),
  [pseudo-types](pseudo-types.md)
