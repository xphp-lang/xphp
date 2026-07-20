# 9. PHPStan over the compiled output

- Status: Accepted — 2026-06

## Context and Problem Statement

xphp's own generic checks are nominal and erased
([ADR-0005](0005-nominal-erased-bound-checking.md)) — they validate that
instantiations are well-formed, but they don't analyze the *bodies* of generic
code for value-flow type errors. A method that returns the wrong type inside a
`Box<T>` is invisible to xphp's checks. PHPStan already does exactly that kind of
analysis — but it can't read `.xphp`. Since monomorphization produces concrete
PHP ([ADR-0002](0002-build-time-transpiler.md)), there *is* something PHPStan can
analyze. The question is how to wire a real type-checker over the compiled output
without reinventing it or fighting the user's existing setup.

## Decision Drivers

- Reuse PHPStan's analysis; don't reimplement value-flow type checking.
- Respect the consumer's existing PHPStan configuration (their level, rules,
  extensions, ignores) — one config, not a competing one.
- Map findings back to the `.xphp` the author wrote, not the generated files.
- Keep PHPStan optional and the xphp distribution lean.

## Considered Options

- **Run the consumer's PHPStan over compiled output, one representative
  specialization per template, behind the same gate.**
- **Ship a fixed, xphp-owned PHPStan config.**
- **Bundle PHPStan as a hard dependency** so analysis always runs.
- **Analyze every specialization** of every template.

## Decision Outcome

Chosen: **run the consumer's own PHPStan over the compiled output**, integrated
into `xphp check`. When the generic checks pass, xphp compiles to a throwaway
directory and invokes PHPStan over **one representative specialization per
template** (chosen deterministically), driven by the **consumer's own config**
(auto-detected or pointed at with a flag) via an ephemeral config that `includes:`
it by absolute path. Findings are mapped from the generated file back to the
originating template's `.xphp` declaration line, naming the instantiation that
surfaced them. PHPStan stays a dev-only tool: never bundled in the PHAR, resolved
at runtime, and a **non-failing Warning** if absent — the generic gate still
delivers value on its own.

One representative per template is sound because a body type error erases to
nominal types and therefore manifests identically across every specialization of
that template; analyzing one surfaces the bug once instead of N noisy times. This
also removed an earlier message-normalization de-duplication heuristic.

### Consequences

- Good: real value-flow analysis with zero reimplementation; one config and one CI
  gate cover both generic correctness and body type safety.
- Good: findings point at the author's source; "one representative" keeps the
  report free of duplicate findings and is deterministic for stable CI output.
- Good: lean distribution — PHPStan isn't shipped, and a missing/failed PHPStan
  degrades to a Warning rather than breaking the build.
- Trade-off: a body error that only manifests for *specific* concrete arguments may
  be missed — that's a value-flow bug PHPStan can't attribute to a template line
  anyway.
- Trade-off: a consumer's path-based PHPStan baseline won't match the generated
  paths (identifier/message ignores still work); a config that leans on `%rootDir%`
  resolves it against the ephemeral config's location. Documented limitations.

### Confirmation

The pieces live in [`src/StaticAnalysis/`](../../src/StaticAnalysis/StaticAnalysisGate.php)
— workspace compile, representative selection, the PHPStan runner, and the result
mapper that re-anchors findings to `.xphp`. PHPStan is a dev-only dependency and is
stripped from the PHAR build. See the [errors reference](../errors.md) for the
`phpstan.*` diagnostic codes and the `--no-phpstan` / `--phpstan-bin` /
`--phpstan-config` options.

## Pros and Cons of the Options

### Consumer's PHPStan, one representative

- Good: reuses PHPStan; respects the consumer's rules; one gate; deterministic,
  de-duplicated findings mapped to source.
- Bad: per-argument-only errors can be missed; path-based baselines/`%rootDir%`
  have caveats.

### Fixed xphp-owned config

- Good: simplest to package.
- Bad: two rule sets for one codebase; the consumer can't tune what runs over their
  `.xphp`.

### Bundle PHPStan as a hard dependency

- Good: "always works".
- Bad: bloats the PHAR; couples xphp's release to a PHPStan version; users can't
  upgrade independently.

### Analyze every specialization

- Good: maximal coverage.
- Bad: N duplicate findings per template; needs a fragile message-dedup heuristic;
  slower.

## More Information

- [Errors and diagnostics](../errors.md) — `phpstan.*` codes and the CLI options.
- [ADR-0005](0005-nominal-erased-bound-checking.md) (the gap this closes),
  [ADR-0007](0007-xphp-check-gate.md) and
  [ADR-0008](0008-collect-or-throw-diagnostic-seam.md) (the gate and seam it builds on).
