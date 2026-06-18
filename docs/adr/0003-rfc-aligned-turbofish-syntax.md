# 3. RFC-aligned turbofish surface syntax

- Status: Accepted — 2026-06

## Context and Problem Statement

xphp adds generic *syntax* to PHP, and there is an active PHP RFC for
bound-erased generic types. xphp's runtime model diverges from that RFC
(monomorphization vs erasure — [ADR-0001](0001-monomorphization-over-type-erasure.md)),
but the *surface syntax* is a separate choice. If xphp invents its own spelling
for instantiation and bounds, then `.xphp` source becomes a dead end the day PHP
ships native generics. If it tracks the RFC, today's source has a path to a
future native runtime.

The specific tension is at call/`new` sites: `new Box<Plastic>()` is ambiguous
with the comparison/`<` grammar, which is exactly why the RFC uses *turbofish*
`Name::<...>`.

## Decision Drivers

- Forward compatibility: `.xphp` source should stay valid against a future native
  PHP generics runtime.
- No grammar ambiguity at call sites.
- Familiarity: users who know the RFC should recognize xphp and vice versa.

## Considered Options

- **Track the RFC syntax** — turbofish `Name::<...>` at call/`new` sites, bare
  `<...>` at declarations and type-hint positions, `:` for bounds.
- **Invent a bespoke syntax** optimized purely for the transpiler.
- **Accept both bare and turbofish at call sites** for convenience.

## Decision Outcome

Chosen: **track the RFC syntax**. Declarations and type-hints use bare angle
brackets (`class Box<T>`, `public T $item`); call and `new` sites require
turbofish with byte-level adjacency (`Box::<Plastic>`, `identity::<int>(…)`); a
parenless `new Box<Plastic>;` form is rejected to avoid silent specialization.
The whitespace-sensitive lookahead means `Foo:: <T>` is *not* a turbofish.

### Consequences

- Good: `.xphp` source is a subset of the prospective native syntax — migration is
  mechanical, not a rewrite.
- Good: no ambiguity with the `<` operator at call sites; the parser's intent is
  unmistakable.
- Trade-off: the turbofish `::<>` is more verbose at call sites than bare `<>`, and
  is a hard requirement (no dual-accept), which was a one-time rewrite of existing
  source.

### Confirmation

Surface syntax is handled in the parser
([`XphpSourceParser`](../../src/Transpiler/Monomorphize/XphpSourceParser.php)); the
[Syntax tour](../syntax/index.md) documents every position, and the divergence
note lives in [the docs index](../index.md) and [comparison](../guides/comparison.md).

## Pros and Cons of the Options

### Track the RFC syntax

- Good: forward-compatible; unambiguous; familiar to RFC readers.
- Bad: more verbose call sites; a hard switch with no deprecation window.

### Bespoke syntax

- Good: could be terser or transpiler-convenient.
- Bad: guarantees a future migration; unfamiliar; no shared mental model with PHP.

### Dual-accept bare + turbofish

- Good: lenient for authors.
- Bad: undermines the forward-compat promise (bare call sites won't run on a future
  native runtime); two idioms confuse tooling and learners.

## More Information

- [PHP RFC: bound-erased generic types](https://wiki.php.net/rfc/bound_erased_generic_types).
- [Syntax tour](../syntax/index.md), [Type bounds](../syntax/type-bounds.md).
