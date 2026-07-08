# Changelog

All notable changes to `xphp` are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [0.3.0] - Unreleased

_In progress on this branch — content still accumulating; date set at tag time._

### Added

- **`Closure(...)` signature types.** A type hint such as `Closure(int $x, string $y):
  bool` may appear in any parameter, return, or property position; it documents the
  callable a slot expects and **erases to a bare `\Closure`** in the emitted PHP.
  Where a closure literal is returned against a `Closure(...)` return type (a
  typed-closure factory), xphp checks conformance — parameters contravariant, return
  covariant, by-reference exact, arity compatible — and fails the build on a
  **provable** mismatch (`xphp.closure_conformance`), while accepting anything it can't
  prove wrong (untyped ⇒ `mixed`, an unresolved or built-in supertype, a
  still-abstract type parameter, a union/intersection). A signature that references an
  enclosing type parameter is **grounded** per specialization, so `Registry<int>` and
  `Registry<string>` check the same factory against different concrete targets. A flat
  union / intersection / nullable inside a signature (`Closure(int|string $x): void`,
  `Closure(): A&B`, `?int`) is variance-checked member by member, following PHP's own
  union/intersection subtyping; an intersection in a parameter position stays gradual
  (an intersection of unrelated types is uninhabited). See
  [closure types](docs/syntax/closure-types.md).
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
  bounded by an enclosing class type parameter — `class Box<out E> { public function
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
  sound, element-typed alternative to a `mixed` parameter on a covariant `<out E>` collection
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
  interfaces:** the method may be declared on a covariant interface (`Collection<out E>`) and called
  through an upcast (`ListColl<Book>` used as `Collection<Product>`) — the implementer specialization
  is scheduled and inherited down the covariant chain automatically. When inheritance can't carry it
  there — the implementing class has another `extends` parent, implements only a *parent* of the
  interface, or reorders the `implements` clause — the member is instead emitted **directly** onto the
  upcast source, with its bounded parameter widened to the supertype argument and its body read at the
  source's own element type (sound because the source's element is a subtype of the supertype). When the
  element type is itself a covariant generic (e.g. a `Tuple<out A, out B>`), per-argument covariance makes the
  source an instance of the interface at *several* supertype arguments at once — a diamond that single
  inheritance can carry only one path of; the remaining obligations are supplied directly once the
  inheritance chain is final, so a covariant container of covariant containers upcasts soundly. The
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
  parameter — e.g. a covariant immutable `ImmutableList<out T>` built from
  `T ...$items`. The parameter keeps its **real** element type on every
  specialisation (`Book ...$items`, not `mixed`), so construction is
  **runtime-type-checked** while `ImmutableList<Book>` still extends
  `ImmutableList<Product>` — PHP exempts `__construct` from LSP, so the
  specialisations' constructors may differ across the edge. A public/protected
  promoted constructor param remains a visible property (strictly invariant — PHP
  enforces property types across the edge for visible members), but a *private*
  one is exempt (see below). See [variance](docs/syntax/variance.md).
- **Variance markers on private properties.** A `out T` / `in T` marker is now allowed
  on a **private** property — declared or promoted, mutable or readonly — so the
  natural covariant shape
  `class Producer<out T> { public function __construct(private T $item) {} ... }`
  compiles, keeps its **real** substituted slot type (nothing erased), and stays
  runtime-type-checked. PHP does not type-check private property types across an
  `extends` chain (a private slot is per-declaring-scope and never inherited) and
  a private member is invisible to the variance surface, so it carries any variance
  soundly. Public/protected properties (including public/protected promoted params,
  and an externally-readable `public private(set)` property) stay strictly
  invariant. A covariant single-value getter over a `private T` field is also
  PHPStan-clean. See [variance](docs/syntax/variance.md).
- **By-reference parameters are an invariant variance position.** A `out T` / `in T`
  type parameter used in a by-reference parameter (`&$x`) is now rejected: a
  by-reference slot is both read and written through the caller's binding, so it is
  invariant — the same rule already applied to a mutable property. See
  [variance](docs/syntax/variance.md).
- **Variance composes through a nested generic type-argument.** A covariant slot whose
  argument is itself a generic of a *different but related* template now emits its
  `extends`/`implements` edge — so a covariant `Tuple<out A, out B>` holding a covariant
  container relates by that container's element type (`Tuple<ImmutableList<Book>, Tag>`
  is usable where a `Tuple<Collection<Product>, Tag>` is required, because
  `ImmutableList<Book> ⊑ Collection<Product>`). The argument relationship is proven by
  threading the subtype's element up its `implements`/`extends` chain to the supertype's
  template and comparing under the inner template's variance; the edge is emitted only
  when positively provable, so the covariance now holds at runtime (`instanceof`, type
  hints) and not only at `check`. Previously such an upcast passed `check` but fatal'd at
  load. See [variance](docs/syntax/variance.md).
- **A contravariant generic may be consumed by a covariant class.** A method parameter typed
  by a contravariant generic of the class's covariant parameter — `class Box<out E> { pick(
  Comparator<E> $c): ?E }` where `Comparator<in T>` — is now accepted: `E` sits in a
  contravariant slot inside a contravariant parameter position, which composes to a covariant
  position a `out E` may occupy (sound under upcast — a `Comparator<Product>` compares the `Book`
  elements of a `Box<Book>` viewed as `Box<Product>`). Variance validation now routes every
  type-constructor-nested type-parameter through the composing check (which already knew the
  inner slot's variance) instead of judging it by the bare outer position, so this sound,
  `mixed`-free `sortedWith`/`minWith`/`pick` shape compiles instead of being wrongly rejected.
  A bare `E` in a parameter position is still rejected — only the composed position is
  covariant. See [variance](docs/syntax/variance.md).

### Changed

- **BREAKING — variance markers are now `out T` / `in T`.** Declaration-site variance
  is written with the Kotlin-style keywords `out` (covariant) and `in` (contravariant)
  instead of the previous `+T` / `-T`: `class Producer<out T>`, `class Consumer<in T>`.
  The keywords are contextual — a leading `out`/`in` is a marker only when immediately
  followed by the parameter name (`out T`, never `outT`), so ordinary parameters whose
  names merely start with those letters are unaffected; the words are reserved and
  cannot themselves name a parameter. Old `+T` / `-T` source now fails with a migration
  error pointing at the new spelling. Variance semantics are unchanged — only the
  surface syntax moves. See [Variance](docs/syntax/variance.md).
- **`xphp compile` runs the validation gate by default.** Compile now runs the same
  gate as `xphp check` (the generic validators plus PHPStan over the compiled output)
  *before* emitting, and fails the build — emitting nothing — when the gate reports an
  error. So a type argument that names no real class (`new Box::<Nonexistent>()`) and
  every other PHPStan-detectable error now fails at compile time instead of slipping
  through to runtime. PHPStan's real autoloader visibility makes this sound — a genuine
  plain-`.php` domain class used as a type argument is never false-rejected. For a fast
  iteration build, `--no-check` skips the gate and compiles directly (the previous
  behavior). `--no-phpstan` runs only the generic validators; a missing PHPStan degrades
  to a non-failing warning.
  See [ADR-0021](docs/adr/0021-compile-runs-the-check-gate-by-default.md).
- **BREAKING — a `final` variant class is now rejected.** A `final class Box<out T>`
  previously compiled, with the generated specialization silently dropping `final`
  so the `extends` subtype edge between specializations could land — which made
  `ReflectionClass::isFinal()` disagree with the written source. A covariant /
  contravariant class marked `final` is now a compile error (a `final` class can't
  anchor the `extends` edge); omit `final` on a variant template. Non-variant
  generic classes are unaffected. See [variance](docs/syntax/variance.md).

### Fixed

- **A specialized generic body keeps calling the free functions and constants it
  named.** When a generic class specializes, its body is relocated into an internal
  `XPHP\Generated\…` namespace. An unqualified free-function call or constant read in
  that body — `helper($x)`, `FACTOR`, whether resolved through the enclosing namespace
  or a `use function` / `use const` import (single **or** grouped, e.g.
  `use function Lib\{make, scale}`) — used to rebind against the generated namespace
  (then PHP's global fallback), silently calling the wrong symbol or fatalling at
  runtime with `Call to undefined function XPHP\Generated\…\helper()` behind a clean
  `compile` and `check`. Each such reference the compilation unit can resolve is now
  fully-qualified to the symbol the template meant; built-in functions, magic constants,
  and any name the unit does not define keep PHP's normal global resolution (functions
  match case-insensitively, constants case-sensitively). The re-qualification also
  covers members supplied by the covariant-upcast gap-fill, which are appended after the
  main relocation pass.
- **A generic clause on a `use` import is rejected instead of misfiring.** Writing
  a type argument on an import — `use App\Box<int>;`, `use App\Box<int> as B;`,
  `use const App\BOX<int>;`, or the grouped `use App\{Box<int>, Bag};` — has no
  meaning (imports name a symbol; they don't instantiate one). It used to either
  blame a phantom double-qualified template (`Main\App\Box`) with no line, or — for
  a grouped import whose name collided with a real template — **silently emit
  unparseable PHP** (an absolute specialized name inside a `use N\{…}` prefix group)
  that passed both `check` and `compile` and only failed when the file was loaded.
  Every form now draws one clear diagnostic naming the real symbol at the import's
  line, collected by `check` and thrown by `compile`. Import the template plainly
  (`use App\Box;`) and apply the type arguments at the use site. A generic
  **trait-use** (`class C { use Holder<int>; }`) is unaffected — it still specializes
  the trait.
- **`check` reports parse-stage rejections at their real source line.** Syntax
  the scanner rejects before the AST exists — a variance marker on a method or
  closure (`out T` / `in T`), the legacy `+T` / `-T` glyphs, a malformed or
  misordered generic default (`T = ?int`, `T = Foo | Bar`, a required parameter
  after a defaulted one), or a call signature on a non-`Closure` name — was
  reported by `check` at line 1 regardless of where it occurred, so an editor
  could not navigate to it (the real location survived only in the message text).
  These now carry the offending token's line. A few structural rejections raised
  after parsing (a self-referential bound, a default referencing a later
  parameter) still fall back to line 1, where no token position is available.
  `compile` behaviour is unchanged.
- **A bare `new` of a generic without all-defaults is rejected instead of
  silently emitting an uninstantiable marker.** `new Box(...)` where `Box<T>`
  has a required type parameter and no turbofish was skipped by the
  instantiation collector, leaving the call site pointing at the stripped marker
  `interface Box {}` — so the emitted code fatalled with "Cannot instantiate
  interface" behind a clean compile and a clean `check`. It now fails with
  `xphp.missing_type_argument` (throwing in `compile`, collected in `check`),
  exactly as a turbofish-less generic call does. All-defaults generics still
  instantiate from a bare `new`, and a bare `new B` still works when a plain
  `class B` coexists with a generic `class B<T>` (conditional same-name
  declarations resolve to the plain class at runtime).
- **A first-class callable of a turbofish specialization emits valid PHP.**
  `$g = $f::<int>(...)` on a generic closure emitted `$f('T_…', ...)` — the
  specialization tag prepended beside the `...` placeholder, which does not
  parse, so the output failed to load. It now emits a forwarding closure that
  routes through the dispatcher, preserving callable semantics (positional,
  variadic, and named arguments and the closure's captures); empty-turbofish
  all-defaults FCCs (`$f::<>(...)`) work the same way.
- **Parenthesised DNF types inside `Closure(...)` signatures are supported.** A
  DNF group (`(A&B)|C`, `A|(B&C)`) anywhere in a signature previously broke the
  type scanner: a leading group in a return position failed to compile, a
  trailing group in a return hint silently mis-erased (emitting a wrong type and
  a truncated recorded return), and a group in a parameter threw the signature's
  arity off — wrongly rejecting a conforming factory literal. A DNF group now
  scans as one **gradual** leaf: the signature erases correctly, arity and the
  slots around the group are checked as usual, and the group itself is accepted
  rather than variance-checked member by member.
- **Array-sugar types inside `Closure(...)` signatures are supported.** A
  sugared leaf (`Closure(Item[] $items): int`, `Closure(): int[]`) previously
  broke the signature scanner: in a return position the bracket pair survived
  erasure as a raw parse error, and in a parameter position it mis-parsed as
  three parameters — wrongly rejecting a conforming factory literal on arity.
  The sugar now lowers to `array` inside signatures, the same lowering it gets
  everywhere else, and is checked as a gradual `array` leaf.
- **Provable violations against built-in target types are now caught.** A factory
  whose literal returns a class with fully-known, built-in-free ancestry checked
  against a built-in target (`Closure(): \Throwable` returning a plain user
  class) was silently accepted — the guard treated EVERY built-in target as
  unprovable. When the candidate's whole ancestry is declared user code, the
  non-relation is provable and now fails the build. Everything genuinely
  satisfiable at runtime keeps compiling: subclasses of built-ins
  (`extends \Exception` vs `\Throwable`), enums against interfaces they
  implement (enum `implements` clauses and the implicit `UnitEnum`/`BackedEnum`
  edges are now modeled — which also lets `T : UnitEnum` bounds accept enum
  arguments), `__toString` classes against `\Stringable`, and candidates with
  any unknown ancestor.
- **Expression-position `Closure(...)` calls are never mistaken for return types.**
  The return-slot detector keyed on a bare `) :` pair — which ternaries,
  `case expr():` labels, and alt-syntax blocks (`if/elseif/while/for/foreach/
  declare (…):`) also produce. A call to a user function named `Closure` in
  those positions was silently rewritten into a `\Closure` constant fetch, and a
  call to ANY function there (`$a ? b() : g(FOO);`) failed the compile with the
  only-Closure error. The detector now requires an actual `function`/`fn`
  declaration header (including by-ref, `use (…)` clauses, and tight spellings)
  and ignores member calls of the semi-reserved names (`C::fn()`).
- **Relative `namespace\Foo` types resolve correctly everywhere names are read
  from tokens.** A relative reference bound to the wrong name (`App\namespace\Foo`,
  or a colliding `use` alias) wherever the resolver worked on raw token text —
  most visibly a `Closure(): namespace\D` target type, which silently skipped
  conformance checking. Relative names now bind to the current namespace, exactly
  as PHP does, for signature targets, bounds, defaults, and generic arguments
  alike; only the exact `namespace\` keyword segment is affected.
- **Fully-qualified types in closure literals participate in conformance.** A
  factory literal spelling its type fully qualified (`fn(): \App\Fruit`) had the
  leading `\` dropped during extraction, mis-resolving the name relative to the
  current namespace — an undeclared class, so the check silently went gradual and
  provable violations were missed. FQ names now resolve absolutely (relative and
  imported names are unchanged).
- **Generic closures after array-sugar rewrites specialize correctly.** The
  `Name[]` → `array` rewrite shortens the source, and the byte-keyed marker that
  attaches type parameters to an anonymous `function<T>` / `fn<T>` was compared
  against the shifted position — so a generic closure appearing after such a
  rewrite silently lost its type parameters, compiled unspecialized, and the
  emitted code fataled at runtime despite a clean validation pass. Marker
  positions are now translated through the byte-offset map before matching.
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
- **A scalar type argument now satisfies a scalar generic bound.** A bound naming a
  scalar or scalar union — `class Box<T : int|string>` — previously namespace-qualified
  its operands (`App\int | App\string`), so *every* valid scalar argument was rejected
  with `"string" does not satisfy "App\int | App\string"`. The bound leaf was the one
  type position that skipped the scalar-aware name resolution every other position uses.
  Its reserved scalar/builtin keywords are now recognised and left unqualified, so
  `Box::<string>` satisfies `<T : int|string>` and `<T : int|float>` accepts `::<float>`,
  while a class argument is still rejected (`"App\Thing" does not satisfy "int | string"`)
  and a class bound that merely *looks* like a legacy alias (`<T : Double>`, where `Double`
  is a real class — not a reserved keyword) keeps resolving to the class.
- **A class whose name aliases a scalar (`Integer`/`Boolean`/`Double`) now resolves to the
  class in every type position.** The internal keyword list conflated the reserved PHP scalar
  keywords with the legacy gettype-style aliases `integer`/`boolean`/`double`, which are
  *legal class names*. So a class `Double` used as a generic type argument (`new
  Box::<Double>(...)`) or as a member/signature type was mistaken for a scalar and emitted
  as the bare type `double`, which PHP reads as a non-existent class — a `TypeError` at
  runtime. The list now holds only the reserved keywords, so such a class resolves to
  `\App\Double` in argument and signature positions (and, as before, in a bound). Genuine
  scalars (`int`, `string`, `bool`, `float`, and case variants like `Int`) are unchanged;
  an undeclared `Double` member is now reported as `xphp.undeclared_type` instead of being
  silently absorbed as a scalar.
- **Generic declarations keep their type parameters regardless of header layout.**
  Four marker-alignment defects silently de-generified declarations — clean compile,
  clean `check`, raw `T` hints in the emitted code, `TypeError` at runtime: any
  **multi-line** generic clause, turbofish argument list, or `T[]` sugar span
  collapsed its newlines when stripped, shifting every later declaration off its
  marker (`class Wide<\n T\n>` before `class Box<T>` left `Box` raw); `static
  fn<T>(...)` anchored its marker at `fn` while the node starts at `static`; an
  attribute before a generic closure (`#[A] function<T>`, `#[A] static fn<T>`)
  moved the node start to `#[`; and an attribute or modifier on its own line before
  a **named** generic declaration (`#[Override]` above `public function wrap<T>`,
  `final` above `class Pair<T>`) lost the marker too — or false-rejected the class
  as "instantiated but never defined". Stripping now preserves newlines
  byte-for-byte (multibyte- and CRLF-safe; the `T[]` lowering re-appends its span's
  newlines), anonymous markers anchor at the node's true start (walking back over
  attribute groups and `static`), and named markers match on the declaration
  name's line. A `static fn<T>` now simply **specializes** like any arrow — it can
  never bind `$this`; the rewritten dispatcher closure is technically non-static,
  observable only via `Closure::bind`/reflection — while `static function<T>`
  keeps its loud not-yet-supported error, which now also fires when an attribute
  precedes it.
