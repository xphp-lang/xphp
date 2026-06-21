# 16. No special-cased value-equality bound — use ordinary generics

- Status: Accepted — 2026-06

## Context and Problem Statement

A generic container that keys on or deduplicates **arbitrary objects** needs a value-equality
contract (a `hashCode()` / `equals()` pair), because PHP array keys are `int|string` only. xphp
recognized such a contract by **whitelisting** a `Hashable` name in
`TypeHierarchy::BUILTIN_TYPES` — first as the global `Hashable`, then (briefly) as a
namespaced `XPHP\Hashable` to dodge a global-namespace collision — so a bound like
`Set<T : \XPHP\Hashable>` would compile and be bound-checked without the interface being in
the scanned sources, and without xphp shipping a runtime type. This ADR replaces that whole
line of thinking.

This left `Hashable` as the **only invented, non-PHP-native** entry in a whitelist otherwise made
of real PHP global interfaces (`Stringable`, `Countable`, …). Its direct analog — `Comparable<T>`,
an ordering contract — is **not** whitelisted at all: a library declares `interface Comparable<T>`
itself, and xphp's existing F-bounded generics handle `Sortable<T : Comparable<T>>` end-to-end
(`test/fixture/compile/bounds_f_bounded/`). The asymmetry had no principled basis.

Two further facts undercut the whitelist:

- It only satisfies the **static** bound check. At runtime a class still `implements` the
  contract, so a real interface must exist regardless — the whitelist only spared xphp from
  *seeing* it during the check.
- It can't express the **generic** `Hashable<T>` form. A whitelisted name is a nominal leaf, but
  the natural, type-safe shape is a generic interface whose `equals(T $other)` specializes to the
  implementer's concrete type. That requires a real template, not a whitelist entry.

Collections themselves (Set/Map) are a **separate** project; xphp is a transpiler whose job is to
make generics work, not to carry a domain-specific contract.

## Decision Drivers

- Consistency — value-equality should be expressed like every other contract (e.g. ordering),
  not via a privileged name.
- Keep the transpiler free of domain types — contracts belong to the libraries built on xphp.
- Prefer the shape that gives natural, type-safe ergonomics over a static-only convenience.

## Considered Options

- **Keep the whitelisted `XPHP\Hashable`** (the prior approach) — zero-setup static recognition,
  but a privileged invented name, static-only, and no generic form.
- **Ship a runtime `XPHP\Hashable` interface** — makes the name real, but fixes one contract shape
  for everyone and reverses xphp's pure-transpiler stance.
- **Special-case nothing; value-equality is an ordinary library generic** — a library declares
  `interface Hashable<T> { hashCode(): int|string; equals(T $other): bool; }` and bounds on
  `Set<K : Hashable<K>>`, exactly like `Comparable<T>`.

## Decision Outcome

Chosen: **the transpiler special-cases nothing.** The `Hashable` whitelist entry is removed.
Value-equality, like ordering, is a contract a library defines as an ordinary generic interface
and bounds on with an F-bound:

```php
interface Hashable<T> { public function hashCode(): int|string; public function equals(T $other): bool; }
final class Money implements Hashable { /* hashCode(); equals(Money $o): bool */ }
class Set<K : Hashable<K>> { /* keys on $k->hashCode() */ }
```

This already works with no transpiler change. A generic interface lowers to an **empty marker**
interface (ADR-0004), so the implementing class declares `equals(Money $other)` with its concrete
type under no LSP obligation — identical to how a `Comparable<T>` implementer writes
`compareTo(Money $other)`. The bound is checked nominally and erased (ADR-0005).

Rather than make a privileged name collision-safe (an earlier iteration of this decision), we drop
the privilege entirely. The capability the original ask wanted — a compile-time-checked
value-equality bound — remains fully available, now uniform with the rest of the bound surface.

### Consequences

- Good: one consistent way to express any contract bound; no invented global/namespaced name to
  collide with PHP or libraries; the transpiler carries no domain types.
- Good: the generic form gives implementers natural, concrete-typed `equals(T)` with no LSP
  friction (empty marker), which the whitelist could never express.
- Trade-off: a library/app now declares its own value-equality interface (a few lines) and keeps
  it in its compile set — the exact, trivial cost `Comparable<T>` already pays. Bounding on the
  name without declaring it is no longer possible.
- Trade-off: this is a static, compile-time contract only (bounds emit no runtime code); the
  deduping/keying container is hand-written library code, as before.

### Confirmation

`TypeHierarchy::BUILTIN_TYPES` no longer contains any value-equality entry, and the
`resolveName` special-case is back to matching only the real PHP-native (no-namespace) built-ins.
The library-defined F-bounded-generic pattern is exercised by the existing
`test/fixture/compile/bounds_f_bounded/` (`Comparable<T>` + `Sortable<T : Comparable<T>>` + a
class implementing the bare marker with a concrete same-type method); the transpiler is
indifferent to whether that method returns `int` (`compareTo`) or `bool` (`equals`), since the
marker is empty. Documented in [Caveats](../caveats.md); ordering/value-equality both fall under
[type bounds](../syntax/type-bounds.md) F-bounded recursion.

## More Information

- [ADR-0004](0004-marker-interfaces-for-instanceof.md) — generic templates lower to empty marker
  interfaces (why a concrete `equals(T)` has no LSP obligation).
- [ADR-0005](0005-nominal-erased-bound-checking.md) — nominal, erased bound checking.
- [Caveats](../caveats.md), [Type bounds](../syntax/type-bounds.md).
