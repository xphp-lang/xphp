# 23. Type-alias syntax is the declaration form `type Name<…> = Body`

- Status: Accepted — 2026-07

## Context and Problem Statement

xphp adds type aliases — a name for a type, expanded at compile time (see
[type aliases](../syntax/type-aliases.md)). A first-class goal is that an alias may be
**generic** (`type Pair<A, B> = Map<A, List<B>>`), not only a name for a fixed type.

PHP itself has a live but unsettled proposal, [PHP RFC: Type
Aliases](https://wiki.php.net/rfc/typed-aliases), which uses an *import* form
(`use type int|float as Number;`) and explicitly lists parameterized (generic) aliases
under "Future Scope" — so there is no PHP-blessed syntax for the generic case xphp needs.
xphp must therefore choose a surface, ideally one that stays forward-compatible with where
PHP is most likely to land.

## Decision Drivers

- **Must express generic aliases**, since that is a primary goal.
- Forward-compatibility with a plausible future PHP syntax.
- Fit xphp's existing angle-bracket surface (`Foo<T>`, the `::<>` turbofish).
- Correctness first: no silent miscompile; an alias must lower to exactly what its body
  would have.

## Considered Options

- **A — declaration form `type Name<…> = Body;`** (with the non-generic case being the
  zero-parameter `type Name = Body;`). The form used by TypeScript, Rust, Scala, and — most
  relevantly — **Hack**, PHP's closest relative.
- **B — import form `use type Body as Name;`** (PHP's current RFC).
- **C — a distinct keyword** (`typedef` / `typealias`).
- **D — a runtime, autoloadable alias symbol** (an alias that exists at runtime and via
  reflection), rather than a pure compile-time substitution.

## Decision Outcome

Chosen: **A — the declaration form `type Name<…> = Body`, resolved as a compile-time
substitution.**

The import form (B) is eliminated by the generic requirement: `use type Body as Name` has
no place to put parameters on `Name` (`use type Map<A, List<B>> as Pair<A, B>` is
ambiguous), which is almost certainly why PHP deferred generic aliases. The declaration
form is the *only* one of the two that expresses both cases with a single rule, and it is
what every language that supports generic aliases uses. Hack — the closest precedent to
xphp's situation — spells it exactly `type Name<T> = …;`. It also fits xphp's own
angle-bracket surface. A distinct keyword (C) buys nothing over `type` and is further from
that precedent.

Aliases are a **compile-time substitution** with no runtime existence (not option D). The
long-standing blocker for PHP here — how to autoload/define a runtime alias symbol — simply
does not arise for xphp: it is a whole-program, build-time transpiler
([ADR-0002](0002-build-time-transpiler.md)), so an alias is expanded before specialization
and needs no runtime identity.

### Consequences

- Good: one grammar covers generic and non-generic aliases; it matches the cross-language
  and Hack consensus and xphp's existing syntax; expansion reuses the monomorphizer with no
  new emission path or runtime cost.
- Trade-off: for the *generic* case xphp defines surface ahead of PHP (which deferred it),
  a bet on the declaration-form consensus. The non-generic import form (`use type … as`)
  could be added later as a parity synonym without disturbing this decision.
- Trade-off: the initial scope was single-head / union / nullable bodies, **file-local** (an
  alias is scoped to its file like a `use` alias, by design — see option D below), delivering a
  safe subset with the richer bodies as later work. (That later work landed in v0.4.0:
  intersection, DNF, and closure-signature bodies are now supported; compound-in-non-slot
  positions remain rejected. See the [caveat](../caveats.md#type-alias-body-and-position-limits)
  and [roadmap](../roadmap.md) for the current state.)

### Confirmation

The scanner recognizes `type Name[<…>] = SingleHead;` and strips it; expansion is exercised
end to end by `test/fixture/compile/type_aliases/` (a runtime fixture that executes the
compiled output and asserts no alias name survives) and the `TypeAliasIntegrationTest`
cases. Every rejection carries a stable code (`xphp.alias_cycle`, `xphp.alias_arity`,
`xphp.alias_class_collision`, `xphp.alias_duplicate`, `xphp.alias_unsupported_body`) and is
verified in both `compile` and `check`.

## Pros and Cons of the Options

### A — declaration form `type Name<…> = Body`

- Good: expresses generic and non-generic aliases with one rule; matches Hack + TS + Rust +
  Scala; fits xphp's angle-bracket surface.
- Bad: leads PHP for the generic case (PHP has only the import form, and only for
  non-generic aliases so far).

### B — import form `use type Body as Name`

- Good: matches PHP's current RFC for the non-generic case; forward-compatible there.
- Bad: cannot carry type parameters, so it cannot express generic aliases — the primary
  goal.

### C — distinct keyword (`typedef` / `typealias`)

- Good: unambiguous keyword.
- Bad: no advantage over `type`; further from the Hack precedent and the cross-language norm.

### D — runtime / autoloadable alias symbol

- Good: reflection and cross-file use "for free".
- Bad: imports PHP's unsolved autoloading/definition problem for no benefit — xphp expands
  aliases at build time and needs no runtime symbol.

## More Information

- [Type aliases](../syntax/type-aliases.md) and the
  [file-local / single-head caveat](../caveats.md#type-alias-body-and-position-limits).
- [ADR-0001](0001-monomorphization-over-type-erasure.md) — monomorphization;
  [ADR-0002](0002-build-time-transpiler.md) — build-time transpiler (why a runtime alias
  symbol is unnecessary).
- [PHP RFC: Type Aliases](https://wiki.php.net/rfc/typed-aliases) (import form; generic
  aliases in Future Scope); [PHP RFC: Bound-erased generic
  types](https://wiki.php.net/rfc/bound_erased_generic_types) (the `Foo<T>` surface xphp
  tracks). Hack spells the declaration form `type Name<T> = …;` (and `newtype`).
