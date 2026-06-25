# Changelog

All notable changes to `xphp` are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [0.3.0] - Unreleased

_In progress on this branch — content still accumulating; date set at tag time._

### Added

- **Multi-root builds via an `xphp.json` manifest.** A project declares its source
  roots, output directory, and hash length in an `xphp.json` at the project root;
  `xphp compile` and `xphp check` auto-detect it (or take an explicit `--config`).
  Roots are merged into a single source set — a template in one root can reference a
  type in another — and include patterns accept a `**` globstar for recursive
  discovery. A consuming build compiles only the `.xphp` sources whose own
  `xphp.json` opts in (so `"vendor/**"` picks up the dependencies that ship a
  manifest, and skips those that don't). See
  [getting started](docs/getting-started.md).
- **Element-typed methods on covariant collections.** A method-level type parameter
  bounded by an enclosing class type parameter — `class Box<+E> { public function
  contains<U : E>(U $value): bool }` — has its bound **grounded** against the receiver's
  concrete type argument: `Box<Fruit>::contains<Banana>` is accepted when `Banana <: Fruit`,
  and a genuine violation (`Box<Fruit>::contains<Rock>`) is rejected with the bound shown as
  the real type, not `E`. The receiver's argument is threaded up the `extends`/`implements`
  chain, so a method declared on a generic interface/base and inherited by a concrete
  collection is grounded too. The receiver's type is determined from a typed parameter or
  `$this->prop`, a `new`-constructed local, a value whose type comes from a method return /
  chained call / `self`/`static` factory, and a branch whose arms agree on the same
  parameterised type. A bound that references a sibling parameter is grounded the same way,
  at the class level (`class Pair<T, U : T>`) and the method level (`<U, V : U>`). This is the
  sound, element-typed alternative to a `mixed` parameter on a covariant `<+E>` collection
  (`U` is invariant — not method-level variance). **Ground or fail:** where the receiver's
  type argument genuinely can't be determined, the bound can't be proven, so it is a compile
  error (`xphp.bound_unprovable`) with an actionable remedy — bind the receiver to a typed
  local — rather than an unchecked call. Nothing knowable is skipped, and no check is deferred
  to runtime. **Lowering:** a method whose bounded parameter is used only as a direct input
  (`U $value`) is emitted as one `E`-typed member per class instantiation
  (`contains_<Fruit>(Fruit)`) rather than one per call-site type — so a self-call that *forwards*
  the parameter, `probe<U : E>(U $v) { return $this->contains::<U>($v); }`, compiles and runs (the
  idiomatic way to call an element-consuming method from inside the class). A `$this`-rooted
  forward to a *non-erasable* method (parameter used nested, in the return, or structurally), and
  a direct concrete `$this->contains::<Banana>()`, remain compile errors
  (`xphp.unspecializable_self_call` / `xphp.bound_unprovable`) — never a runtime fault. **Covariant
  interfaces:** the method may be declared on a covariant interface (`Collection<+E>`) and called
  through an upcast (`ListColl<Book>` used as `Collection<Product>`) — the implementer specialization
  is scheduled and inherited down the covariant chain automatically. When inheritance can't carry it
  there — the implementing class has another `extends` parent, implements only a *parent* of the
  interface, or reorders the `implements` clause — the member is instead emitted **directly** onto the
  upcast source, with its bounded parameter widened to the supertype argument and its body read at the
  source's own element type (sound because the source's element is a subtype of the supertype). The
  upcast remains a compile error (`xphp.unschedulable_covariant_upcast`) — never emitted load- or
  runtime-fataling code — only where no emittable class body exists (a truly abstract or trait-only
  method), where the method's return type names the element parameter (the widened argument would
  escape through a narrower return), or where its parameters are bounded by different enclosing
  parameters (no single member can be derived). See [type bounds](docs/syntax/type-bounds.md) and
  [ADR-0018](docs/adr/0018-grounding-method-generic-bounds-on-enclosing-type-parameters.md).
- **Generic methods resolved through inheritance.** A generic method declared on a
  base or abstract class is now callable by turbofish on a *subclass* receiver —
  instance (`$child->m::<int>()`), static (`Child::m::<int>()`), and nullsafe
  (`$child?->m::<int>()`). It is specialized once on its declaring class and
  inherited, so the call dispatches to the real member. An **unresolved** turbofish —
  a generic method that exists on neither the receiver nor any ancestor — is now a
  compile error (`xphp.unresolved_generic_call`) instead of an emitted call to a
  stripped method that fatals at runtime.
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
- **Type-parameter-typed constructors on variant classes.** A covariant /
  contravariant class may take its type parameter in a (non-promoted) constructor
  parameter — e.g. a covariant immutable `ImmutableList<+T>` built from
  `T ...$items`. The parameter keeps its **real** element type on every
  specialisation (`Book ...$items`, not `mixed`), so construction is
  **runtime-type-checked** while `ImmutableList<Book>` still extends
  `ImmutableList<Product>` — PHP exempts `__construct` from LSP, so the
  specialisations' constructors may differ across the edge. A public/protected
  promoted constructor param remains a visible property (strictly invariant — PHP
  enforces property types across the edge for visible members), but a *private*
  one is exempt (see below). See [variance](docs/syntax/variance.md).
