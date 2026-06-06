# Default type parameters

Type parameters can carry default arguments with `T = Default`. Call
sites that supply fewer args than parameters pad with the defaults.
Two call-site shapes are equivalent to "use all defaults": the bare
`new Cache;` form and the empty turbofish `new Cache::<>;` form.

## Example

```php
<?php
declare(strict_types=1);

namespace App;

// Class with two defaulted params
class Cache<K = string, V = mixed> {
    public function __construct(public K $key, public V $value) {}
}

// Forward reference -- B's default is A
class Pair<A, B = A> {
    public function __construct(public A $first, public B $second) {}
}

// Generic function with default
function box<T = int>(T $x): array { return [$x]; }

// Generic closure with default
$tag = function<T = string>(T $x): T { return $x; };

$a = new Cache('age', 42);                  // pads to Cache<string, mixed>
$b = new Cache::<>;                         // same -- empty turbofish, defaults
$c = new Cache::<int, User>(1, new User);   // explicit
$d = new Pair::<int>(1, 2);                 // pads B to int (forward ref)

$x = box::<>(7);                            // pads T to int
$y = $tag::<>('hi');                        // pads T to string
```

## What gets emitted

Defaults are resolved at compile time before specialization. The
generated class has the concrete defaults baked in:

```php
// Cache::<> and bare `new Cache` both produce:
namespace XPHP\Generated\App\Cache;
class T_<hash-of-(string,mixed)> implements \App\Cache {
    public function __construct(public string $key, public mixed $value) {}
}

// Pair::<int> pads forward ref:
namespace XPHP\Generated\App\Pair;
class T_<hash-of-(int,int)> implements \App\Pair {
    public function __construct(public int $first, public int $second) {}
}
```

For closure/arrow defaults, the empty turbofish `$f::<>(...)` rewrites
to a dispatcher call with the padded-args tag; the specialized
function uses the default type.

## Rules

- Required-after-default is rejected: `class Bad<T = int, U> {}` is
  a parse error.
- Defaults can reference *strictly earlier* type parameters: `class
  Pair<A, B = A>` is allowed; `class Pair<A = B, B>` is rejected.
- Self-reference (`T = T`) at the top level is rejected.
- Defaults work on:
  - Classes / interfaces / traits
  - Methods + free functions
  - Anonymous closures and arrows
- `static function<T = ...>` is **not** supported — defaults on
  static closures stay rejected at parse time.
- Bare `new Cache;` (no `::<>`, no parens) resolves only when every
  type param has a default.
- The empty turbofish `Name::<>` is a single token (PHP's legacy
  `<>` not-equal operator is reused as the syntax marker), so it
  only fires immediately after `::` and won't conflict with a
  `$x <> $y` comparison elsewhere.

## Caveats

- > ⚠️ **`static` closures + defaults rejected** — `static function<T = int>(...)`
  fails at parse time. See
  [caveats](../caveats.md#static-closures-not-supported).

## See also

- Test fixture: `test/fixture/compile/defaults_full/`
- Test fixture: `test/fixture/compile/defaults_forward_ref/`
- Test fixture: `test/fixture/compile/defaults_cross_file_bare_new/`
- Test fixture: `test/fixture/compile/closure_dispatcher_defaults/`
- Related: [type bounds](type-bounds.md), [turbofish](turbofish.md)
