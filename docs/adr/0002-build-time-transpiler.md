# 2. A build-time transpiler that emits plain PHP

- Status: Accepted — 2026-05

## Context and Problem Statement

Given monomorphization ([ADR-0001](0001-monomorphization-over-type-erasure.md)),
*when* and *how* does the specialization happen? It could run at runtime (a
library that intercepts class loading and generates specializations on demand),
or ahead of time (a compiler that reads source and writes source). The answer
determines what a consuming project depends on, how the output interacts with
`opcache` and static analyzers, and whether xphp is something you *run* or
something you *build with*.

## Decision Drivers

- No runtime dependency on xphp in the shipped application.
- Output that existing PHP tooling (opcache, PHPStan, IDEs) understands natively.
- A clear, debuggable artifact — you can open and read the generated PHP.
- Deterministic, cacheable builds.

## Considered Options

- **Build-time transpiler** — `xphp compile <src> <dist> <cache>` reads `.xphp`
  and writes plain `.php`.
- **Runtime library** — generate specializations lazily during autoloading.
- **A PHP extension** — implement generics in C in the engine.

## Decision Outcome

Chosen: a **build-time transpiler**. `.xphp` files are compiled offline into
ordinary PHP — rewritten user files under a `dist/` directory and generated
specialized classes under a cache directory — with the generic sugar fully
resolved away. The application ships and runs the plain PHP; xphp is a
development/CI-time tool, not a runtime.

### Consequences

- Good: the runtime has zero xphp dependency; the output is normal PHP that
  `opcache`, PHPStan, and IDEs handle with no special support.
- Good: the generated code is inspectable and debuggable — no magic at runtime.
- Good: it makes the PHPStan-over-output layer
  ([ADR-0009](0009-phpstan-over-compiled-output.md)) possible at all, since there
  is concrete PHP to analyze.
- Trade-off: a build step is required before the code runs (and before stack
  traces/line numbers map cleanly — source maps are roadmap work).
- Trade-off: analysis and tooling see only what was actually instantiated and
  compiled, not the templates directly.

### Confirmation

The CLI entry point is [`bin/xphp`](../../bin/xphp); the compile command is
[`CompileCommand`](../../src/Console/Command/CompileCommand.php). See
[Getting started](../getting-started.md).

## Pros and Cons of the Options

### Build-time transpiler

- Good: no runtime dep; tooling-native output; inspectable; cacheable.
- Bad: needs a build step; line/stack mapping back to `.xphp` is extra work.

### Runtime library

- Good: no separate build step.
- Bad: per-request generation overhead; fights opcache; static analyzers can't see
  generated types; a hard runtime dependency.

### PHP extension

- Good: deepest integration, potentially best performance.
- Bad: enormous scope and maintenance; install friction; ties the project to engine
  internals — out of proportion for a language experiment.

## More Information

- [How it works](../guides/how-it-works.md) — the parse → specialize → emit flow.
- `composer.json` declares the project a `library` of type *transpiler /
  monomorphization*; the compiled output targets plain PHP.
