# 10. Undeclared-type and arity validation

- Status: Accepted — 2026-06

## Context and Problem Statement

Two authoring mistakes used to pass silently. A generic member that names a type
parameter the template never declared — `interface Foo<Z> { add(T $x); }`, where
`T` is a typo for `Z` — compiled to a reference to a non-existent class (`\App\T`)
with no error. And instantiating with more type arguments than declared —
`Box::<int, string>` for a one-parameter `Box` — silently dropped the extras.
Both produce wrong output from plainly-wrong input. The hard part of the first is
that, in erased nominal terms, a stray `T` is indistinguishable from a reference
to a real class named `T` — xphp has no whole-program symbol table to tell them
apart.

## Decision Drivers

- Catch these mistakes at `check`/`compile` time, not at runtime.
- Don't reject valid code, especially references to real types.
- Keep it inside xphp's erased model (no full type resolution — that's PHPStan's
  job, [ADR-0009](0009-phpstan-over-compiled-output.md)).

## Considered Options

For undeclared-type detection:
- **A broad structural rule** — flag any bare, unqualified, single-segment type
  name used inside a generic context that is neither a declared type parameter,
  a built-in, nor imported, and that resolves to no type the compiler can see.
- **A narrow heuristic** — only flag single-letter names.
- **Defer entirely to PHPStan.**

For arity: **report over-arity** vs **keep truncating silently**.

## Decision Outcome

Chosen: the **broad structural rule**, plus **reporting over-arity**. A bare,
unqualified, single-segment name inside a generic context that isn't a declared
parameter, a scalar/built-in, or brought in by a `use` import — and that resolves
to nothing the compiler knows — is reported as `xphp.undeclared_type`. Fully
qualified names and `use`-imported names are the escape hatch and are never
flagged. Supplying more type arguments than a template declares is reported as
`xphp.too_many_type_arguments` instead of being truncated. Both reuse the
collect-or-throw seam ([ADR-0008](0008-collect-or-throw-diagnostic-seam.md)), so
they fail `compile` and are collected by `check`.

### Consequences

- Good: the common typo and the over-arity mistake now fail fast with a clear
  message and `file:line`, instead of emitting broken or silently-wrong code.
- Good: a dry-run of the broad rule over the entire existing test corpus produced
  zero false positives on valid code.
- Trade-off (accepted): a real class in the *same namespace* that lives in a plain
  `.php` file (which `check` doesn't scan) and is referenced **without** a `use`
  will be flagged, because the compiler can't see it and it looks like a stray
  parameter. The remedy is to `use`/fully-qualify it — both silence the check. The
  alternative (a narrow single-letter heuristic) would miss real multi-letter
  typos, so the broad rule with a documented escape hatch was preferred.

### Confirmation

The detection runs as a validation phase
([`UndeclaredTypeParameterValidator`](../../src/Transpiler/Monomorphize/UndeclaredTypeParameterValidator.php));
the arity check lives in the registry's argument padding. Both `xphp.undeclared_type`
and `xphp.too_many_type_arguments` are in the [errors reference](../errors.md).

## Pros and Cons of the Options

### Broad structural rule

- Good: catches real typos in members, bounds, and defaults; zero false positives
  on the existing corpus; cheap and resolution-free.
- Bad: the accepted false positive above (same-namespace plain-`.php` class, no
  `use`).

### Narrow single-letter heuristic

- Good: even lower false-positive risk.
- Bad: misses multi-letter undeclared names; users have to learn the heuristic.

### Defer to PHPStan

- Good: full name resolution.
- Bad: PHPStan runs on generated PHP, can't see the generic context, and isn't
  always installed; the mistake should fail the fast, resolution-free gate too.

## More Information

- [Errors and diagnostics](../errors.md).
- [ADR-0009](0009-phpstan-over-compiled-output.md) — the resolution-aware layer this
  intentionally stops short of.