- **Variance markers on private properties.** A `+T` / `-T` marker is now allowed
  on a **private** property — declared or promoted, mutable or readonly — so the
  natural covariant shape
  `class Producer<+T> { public function __construct(private T $item) {} ... }`
  compiles, keeps its **real** substituted slot type (nothing erased), and stays
  runtime-type-checked. PHP does not type-check private property types across an
  `extends` chain (a private slot is per-declaring-scope and never inherited) and
  a private member is invisible to the variance surface, so it carries any variance
  soundly. Public/protected properties (including public/protected promoted params,
  and an externally-readable `public private(set)` property) stay strictly
  invariant. A covariant single-value getter over a `private T` field is also
  PHPStan-clean. See [variance](docs/syntax/variance.md).
- **By-reference parameters are an invariant variance position.** A `+T` / `-T`
  type parameter used in a by-reference parameter (`&$x`) is now rejected: a
  by-reference slot is both read and written through the caller's binding, so it is
  invariant — the same rule already applied to a mutable property. See
  [variance](docs/syntax/variance.md).

### Changed

- **BREAKING — a `final` variant class is now rejected.** A `final class Box<+T>`
  previously compiled, with the generated specialization silently dropping `final`
  so the `extends` subtype edge between specializations could land — which made
  `ReflectionClass::isFinal()` disagree with the written source. A covariant /
  contravariant class marked `final` is now a compile error (a `final` class can't
  anchor the `extends` edge); omit `final` on a variant template. Non-variant
  generic classes are unaffected. See [variance](docs/syntax/variance.md).

### Fixed

- **Undeclared type parameters are now rejected** instead of silently compiling to
  a reference to a non-existent class. A bare, single-segment, non-imported type
  name used in a generic member, bound, or default that is neither a declared type
  parameter nor a known type — e.g. `interface Foo<Z> { add(T $x); }` or
  `class Box<T: Nonexistent>` — fails `xphp compile` and is reported by `xphp check`
  as `xphp.undeclared_type`. Covers class/interface/trait members and method,
  function, closure, and arrow generics. Imported (`use`) and fully-qualified names
  are unaffected.
- **Too many type arguments are now rejected** instead of silently truncated:
  `Box::<int, string>` for a one-parameter `Box` reports `xphp.too_many_type_arguments`.
- **A turbofish call on an undeterminable receiver is now rejected** instead of
  silently emitting a runtime fatal. A generic method call like `$x->m::<int>()` is
  specialized at compile time and the generic method is stripped from its class, so
  when the receiver's type can't be determined — an untyped `foreach` variable, a
  local whose type is ambiguous after a branch — the call previously compiled to
  `$x->m(...)`, a call to a method that no longer exists (an "undefined method" fatal
  at runtime). It now fails `xphp compile` and is reported by `xphp check` as
  `xphp.undetermined_receiver`, with the fix: give the receiver a statically-known
  type. Ground or fail — the compiler never emits a call it knows will fatal.
- **A class bound that references a sibling type parameter is now checked**
  correctly. A bound such as `class Pair<T, U : T>` grounds `T` against the
  supplied argument instead of treating `T` as a phantom class — which previously
  rejected valid code with a misleading "does not extend/implement T".
- **A scalar bound is no longer flagged as an undeclared type.** A bound naming a
  scalar (`int`, `string`, …) is no longer reported as `xphp.undeclared_type`.
- **A turbofish-less call to a generic method, function, or closure is now rejected**
  instead of silently skipped. A method generic takes no inference, so a forgotten
  turbofish (`$x->pick('a')` instead of `$x->pick::<string>('a')`) previously emitted a
  call to the stripped mangled member and fataled at runtime — clean `check` and
  `compile`. It now fails `xphp compile` and is collected by `xphp check` as
  `xphp.missing_type_argument`, across instance, static, free-function, and closure
  calls. A generic whose type parameters are all defaulted still resolves; a
  first-class callable (`pick(...)`) and a non-generic call are unaffected.

## [0.2.1] - 2026-06-17

### Fixed

- The Composer autoloader is located when xphp is installed as a project
  dependency, not only when run from its own checkout.
- Bare imported (`use`) names are fully-qualified in specialized classes, so a
  generated specialization in a mirrored namespace resolves them correctly.

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

- Variance markers (`+T` / `-T`) are class-level only **by design** — not
  supported on methods, free functions, closures, or arrows (a function or
  closure specialization has no stable class identity for a subtype edge).
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

[0.3.0]: https://github.com/xphp-lang/xphp/compare/v0.2.1...HEAD
[0.2.1]: https://github.com/xphp-lang/xphp/compare/v0.2.0...v0.2.1
[0.2.0]: https://github.com/xphp-lang/xphp/compare/v0.1.0...v0.2.0
[0.1.0]: https://github.com/xphp-lang/xphp/releases/tag/v0.1.0
