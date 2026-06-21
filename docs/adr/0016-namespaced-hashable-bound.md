# 16. A namespaced `XPHP\Hashable` value-equality bound

- Status: Accepted — 2026-06

## Context and Problem Statement

PHP array keys are `int|string` only, so a generic container that keys on or
deduplicates **arbitrary objects** needs a value-equality contract — a
`hashCode()` / `equals()` pair — to bound its key parameter on. xphp recognizes
such a bound so `Set<T : Hashable>` / `Map<K : Hashable, V>` are expressible and
compile-time bound-checked, without xphp shipping any runtime interface (bounds
are nominally erased — [ADR-0005](0005-nominal-erased-bound-checking.md)).

The recognized name lives in the compiler's built-in-type whitelist
(`TypeHierarchy::BUILTIN_TYPES`), alongside the real PHP-native interfaces
(`Stringable`, `Countable`, …) that user code can implement without xphp seeing
their declaration. The question is **what to call it**. It was originally the
global `Hashable`. Every other whitelist entry is a real PHP global interface that
PHP itself guarantees at that name; `Hashable` is the only invented one, and a
global `\Hashable` is a generic, collision-prone name.

## Decision Drivers

- A name xphp invents must not squat a global identifier PHP (or a widely-used
  library) might later define with a *different* contract — bounds are erased, so
  xphp would silently conflate the two and enforce nothing.
- Keep xphp a pure transpiler: it should ship no runtime interface; the consumer
  (or their collection library) provides the contract.
- Stay vendor-neutral — don't hard-code one specific library's interface as the
  only recognized value-equality bound.

## Considered Options

- **Global `\Hashable`** (the original) — simplest, but squats a generic global
  name and is the lone non-native entry in a whitelist of real PHP globals.
- **`Ds\Hashable`** (the established ext-ds / php-ds interface) — namespaced and
  real, but hard-codes one library's interface as the blessed bound.
- **A namespaced, vendor-neutral `XPHP\Hashable`** — recognized under xphp's own
  namespace, provided by the consumer.

## Decision Outcome

Chosen: **recognize a namespaced `XPHP\Hashable`**, dropping the magic from the
global `Hashable`. It is referenced fully-qualified (`\XPHP\Hashable`) or via a
`use`, exactly like the built-in `\Stringable` bound, and the consumer provides
the interface (xphp still ships nothing):

```php
namespace XPHP;

interface Hashable {
    public function hashCode(): int|string;
    public function equals(self $other): bool;
}
```

Namespacing makes a collision with a future PHP-native global interface
structurally impossible — PHP reserves the global namespace, not `XPHP\`. The name
stays vendor-neutral (it doesn't bless php-ds or any other library), while keeping
xphp a pure transpiler: the `XPHP\Hashable` name is recognized, but no runtime type
is emitted or required from xphp itself.

The change is free of compatibility cost: the bound was still unreleased when it was
renamed, so no published code depended on the global name.

### Consequences

- Good: no global-namespace squat; the recognized bound can never clash with a
  future `\Hashable` in PHP core or a popular library.
- Good: bare `Hashable` is no longer magic — inside any namespace it follows normal
  PHP name resolution, so a project's own `App\Hashable` is never shadowed.
- Trade-off: the contract lives under xphp's namespace, so a consumer who wants the
  bound defines (or aliases) an interface at `XPHP\Hashable`. Acceptable — it's a
  one-line interface, and the namespace clearly signals "the bound xphp recognizes."
- Trade-off: not auto-aligned with `Ds\Hashable`; a php-ds user aliases or
  re-declares rather than reusing `Ds\Hashable` directly.

### Confirmation

The recognized name is the single entry `XPHP\Hashable` in
[`TypeHierarchy::BUILTIN_TYPES`](../../src/Transpiler/Monomorphize/TypeHierarchy.php);
the name-resolution special-case that lets a built-in resolve unqualified is scoped
to single-segment (no-namespace) natives, so a relative `XPHP\Hashable` follows
normal PHP namespacing. Tests pin that `Set<T : \XPHP\Hashable>` compiles against a
class that `implements \XPHP\Hashable`, that a non-implementing class is rejected
with the bound named in the error, and that a bare global `\Hashable` is no longer a
recognized type. Documented in [Caveats](../caveats.md) and the
[roadmap](../roadmap.md).

## More Information

- [ADR-0005](0005-nominal-erased-bound-checking.md) — nominal, erased bound checking
  (why xphp recognizes a bound name without enforcing its method shape).
- [Caveats](../caveats.md) — object-keyed containers and the `XPHP\Hashable` bound.
