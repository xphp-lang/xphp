# 8. The collect-or-throw diagnostic seam

- Status: Accepted — 2026-06

## Context and Problem Statement

`xphp check` ([ADR-0007](0007-xphp-check-gate.md)) needs to *collect* every
validation error and keep going; `xphp compile` needs to *throw* on the first
one and stay byte-identical to its long-standing behavior (a large body of tests
pins the exact exception messages, down to substrings like `(position 1)`). Both
must run the *same* validation logic — duplicating it would guarantee drift
between what `check` reports and what `compile` enforces. How should one set of
validators serve both modes?

## Decision Drivers

- A single source of truth for validation and for each error message.
- `compile` stays fail-fast and byte-identical (no message drift).
- Minimal, low-ceremony change — no heavyweight abstraction threaded everywhere.

## Considered Options

- **An optional trailing `?DiagnosticCollector` parameter** on the validation
  methods: absent ⇒ throw (as before); present ⇒ append a diagnostic and continue.
- **A `DiagnosticSink` interface** with `Throwing` and `Collecting` implementations
  injected through the stack.
- **Fork the validators** into separate compile and check code paths.

## Decision Outcome

Chosen: an **optional `?DiagnosticCollector` parameter**. Validation methods take
a nullable collector as their last argument. When it's absent (the `compile`
path), they throw exactly as before. When it's present (the `check` path), each
violation is appended as a structured diagnostic and validation continues — across
every validation phase — so all problems surface in one run. The user-facing text
comes from a single shared message builder used by both the `throw` and the
diagnostic, so the two can never diverge. The collector is mutable by design —
it's the one sink threaded through the validation phases.

### Consequences

- Good: one validator, one message string, two behaviors; `compile` output is
  provably byte-identical and `check` collects — verified by tests that assert both
  from the same input.
- Good: tiny surface area — a nullable parameter, no interface hierarchy or DI.
- Good: in `check` mode every validation phase runs unconditionally — there is no
  early return between phases — so a single run yields effectively a flat list of
  all diagnostics across phases, each with its location. Two deliberate exceptions:
  the inner-variance pass skips templates the variance-position pass already flagged
  (to avoid double-reporting the same issue), and a generated-name hash collision is
  still thrown rather than collected (it's intentionally outside the seam).
- Trade-off: the no-collector default must be preserved at every call site, or that
  site silently reverts to fail-fast; this is covered by tests.

### Confirmation

The seam threads through the [`Registry`](../../src/Transpiler/Monomorphize/Registry.php)
and the validators; the diagnostic model is in
[`src/Diagnostics/`](../../src/Diagnostics/DiagnosticCollector.php). Tests assert
both the byte-identical throw path and the collect path for the same fixtures.

## Pros and Cons of the Options

### Optional `?DiagnosticCollector` parameter

- Good: one code path; shared message; minimal ceremony; byte-identical compile;
  in `check` mode all phases run and collect into one flat report.
- Bad: a per-call-site convention to uphold — every call site must preserve the
  no-collector default or it silently reverts to fail-fast.

### `DiagnosticSink` interface

- Good: clean polymorphism.
- Bad: two implementations + injection through the stack for only two call modes;
  overkill.

### Forked validators

- Good: each path is self-contained.
- Bad: duplicated logic; near-certain message drift between check and compile.

## More Information

- [ADR-0007](0007-xphp-check-gate.md) — the gate this enables.
- [ADR-0009](0009-phpstan-over-compiled-output.md) and
  [ADR-0010](0010-undeclared-type-and-arity-validation.md) reuse the same seam.
