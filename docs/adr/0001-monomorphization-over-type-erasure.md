# 1. Monomorphization over type erasure

- Status: Accepted — 2026-05

## Context and Problem Statement

PHP has no generics. xphp adds them as a source-level language feature, which
forces a foundational question: what should a generic *become* at runtime? Two
families of answer dominate the prior art. **Erasure** (Java, the PHP
bound-erased-generics RFC) keeps generic identity at compile time only — at
runtime a `Collection<User>` is just a `Collection`, and the type argument is
gone. **Monomorphization** (C++ templates, Rust) stamps out a distinct concrete
type per instantiation — `Collection<User>` becomes a real `Collection_User`
class with `User` baked into every signature.

The choice is effectively irreversible: it defines what the generated code is,
what reflection sees, whether `instanceof T` can work, and how runtime type
errors surface. Everything else in xphp is built on top of it.

## Decision Drivers

- **Zero runtime penalty** — the result should run exactly like hand-written PHP,
  with no dispatcher, reflection, or custom runtime in the hot path.
- **Runtime safety that doesn't lie** — passing the wrong type should raise a real
  PHP `TypeError` at the boundary, not silently degrade to a bag of `mixed`.
- **Progressive enhancement** — output must be ordinary PHP a team can drop into
  an existing project with no new runtime dependency.
- **Reflection fidelity** — tooling, serializers, and DI containers should see the
  concrete type.

## Considered Options

- **Monomorphization** — one specialized class per instantiation.
- **Type erasure** — generic identity at compile time only; one runtime class.
- **Hybrid** — erase the class, carry type identity in a metadata sidecar.

## Decision Outcome

Chosen: **monomorphization**, because it is the only option that delivers all
four drivers at once. `class Box<T>` plus `new Box::<Plastic>()` compiles to a
concrete `Box`-specialization whose members are typed with `Plastic`; PHP's own
type system then enforces them, reflection reports them, and `opcache` optimizes
the result like any other class.

### Consequences

- Good: the runtime sees only vanilla PHP — no dependency, no overhead, native
  `TypeError` enforcement, accurate reflection.
- Good: reified types fall out for free — `new T(...)`, `T::class`, and
  `instanceof` against a concrete arg work because `T` literally *is* the concrete
  class in the generated code.
- Trade-off: each distinct instantiation emits a separate class file, so the
  compiled output grows with the number of instantiations.
- Trade-off: compilation does real work (a fixed-point specialization loop), which
  must be bounded against pathological inputs — see
  [ADR-0006](0006-bounded-specialization-depth-cap.md).
- Trade-off: runtime semantics diverge from the erasure-based PHP RFC. The surface
  syntax is kept compatible (see [ADR-0003](0003-rfc-aligned-turbofish-syntax.md)),
  but the runtime models differ by design.

### Confirmation

The pipeline that performs specialization lives in
[`src/Transpiler/Monomorphize/`](../../src/Transpiler/Monomorphize/Compiler.php);
end-to-end fixtures compile real `.xphp` and snapshot the emitted PHP. See
[How it works](../guides/how-it-works.md) and
[Runtime semantics](../guides/runtime-semantics.md).

## Pros and Cons of the Options

### Monomorphization

- Good: zero-overhead, reified, reflection-accurate, no runtime dependency.
- Bad: larger output; non-trivial compile-time work; runtime diverges from the RFC.

### Type erasure

- Good: tiny output, trivial compile, closest to a future native-PHP runtime.
- Bad: `instanceof T` / `T::class` impossible; reflection sees a raw `Collection`;
  composition mistakes surface late.

### Hybrid (metadata sidecar)

- Good: smaller output than full monomorphization while keeping some identity.
- Bad: two models to keep in sync; a metadata registry to persist and invalidate;
  runtime/metadata gaps to bridge.

## More Information

- [PHP RFC: bound-erased generic types](https://wiki.php.net/rfc/bound_erased_generic_types)
  — the erasure model xphp deliberately diverges from at runtime.
- An earlier prototype implemented generics via runtime attributes and reflection;
  it was fully superseded by the monomorphization compiler, which also let the
  project drop its reflection-library dependencies.
- [ADR-0002](0002-build-time-transpiler.md) — the build-time transpiler model that
  makes this practical.
