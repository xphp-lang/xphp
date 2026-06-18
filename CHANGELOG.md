# Changelog

All notable changes to `xphp` are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- **`xphp check`** — a validate-without-emitting CI gate. It runs every generic
  validation `xphp compile` does (bounds, variance, defaults, missing/duplicate
  generics, unsupported closures), but collects **all** problems in one run —
  each as a structured diagnostic with a `file:line` — instead of aborting on the
  first. Exit codes: `0` clean, `1` ≥1 error, `2` operational failure.
  `--format=text|json|github` (the `github` format emits PR annotations).
- **PHPStan over the compiled output.** When the generic checks pass, `xphp check`
  compiles to a throwaway directory, runs **your** PHPStan over the concrete
  (monomorphized) output, and maps each finding back to the originating `.xphp`
  template declaration — naming the concrete instantiation that surfaced it. One
  config (your `phpstan.neon` drives level/rules), one gate, one exit code.
  `phpstan/phpstan` stays optional and is never bundled in the PHAR; a missing
  binary or a failed run is a non-failing Warning. Opt out with `--no-phpstan`;
  override discovery with `--phpstan-bin` / `--phpstan-config`.

## [0.2.0]

The first feature release on top of the core monomorphization pipeline.
Generics now cover the full declaration surface — classes, interfaces,
traits, methods, free functions, closures, and arrow functions — each
with bounds, defaults, and variance, behind forward-compatible turbofish
syntax at every call site. See the [syntax tour](docs/syntax/index.md)
for a hands-on look at every feature below.

### Added

- **Type-parameter bounds**, checked at compile time, with error
  messages that point at the source-level instantiation rather than the
  generated hash:
  - Single upper bound — `class Box<T: \Stringable>`.
  - Intersection multi-bound — `T: \Stringable & \Countable`.
  - Disjunctive normal form (DNF) — `T: (\Stringable & \Countable) | \Iterator`.
  - F-bounded recursion — `class Sortable<T: Comparable<T>>`.
  - Built-in interface whitelist (`Stringable`, `Countable`, `Iterator`,
    `Traversable`, `ArrayAccess`, `JsonSerializable`, `Throwable`, …) so a
    user class that `implements \Stringable` satisfies a bound without the
    interface needing a source declaration.
- **Default type parameters** at every level — class, method, free
  function, closure, and arrow — with forward references
  (`Pair<A, B = A>`), declaration-time bound checks on fully-concrete
  defaults, and empty-turbofish `::<>` (and bare `new Cache;`) for
  all-defaults templates.
- **Variance** — `+T` (covariant) and `-T` (contravariant) markers on
  type parameters:
  - Position rules enforced at parse time (covariant in return,
    contravariant in parameter; both forbidden in properties,
    constructors, bounds, and defaults).
  - Real subtype edges between specializations — `Producer<Banana>`
    actually `extends Producer<Fruit>` when `Banana extends Fruit`.
  - Inner-template variance composition across nested generic args,
    validated at compile time after all templates are known.
- **Function-level generics**:
  - Generic methods on static and instance receivers.
  - Generic free functions at namespace scope and bare top-level.
  - Nullsafe instance turbofish — `$obj?->m::<T>()`.
  - Receiver-type analysis for `$this`, typed parameters, typed
    properties, and local `$x = new Foo()` assignments; conservative
    de-specialization when branch arms disagree on a class.
- **Generic closures and arrow functions** — `function<T>(...) { … }` and
  `fn<T>(...) => …`, specialized through a closure dispatcher:
  - Explicit `use (...)` clauses, including by-reference `use (&$x)`.
  - Implicit arrow captures lifted into the dispatch shape.
  - Default type parameters on closures and arrows.
- **Pseudo-types** — `self<T>` / `static<T>` / `parent<T>` in type-hint
  positions and at constructor sites (`new self::<T>()`,
  `new static::<T>()`, `new parent::<T>()`).
- **`T[]` array sugar** — documentation-friendly shorthand that lowers to
  plain `array` at compile time. (`array<K, V>` remains rejected, per the
  RFC.)
- **Documentation** — a full `docs/` tree: a per-feature
  [syntax tour](docs/syntax/index.md), [getting started](docs/getting-started.md),
  [how it works](docs/guides/how-it-works.md),
  [runtime semantics](docs/guides/runtime-semantics.md),
  [type-system comparison](docs/guides/comparison.md),
  [caveats](docs/caveats.md), [errors](docs/errors.md), and the
  [roadmap](docs/roadmap.md).

### Changed

- Call-site syntax aligned with the PHP RFC: turbofish `Name::<...>` at
  call sites. Parenless `new Name<...>` is now rejected with a message
  pointing at the `::<...>` form.

### Known limitations

These are documented in full in the [caveats](docs/caveats.md):

- Variance markers (`+T` / `-T`) are class-level only — not yet on
  methods, free functions, closures, or arrows.
- Generic closures and arrows that capture `$this`, and `static`
  closures, are rejected at the call site.
- Reflection and closure serializers see the dispatcher shape, not the
  original closure body.
- The variance validator does not walk into trait-imported method
  signatures.

## [0.1.0]

### Added

- Initial release: the core monomorphization pipeline that compiles
  `.xphp` generic **classes** into vanilla PHP — one specialized class
  per concrete instantiation, with native type hints and no runtime
  dispatch overhead.
- Arbitrarily nested generics with a fixed-point specialization loop
  (depth cap plus cycle detection).
- Marker-interface trick so `$x instanceof App\Box` holds across every
  `Box<...>` specialization.
- Build-time hash-collision detection and a configurable
  `XPHP_HASH_LENGTH` (16–64).

[Unreleased]: https://github.com/xphp-lang/xphp/compare/v0.2.0...HEAD
[0.2.0]: https://github.com/xphp-lang/xphp/compare/v0.1.0...v0.2.0
[0.1.0]: https://github.com/xphp-lang/xphp/releases/tag/v0.1.0
