# 12. The engineering quality bar

- Status: Accepted — 2026-06

## Context and Problem Statement

A generics compiler is a correctness-critical tool: a bug doesn't just misbehave,
it emits wrong code into someone else's project. That argues for an unusually high
bar on the compiler's own codebase and its tests. But strictness has costs —
slower CI, more findings to triage, more test ceremony — so the bar has to be a
deliberate, documented choice rather than an accident.

## Decision Drivers

- High confidence that the compiler itself is correct.
- Tests that actually fail when behavior regresses (not just line coverage).
- Stable, modern target platform without blocking on bleeding-edge syntax.
- Fast, deterministic CI signal.

## Considered Options

- **A high, enforced bar**: PHPStan at the strictest level on `src/`, Infection
  mutation testing gated on a high MSI, fixture+snapshot end-to-end tests, a fixed
  minimum PHP with newer-syntax tests isolated into their own group/runtime.
- **A conventional bar**: a mid PHPStan level, line-coverage thresholds, ad-hoc
  integration tests.

## Decision Outcome

Chosen: the **high, enforced bar**, adopted progressively.

- **PHPStan at the strictest level** over `src/`, reached by stepping the level up
  and clearing findings at each stop rather than baselining them away.
- **Mutation testing (Infection)** gated on a high MSI; every surviving mutant is
  either killed with a new test or annotated inline as a genuinely-equivalent
  mutant with a one-line reason, so the report only ever shows real gaps.
- **Fixture + snapshot tests**: a feature compiles a `.xphp` source tree and its
  emitted PHP is snapshotted; deterministic generated-name hashes are normalized to
  stable placeholders so a role-swap regression still shows up as a diff.
- **A fixed minimum PHP** (the supported runtime) with tests that exercise
  newer-PHP syntax isolated into their own group, run on a dedicated runtime and
  skipped elsewhere — so the default suite stays fast and the production parser
  stays on the supported version.

### Consequences

- Good: high confidence in the compiler; tests bite on real regressions; CI signal
  is fast (heavy/optional suites are split out) and the analysis target is stable.
- Good: the discipline is self-documenting — equivalent-mutant annotations and the
  curated ignore set explain *why* something isn't tested, instead of hiding it.
- Trade-off: stricter analysis and mutation runs are slower and demand more effort
  per change; newer-syntax features must wait for the dedicated runtime to cover
  them.

### Confirmation

The PHPStan configuration, the Infection configuration with its curated
equivalent-mutant ignore set, the snapshot/fixture test support, and the CI
workflow split (default suite, newer-syntax group, the optional analysis-pass
tests, and the mutation job) collectively enforce this. See
[CONTRIBUTING](../../CONTRIBUTING.md) for how to run each locally.

## Pros and Cons of the Options

### High, enforced bar

- Good: maximal confidence; regression-sensitive tests; fast, deterministic CI;
  self-documenting discipline.
- Bad: slower analysis/mutation; more upfront effort; newer syntax gated on a
  dedicated runtime.

### Conventional bar

- Good: cheaper and faster to satisfy.
- Bad: line coverage hides untested logic; a mid analysis level lets subtler bugs
  through — unacceptable risk for a code generator.

## More Information

- [CONTRIBUTING](../../CONTRIBUTING.md) — the test/lint targets and the group split.
- [ADR-0001](0001-monomorphization-over-type-erasure.md) — why correctness of the
  generated code matters so much.
