# 7. The `xphp check` validate-only gate

- Status: Accepted — 2026-06

## Context and Problem Statement

The only way to validate generic code used to be to `compile` it. Compilation
threw on the *first* generic error, as a bare exception with no `file:line`, and
produced output as a side effect. For CI and editors that's a poor fit: you fix
one error, recompile, find the next, repeat; there's no machine-readable result
and no way to "just check" without emitting `dist/`. xphp needs a first-class
validation entry point.

## Decision Drivers

- Report *all* problems in one run, each with a precise `file:line`.
- No side effects — a pure gate that writes nothing.
- Machine-readable, CI- and IDE-friendly output and exit codes.
- Resilience: one unparseable file shouldn't blind the check to the rest.

## Considered Options

- **A dedicated `xphp check` command** — validate-only, collect-all, structured
  diagnostics, multiple renderers, structured exit codes.
- **Keep `compile` fail-fast, add a `--collect-errors` flag** to it.
- **Emit a diagnostics JSON file** from `compile` and let CI parse it.

## Decision Outcome

Chosen: a **dedicated `xphp check` command**. It runs the validation phases
without specializing or emitting anything, gathers every diagnostic in a single
run (each a structured record with a stable code, severity, and `file:line`),
renders them as `text`, `json`, or `github` (PR annotations), and returns exit
**0** (clean), **1** (≥1 error), or **2** (operational failure — bad source dir
or unknown format). Each file is parsed in isolation, so a syntax error in one is
reported and the rest are still checked.

### Consequences

- Good: one CI step surfaces every problem at once, with locations; the `github`
  renderer puts findings inline on PRs; the `json` renderer feeds tooling/IDEs.
- Good: stable diagnostic codes (e.g. `xphp.bound_violation`) give a contract for
  tooling and a searchable [errors reference](../errors.md).
- Good: it's the natural place to add more analyses behind one gate — see
  [ADR-0009](0009-phpstan-over-compiled-output.md).
- Trade-off: there are now two entry points (`compile` and `check`) whose
  validation must agree; that's exactly what the shared seam in
  [ADR-0008](0008-collect-or-throw-diagnostic-seam.md) guarantees.

### Confirmation

[`CheckCommand`](../../src/Console/Command/CheckCommand.php) and the validate-only
path [`Compiler::check()`](../../src/Transpiler/Monomorphize/Compiler.php); the
renderers in [`src/Diagnostics/Renderer/`](../../src/Diagnostics/Renderer/). The
[errors reference](../errors.md) documents the codes, formats, and exit codes.

## Pros and Cons of the Options

### Dedicated `check` command

- Good: collect-all; no side effects; structured codes/formats/exit codes; per-file
  resilience; extensible.
- Bad: a second entry point to keep behaviorally consistent with `compile`.

### `compile --collect-errors`

- Good: one command.
- Bad: still emits output; conflates "validate" with "build"; risks forking the
  validator logic.

### Emit a JSON file

- Good: machine-readable.
- Bad: pushes parsing/format burden to every CI; no inline PR annotations; awkward
  for interactive use.

## More Information

- [Errors and diagnostics](../errors.md).
- [ADR-0008](0008-collect-or-throw-diagnostic-seam.md) — how `check` and `compile`
  share one validator.
