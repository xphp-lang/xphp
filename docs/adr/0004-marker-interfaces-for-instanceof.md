# 4. Marker interfaces for `instanceof` across specializations

- Status: Accepted — 2026-05

## Context and Problem Statement

Monomorphization turns `Box<Plastic>` and `Box<Metal>` into two unrelated
classes with mangled, instantiation-specific names. But user code naturally
wants to ask "is this *a Box*, of whatever element?" — `$x instanceof Box`. After
specialization there is no longer a class literally named `Box` for that check to
target, and the two specializations share no common supertype. Something has to
preserve the template's identity at runtime.

## Decision Drivers

- `$x instanceof App\Containers\Box` should be true for *any* `Box<…>`
  specialization, without the call site knowing the concrete arguments.
- The mechanism must be ordinary PHP (no runtime support code).
- It must not break PHP's autoload/LSP rules for the generated classes.

## Considered Options

- **Marker interface** — replace the template with an empty interface at its
  original FQN; every specialization implements/extends it.
- **A shared abstract base class** the specializations extend.
- **Drop cross-specialization `instanceof`** and offer only concrete checks.

## Decision Outcome

Chosen: a **marker interface**. Each generic class/interface template is replaced
in the output by an empty interface at the template's original fully-qualified
name; every specialization `implements` (for classes) or `extends` (for
interfaces) that marker. So `App\Containers\Box` survives as a real interface,
and `$x instanceof App\Containers\Box` is true for every specialization. Generic
*traits* are removed entirely, since PHP cannot `instanceof` a trait.

### Consequences

- Good: cross-specialization `instanceof` works with plain PHP and zero runtime
  code; the template name stays meaningful.
- Good: an interface (not a base class) keeps specializations free to have their
  own real parents and avoids single-inheritance conflicts.
- Trade-off: the original template name becomes an empty interface in the output,
  which can surprise someone reading the generated code without context.
- Trade-off: the variance subtype edges between specializations are a separate,
  related concern (covariant/contravariant `extends` links).

### Confirmation

The replacement happens in the call-site rewriter
([`CallSiteRewriter`](../../src/Transpiler/Monomorphize/CallSiteRewriter.php)); the
specializer adds the `implements`/`extends` back-link. See
[Runtime semantics](../guides/runtime-semantics.md).

## Pros and Cons of the Options

### Marker interface

- Good: any-arg `instanceof`; interface composes cleanly with existing parents.
- Bad: template name is an empty interface in output; traits can't participate.

### Shared abstract base class

- Good: also enables `instanceof`.
- Bad: consumes the single allowed parent class, conflicting with specializations
  that already extend something.

### Concrete-only `instanceof`

- Good: nothing to generate.
- Bad: loses a natural, expected capability — you couldn't ask "is this a Box?".

## More Information

- [Variance](../syntax/variance.md) — the subtype edges between specializations.
- [ADR-0001](0001-monomorphization-over-type-erasure.md).