- **A generic clause that fails to bind to its declaration is now a loud compile
  error.** Every defect in the family above was silent for the same structural
  reason: an unbound marker simply evaporated and the compiler carried on. The
  strict compile path now reports it (as a transpiler bug to report), instead of
  emitting silently de-generified code; the tolerant LSP path is exempt —
  half-typed editor buffers legitimately strand markers. A `use function b<T>;`
  typo gets PHP's own syntax error on the `<` rather than being swallowed.
- **Fully-qualified and `namespace\`-relative spellings work at every generic
  site.** Names were resolved from prefix-erased strings, losing the author's
  qualification: `new \App\Box::<int>` hard-failed as the undefined, doubled
  `App\App\Box` — and an FQ generic **function** call (`\App\make::<int>(...)`)
  silently lost its dispatcher, an undefined-function fatal at runtime;
  `new \App\Box("hi")` on an all-defaults generic silently emitted the stripped
  marker interface plus the kept `new` — "Cannot instantiate interface" at
  runtime; `new namespace\Box::<int>` never bound its marker and silently emitted
  the same fatal shape; on one line, a relative generic return type could steal
  the body turbofish's marker, specializing the wrong site; `class Gen<T> extends
  namespace\Base` with a colliding `use Other\Base` in scope emitted the generated
  class extending `\Other\Base` — a `use` alias never applies to a relative name —
  and the same capture hit every marked type position, generic-method receiver
  typing, and the conformance hierarchy (where a wrong parent edge could
  false-reject a valid factory); and `namespace\Thing` colliding with a type
  parameter was substituted like one, emitting `int $x` where the author wrote an
  explicit class reference. Markers now record the raw source spelling
  (case-folding only the `namespace` keyword), every resolver honors the node's
  own qualification, fully-qualified call sites rewrite to their specializations,
  and relative names bind to the current namespace — exactly as PHP does.
- **Generic markers bind byte-exact — two same-spelling sites on one line can no
  longer steal each other's markers.** Marker matching was line-keyed
  (first-traversed-wins), so `f(Box $a, Box<int> $b)` emitted the specialization
  on the **wrong parameter**; a plain return hint could steal a
  `new Box::<int>(...)` marker, leaving a raw `new` against the marker interface;
  one-line same-name conditional classes handed the generic clause to the plain
  class — rewriting it into an uninstantiable marker interface; and
  `Plain::pick(5) + Util::pick::<int>(4)` on one line **false-rejected both
  calls** (the plain call's line-range claimed the generic call's marker). Every
  marker now matches on the byte of the token it anchors at, translated through
  the byte-offset map — exact across multi-line member chains, length-changing
  `T[]` rewrites, interpolated-string turbofish, and multibyte identifiers.
- **A generic closure that nothing specializes is now rejected**
  (`xphp.unspecialized_generic_closure`) instead of silently emitting raw
  type-parameter hints. Specialization is call-site-driven, so a generic
  closure/arrow with no in-scope grounding `$var::<...>(...)` call kept hints
  naming the non-existent class `App\T` in the emitted output — a `TypeError` on
  first invocation behind a clean compile and a clean `check`, including when the
  value was only handed away as a callable (`array_map($f, ...)`, a returned
  factory) — which cannot ground it. Both modes reject every such shape:
  assigned-but-uncalled, return-position, argument-position, `use (...)`-
  capturing, defaulted, conditional arms, and — uniformly — a clause whose
  parameters are never referenced (dead syntax; delete it). A template whose
  call sites drew their own rejection (static closure, `$this` capture, missing
  turbofish) is not double-reported, and a turbofish with still-abstract type
  arguments counts as a real call.

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
