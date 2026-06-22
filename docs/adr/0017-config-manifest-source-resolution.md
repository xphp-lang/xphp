# 17. Config-manifest, multi-root source resolution (`xphp.json`)

- Status: Accepted — 2026-06

## Context and Problem Statement

`xphp compile`/`check` took a single source directory. Because specialization needs each generic
template's parsed AST in memory, a `new Foo::<int>()` call site can only be specialized when
`Foo<T>`'s `.xphp` template is in the *same* compiled source set ([ADR-0001](0001-monomorphization-over-type-erasure.md)).
So a package that ships `.xphp` templates, and any app consuming one, had to **stage** the
templates and the consumer tree into a single directory on every build (a copy step). Consuming
another package's generics is a first-class goal, and that friction defeats it.

We need a way to compile several source roots together in one invocation, and to do so without the
developer hand-listing every dependency or re-editing config when a package is installed.

## Decision Drivers

- Let a package self-describe its `.xphp` sources, and pull in other packages, in one compile.
- Auto-discover dependencies — installing a new xphp package must not require a manifest edit.
- Keep xphp's minimal-dependency posture and its pure-transpiler runtime model.
- Preserve the existing single-directory CLI form unchanged.

## Considered Options

- **Repeatable `--source` flags** — multiple roots on the command line. Works, but the consumer must
  enumerate every (transitive) dependency by hand and re-do it on every install.
- **Prebuilt/persisted registry** — ship a compiled, reloadable package and compile against it
  without its sources. Larger change (serialize template ASTs + a load path); deferred (see below).
- **A config manifest (`xphp.json`)** that declares sources and transitively includes other
  packages, with glob auto-discovery.

## Decision Outcome

Chosen: **an `xphp.json` manifest resolved into a multi-root source set.** A manifest declares
`sources` (its own `.xphp` roots) and `include` (other packages, transitively). `include` entries
may be globs; a glob-matched directory with its own `xphp.json` is pulled in, others skipped, so
`"include": ["vendor/*/*"]` discovers every installed xphp package and is set once. Resolution
dedups by realpath (diamonds resolve once; cycles terminate). The CLI takes `--config <path|dir>`
or auto-detects `xphp.json` in the working directory; the single-directory positional form is
unchanged (precedence: positional source → `--config` → auto-detect).

Three sub-decisions:

- **Plain JSON, no new dependency.** Parsed with the built-in `json_decode`. JSON5 (comments /
  trailing commas) would need a runtime parser; not worth a dependency for v1.
- **Emit-all (consumer compiles the union).** Upstreams ship `.xphp` *sources*; the downstream
  build pulls and compiles them, so it is the sole compiler and **emits everything it depends on** —
  the upstream's marker interfaces ([ADR-0004](0004-marker-interfaces-for-instanceof.md)) and
  non-generic classes, plus the consumer-driven specializations. A "resolve-only, don't re-emit
  deps" policy would leave the generated specializations referencing a marker nobody emitted
  (runtime fatal); it is only correct once upstreams ship *prebuilt* PHP, which is the deferred
  Option B below.
- **Root-aware emit.** `Compiler::compile` gained a per-file source-root map so each emitted file
  keeps its own PSR-4 layout instead of flattening a second root to `basename()`; a same-target
  collision across roots is a hard error.

The resolution logic lives in two small services (`ManifestParser`, `ManifestResolver`) plus a
`SourceResolver` that the commands share; the monomorphization core is otherwise untouched.

### Consequences

- Good: a library compiles its `src` + `tests` + `examples` together (no staging script), and an
  app compiles against its dependencies' templates with a one-line, install-stable `include` glob.
- Good: no new runtime dependency; xphp stays a pure transpiler (it emits, it doesn't ship a runtime).
- Trade-off: the downstream recompiles included sources on each build ("compile when needed"); a
  prebuilt/incremental path is future work (Option B).
- Trade-off: the manifest is plain JSON — no comments/trailing commas.

### Confirmation

`ManifestParser`/`ManifestResolver` are unit-tested (transitive resolution, realpath dedup, cycle
termination, glob discovery skipping non-xphp dirs, explicit-missing-manifest error). `CompileCommand`
is end-to-end tested incl. a cross-package consume whose compiled output is required in a subprocess
and runs with no fatal (the upstream marker is emitted), glob auto-discovery, auto-detect, `--config`
precedence, and target/cache precedence. The single-directory form's behaviour is pinned unchanged.
Documented in [getting started](../getting-started.md).

## More Information

- [ADR-0001](0001-monomorphization-over-type-erasure.md) — specializations need the template AST.
- [ADR-0004](0004-marker-interfaces-for-instanceof.md) — the marker interfaces the consumer must emit.
- [ADR-0011](0011-phar-distribution.md) — why a new runtime dependency was avoided.
- Future: a prebuilt/persisted-registry distribution (serialize template ASTs + a load path),
  enabling resolve-only deps and skip-unchanged incremental builds.
