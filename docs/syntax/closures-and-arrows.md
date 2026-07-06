# Closures and arrow functions

Anonymous generic templates assigned to a variable, then called with
the variable-turbofish syntax `$f::<T>(...)`. Both `function<T>(...) {...}`
and `fn<T>(...) => ...` forms are supported, including explicit
`use (...)` captures and arrow's implicit captures.

## Example

```php
<?php
declare(strict_types=1);

namespace App;

// Generic closure, capture-free
$pair = function<K, V>(K $key, V $value): array {
    return [$key, $value];
};

// Generic arrow with implicit capture
$y = 1;
$add = fn<T>(T $x): T => $x + $y;
$y = 99;     // captured snapshot stays at 1

// Generic closure with explicit `use`, including by-ref
$counter = 0;
$tag = function<T>(T $x) use (&$counter): array {
    $counter++;
    return [$x, $counter];
};

$pair::<string, int>('age', 42);
$result = $add::<int>(10);          // 11 (uses captured $y = 1)
$first  = $tag::<string>('first');  // ['first', 1]
$second = $tag::<int>(2);           // [2, 2]   -- $counter is now 2 outside too
```

## What gets emitted

Each anonymous template's variable is rewritten to a small
**dispatcher closure**. The dispatcher carries any captures from the
original `use ()` clause (or, for arrows, synthesized from the body's
free variables) and routes runtime calls to the right specialized
top-level function:

```php
$pair = function (string $__xphp_tag, mixed ...$__xphp_args): mixed {
    return match ($__xphp_tag) {
        'T_3a9f...' => \App\closure_pair_T_3a9f...(...$__xphp_args),
        default     => throw new \RuntimeException(
            'Unknown generic specialization tag: ' . $__xphp_tag),
    };
};
function closure_pair_T_3a9f...(string $key, int $value): array {
    return [$key, $value];
}

// Call site rewrite:
$pair('T_3a9f...', 'age', 42);
```

For arrows, the captured variables also get lifted onto each
specialized function as trailing `mixed` params; for `use (&$y)` the
ref-ness is preserved end-to-end.

## Rules

- Two same-typed-arg calls share one specialization.
- Empty turbofish `$f::<>()` is valid when every type-param has a
  default; defaults pad the missing args. See [defaults](defaults.md).
- Arrow implicit captures are computed from the body's free variables
  (excluding params and `$this`). Variables imported by a nested
  closure's `use` clause are also captured for the outer arrow.
- Explicit `use (...)` clauses on closures forward verbatim onto the
  dispatcher, including `&` byref captures.
- The variable receiver stays unchanged at call sites — `$f::<int>(...)`
  becomes `$f('T_<hash>', ...)`, not a renamed call.

## Caveats

- > ⚠️ **`$this`-capturing rejected** — arrows and closures whose
  body references `$this` are rejected at compile time. Rewrite as a
  method on the enclosing class, or extract `$this->property` to a
  local before the closure. See
  [caveats](../caveats.md#this-capturing-arrows-and-closures-rejected).

- > ⚠️ **`static` closures rejected** — `static function<T>(...)` is
  rejected because generic static closures can't yet be specialized at
  the call site (a capability gap, not a binding one). Use a named
  generic function at file scope instead. See
  [caveats](../caveats.md#static-closures-not-supported).

- > ⚠️ **Variance markers not allowed** — `out T` / `in T` are rejected on
  anonymous templates. They have no stable identity for an `extends`
  chain. See [caveats](../caveats.md#variance-markers-are-class-level-only).

- > ⚠️ **Reflection sees the dispatcher** — `Reflection*` on a
  rewritten `$pair` reports a 2-arg variadic closure, not the
  original body. Affects closure serializers. See
  [caveats](../caveats.md#reflection-on-rewritten-generic-closures).

## See also

- Test fixture: `test/fixture/compile/closure_generic/`
- Test fixture: `test/fixture/compile/closure_dispatcher_arrow/`
- Test fixture: `test/fixture/compile/closure_dispatcher_use_clause/`
- Test fixture: `test/fixture/compile/closure_dispatcher_defaults/`
- Related: [defaults](defaults.md), [turbofish](turbofish.md)
