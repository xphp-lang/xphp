# 6. Bounded specialization with a hard depth cap

- Status: Accepted — 2026-05

## Context and Problem Statement

Specialization is a fixed-point process: specializing `wrap<T>(): Box<T>` for
`int` introduces a new `Box<int>` instantiation, which must itself be
specialized, which may introduce more. For ordinary code this converges quickly.
But a self-referential or combinatorial template (e.g. one whose specialization
keeps producing a strictly more-nested instantiation) can drive the loop forever.
The compiler needs a guaranteed-terminating story.

## Decision Drivers

- The compiler must always terminate, even on pathological or malicious input.
- A real bug or runaway should fail loudly and quickly, not hang.
- Don't penalize legitimate (finite, possibly deep) generic code.

## Considered Options

- **A hard depth cap that aborts** the whole run when nesting exceeds a fixed
  limit.
- **No cap**, trusting templates to converge.
- **Report the cap as a collectable diagnostic** and keep going.

## Decision Outcome

Chosen: a **hard depth cap that aborts**. The fixed-point specialization loop
tracks nesting depth and aborts the `compile` run with a clear message once it
exceeds a fixed limit (16 levels of nested specialization,
`Compiler::MAX_SPECIALIZATION_DEPTH`) set well above realistic generic nesting.
This is treated as a runaway-input guard — a distinct error class from
user-facing generic errors like a bound violation. The cap applies to `compile`
only: `check` validates without ever specializing, so it never enters the loop.

### Consequences

- Good: termination is guaranteed; a spiraling template fails fast with an
  actionable message instead of hanging.
- Good: the limit sits well above realistic nesting, so ordinary deep generics
  don't hit it.
- Trade-off: a genuinely legitimate but extremely deep instantiation would be
  refused; the workaround is to break it into intermediate templates.
- Notable: unlike most checks, the depth cap is **not** routed through the
  collect-or-throw diagnostic seam
  ([ADR-0008](0008-collect-or-throw-diagnostic-seam.md)). If it were merely
  collected and the loop continued, the next iteration would hit the cap again
  forever — so it must abort.

### Confirmation

The cap is `Compiler::MAX_SPECIALIZATION_DEPTH` (16), enforced in the fixed-point
loop in [`Compiler::compile()`](../../src/Transpiler/Monomorphize/Compiler.php); a
fixture exercises a self-referential template that trips it.

## Pros and Cons of the Options

### Hard cap, abort

- Good: guaranteed termination; fast, clear failure.
- Bad: a (rare) legitimate very-deep template is refused.

### No cap

- Good: never refuses anything.
- Bad: a single bad template can hang the compiler indefinitely.

### Collect-and-continue

- Good: consistent with the other checks' reporting model.
- Bad: doesn't actually stop the loop — it would re-trip endlessly; a contradiction
  for a non-terminating condition.

## More Information

- [ADR-0001](0001-monomorphization-over-type-erasure.md) — why a specialization
  loop exists at all.
- [Caveats](../caveats.md).
