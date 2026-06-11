# Syntax tour

A hands-on walk through every feature xphp adds on top of PHP. Each
page leads with runnable source, shows the slice of generated PHP that
matters, lists the rules, and links to a test fixture you can run
yourself.

If you're brand new, start with [getting started](../getting-started.md)
first.

## Pages

| Page | What it covers |
|------|----------------|
| [Classes and interfaces](classes-and-interfaces.md) | `class Box<T> {}`, generic interfaces and traits, marker-interface runtime behavior |
| [Methods and functions](methods-and-functions.md) | Generic methods (static + instance), generic free functions, bare top-level |
| [Closures and arrows](closures-and-arrows.md) | `function<T>(...)`, `fn<T>(...) => ...`, captures incl. by-ref |
| [Type bounds](type-bounds.md) | `T : Stringable`, `T : A & B`, `T : (A & B) \| C`, F-bounded `T : Box<T>` |
| [Variance](variance.md) | `+T`, `-T`, position rules, subtype edges between specializations |
| [Defaults](defaults.md) | `T = int`, forward refs `Pair<A, B = A>`, empty turbofish `$f::<>()` |
| [Pseudo-types](pseudo-types.md) | `self<T>` / `static<T>` / `parent<T>` and the `new self::<T>(...)` form |
| [Turbofish](turbofish.md) | All four call-site shapes plus variable and empty turbofish |
| [Array sugar](array-sugar.md) | `T[]` shorthand |

## Quick reference card

```php
// Generic class
class Box<T> {
    public function __construct(public T $item) {}
}
$intBox = new Box::<int>(42);

// Generic interface / trait
interface Container<T> { public function get(): T; }
trait HasItem<T> { public T $item; }

// Generic method (static or instance)
class Util {
    public static function id<T>(T $x): T { return $x; }
}
$x = Util::id::<int>(7);

// Generic free function
function pair<A, B>(A $a, B $b): array { return [$a, $b]; }
$p = pair::<int, string>(1, 'a');

// Generic closure
$pick = function<T>(T $x, T $y, bool $first): T { return $first ? $x : $y; };
$pick::<int>(1, 2, true);

// Generic arrow function
$id = fn<T>(T $x): T => $x;
$id::<string>('hi');

// Type bounds
class Sortable<T : Comparable<T>> {}     // F-bounded
class Pair<K : Stringable & Countable, V> {}

// Variance
class Producer<+T> { public function get(): T; }     // covariant
class Consumer<-T> { public function set(T $x): void; }   // contravariant

// Default type params
class Cache<K = string, V = mixed> {}
new Cache;                                // pads to <string, mixed>
new Cache::<>;                            // same
new Cache::<int, User>;                   // explicit

// Pseudo-types
class Container<T> {
    public function with(T $n): self<T> { return new self::<T>($n); }
}
return new self::<T>($x);                 // constructor turbofish
```

For the runtime side -- how marker interfaces work, how specialized
classes are named, why reflection sees the real types -- see
[runtime semantics](../guides/runtime-semantics.md).
