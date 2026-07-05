# Architecture Decision Records

This directory records the **architecturally significant** decisions behind
xphp — the choices that are expensive to reverse and that shape everything
built on top of them: how generics are implemented, what the compiler emits,
how the `check` gate and the PHPStan layer work, and the quality bar the
codebase holds itself to.

Each record states the problem, the options that were on the table, the option
chosen and why, and the consequences (the good and the trade-offs). They are
written after the fact, from the project's history, so they read as a map of
*why xphp is shaped the way it is* — useful whether you're evaluating xphp,
contributing to it, or just curious.

We use the [MADR](https://adr.github.io/madr/) format. New significant decisions
should be added here as a new numbered file; copy
[`0000-adr-template.md`](0000-adr-template.md) to start.

| # | Decision | Status |
|---|----------|--------|
| [0001](0001-monomorphization-over-type-erasure.md) | Monomorphization over type erasure | Accepted |
| [0002](0002-build-time-transpiler.md) | A build-time transpiler that emits plain PHP | Accepted |
| [0003](0003-rfc-aligned-turbofish-syntax.md) | RFC-aligned turbofish surface syntax | Accepted |
| [0004](0004-marker-interfaces-for-instanceof.md) | Marker interfaces for `instanceof` across specializations | Accepted |
| [0005](0005-nominal-erased-bound-checking.md) | Nominal, erased bound checking | Accepted |
| [0006](0006-bounded-specialization-depth-cap.md) | Bounded specialization with a hard depth cap | Accepted |
| [0007](0007-xphp-check-gate.md) | The `xphp check` validate-only gate | Accepted |
| [0008](0008-collect-or-throw-diagnostic-seam.md) | The collect-or-throw diagnostic seam | Accepted |
| [0009](0009-phpstan-over-compiled-output.md) | PHPStan over the compiled output | Accepted |
| [0010](0010-undeclared-type-and-arity-validation.md) | Undeclared-type and arity validation | Accepted |
| [0011](0011-phar-distribution.md) | PHAR distribution via Humbug Box | Accepted |
| [0012](0012-engineering-quality-bar.md) | The engineering quality bar | Accepted |
| [0013](0013-typed-constructor-parameters-on-variant-classes.md) | Typed constructor parameters on variant classes | Accepted |
| [0014](0014-variance-markers-are-class-level-only.md) | Variance markers are class-level only | Accepted |
| [0015](0015-variance-markers-on-private-properties.md) | Variance markers on private properties | Accepted |
| [0016](0016-no-special-cased-value-equality-bound.md) | No special-cased value-equality bound (use ordinary generics) | Accepted |
| [0017](0017-config-manifest-source-resolution.md) | Config-manifest, multi-root source resolution (`xphp.json`) | Accepted |
| [0018](0018-grounding-method-generic-bounds-on-enclosing-type-parameters.md) | Grounding a method-generic bound that references an enclosing class type parameter | Accepted |
| [0019](0019-trait-members-are-not-modeled-in-the-type-hierarchy.md) | Trait-imported members are not modeled in the type hierarchy | Accepted |
| [0020](0020-diagnose-and-restructure-self-reintroducing-specialization.md) | Diagnose and restructure self-reintroducing specialization (erased seam deferred) | Accepted |
| [0021](0021-compile-runs-the-check-gate-by-default.md) | `xphp compile` runs the check gate by default | Accepted |
