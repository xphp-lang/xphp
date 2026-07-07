# Errors and diagnostics

Every compile-time rejection from xphp lists the error message
verbatim below, paired with the docs section that explains the
constraint. Search this page for the text your compile output shows.

## `xphp check` — validate without emitting

`vendor/bin/xphp check <source-dir>` validates your generic `.xphp`
code and reports **every** problem below as a structured diagnostic —
each with a `file:line` location — instead of aborting on the first.
It writes no output (no `dist/`, no cache); it's a pure gate, ideal
for CI.

```bash
vendor/bin/xphp check src
```

Output formats via `--format`:

| Format | Use |
|--------|-----|
| `text` (default) | human-readable, one block per problem |
| `json` | machine-readable; stable `{ "diagnostics": [ … ] }` shape for tooling |
| `github` | GitHub Actions annotations (`::error file=…,line=…::…`) so problems show inline on a PR |

Exit codes: **0** (clean), **1** (at least one error), **2** (bad
source directory or unknown `--format`). A file that fails to parse is
reported and skipped, so the rest of the tree is still checked in the
same run.

### Diagnostic codes

The `json` and `github` formats tag each diagnostic with a stable code:

| Code | Meaning |
|------|---------|
| `xphp.bound_violation` | a concrete type argument doesn't satisfy its parameter's bound |
| `xphp.default_bound_violation` | a parameter's default doesn't satisfy its own bound |
| `xphp.missing_type_argument` | a required type argument was omitted and has no default — including a **turbofish-less call** to a generic method, function, or closure (`$x->pick('a')` instead of `$x->pick::<string>('a')`): a method generic takes no inference, so the type argument must be supplied explicitly |
| `xphp.too_many_type_arguments` | more type arguments were supplied than the template declares (e.g. `Box::<int, string>` for a one-parameter `Box`) |
| `xphp.variance_position` | an `out T` / `in T` parameter appears in a position its variance forbids |
| `xphp.inner_variance` | variance is violated through another generic's slot (composition) |
| `xphp.undefined_template` | a generic was instantiated but never declared |
| `xphp.undeclared_type` | a generic member, bound, or default names a type that is neither a declared type parameter nor a known type — e.g. `interface Foo<Z> { add(T $x); }` or `class Box<T: Nonexistent>` where the name is a stray/typo'd parameter (would otherwise compile to a reference to a non-existent class). Imported (`use`) and fully-qualified names are never flagged. A real class referenced bare must be imported or fully-qualified — including a class in the *same namespace* that lives in a plain `.php` file (which `xphp check` doesn't scan), even though PHP itself wouldn't require the `use` |
| `xphp.duplicate_generic_function` | the same generic function is declared in two files |
| `xphp.closure_this_capture` | a generic closure/arrow used via turbofish captures `$this` (unsupported) |
| `xphp.static_closure` | a generic `static` closure used via turbofish (unsupported) |
| `xphp.unspecialized_generic_closure` | a generic closure/arrow is declared but no in-scope `$var::<...>(...)` call grounds its type parameters — the emitted value would keep raw hints naming non-existent classes (`App\T`) and fatal on first invocation, even when only handed away as a callable (which cannot ground it). Call it with a turbofish in the scope that declares it, or remove the `<...>` clause (also flagged when the parameters are never referenced — the clause is dead syntax) |
| `xphp.unresolved_generic_call` | a turbofish method call (`$obj->m::<…>()` / `Foo::m::<…>()`) names a generic method that can't be resolved on the receiver's type — a typo or wrong receiver type, caught at compile time instead of fataling at runtime |
| `xphp.bound_unprovable` | a method-generic bound that references an enclosing class type parameter (`contains<U : E>`) can't be proven because the receiver's type argument isn't determinable here — a raw `Box` with no argument, a branch whose arms disagree, a static call, or a `$this` self-call. Ground the receiver (bind it to a typed local) or the build fails |
| `xphp.undetermined_receiver` | a turbofish method call's receiver has no statically-known type (an untyped `foreach` variable, a local whose type is ambiguous after a branch), so the call can't be specialized — it would emit a call to a stripped method that fatals at runtime. Give the receiver a declared type |
| `xphp.unspecializable_self_call` | a `$this`-rooted self-call forwards a type parameter to a **non-erasable** generic method (one whose parameter is used nested, in the return, or structurally). Forwarding to an *erasable* method — parameter used only as a direct input — compiles and runs; otherwise move the call to a typed-receiver context |
| `xphp.unschedulable_covariant_upcast` | a value is upcast to a covariant *interface* whose element-consuming method (`contains<U : E>`) needs a concrete implementation at the supertype argument that can neither be inherited through the covariant chain nor emitted directly onto the upcast source. Direct emission already covers the cases where inheritance can't carry it (the implementing class has another `extends` parent, implements only a parent of the interface, or reorders the clause); the upcast fails only when **no** emittable class body exists (a truly abstract or trait-only method), the method's **return type** names the element parameter (the widened argument would escape through a narrower return), or its parameters are bounded by **different** enclosing parameters (no single member can be derived). Provide a concrete implementation on a class — move a trait body onto the covariant base, or give the method a non-element return type |
| `xphp.closure_conformance` | a closure literal returned against a `Closure(...)` type doesn't conform to it — its parameters aren't wide enough, its return isn't narrow enough, its by-reference-ness differs, or its arity is incompatible |
| `xphp.parse_error` | the file isn't valid PHP after the generic strip pass |
| `phpstan.*` | a PHPStan finding in the compiled output, mapped back to the template declaration (the code is `phpstan.` + PHPStan's own identifier, e.g. `phpstan.return.type`; a finding that carries no identifier falls back to the literal `phpstan.error`) — present only when the PHPStan pass runs |
| `phpstan.unavailable` | (Warning) no phpstan binary was found, so the PHPStan pass was skipped |
| `phpstan.run_failed` | (Warning) phpstan was found but couldn't complete (e.g. a config error) |

> **Scope.** `xphp check` runs every generic *validation* check `xphp compile`
> does — class/interface/trait-level **and** method/function/closure-level
> (bounds, variance, defaults, missing/duplicate generics, unsupported closures).
> By design it does not run the specialization loop or emit code, so the two
> runaway/config guards — the nested-specialization **depth cap** and the
> **hash-collision** check — surface only at `xphp compile` (they aren't type
> errors). You still run `xphp compile` to produce the PHP; `check` is the fast
> validation gate in front of it.

### PHPStan over the compiled output

PHPStan never sees `.xphp` generic sugar, so it can't analyse a generic body. When
the generic checks above pass, `xphp check` closes that gap: it compiles your
sources to a throwaway directory, runs **your** PHPStan over the concrete
(monomorphized) output, and maps any finding back to the originating `.xphp`
template declaration — the diagnostic names the concrete instantiation that
surfaced it (e.g. *triggered by `App\Box<int>`*). The findings merge into the same
report and exit code as the generic checks: **one PHPStan, one config, one gate.**

- **One config.** Your own config drives the level, rules, and extensions —
  auto-detected at the project root (`phpstan.neon`, then `phpstan.neon.dist`,
  then `phpstan.dist.neon`), or pass `--phpstan-config=PATH`. xphp adds only what's
  needed to resolve the generated code's symbols; it picks no level of its own.
- **Opt-out + graceful.** `phpstan/phpstan` is an optional, `require-dev`-style
  dependency and is never bundled in the PHAR. The binary is resolved as
  `--phpstan-bin=PATH` → `vendor/bin/phpstan` → `$PATH`; if none is found (or an
  explicit `--phpstan-bin` doesn't exist), or phpstan can't complete, `check`
  emits a **Warning** and carries on — a missing optional tool never fails the
  gate. Pass `--no-phpstan` to skip the pass entirely.
- **One representative per template.** A body type error is identical across every
  specialization of a template, so xphp analyses a single representative
  specialization per template — surfacing the bug once, not once per instantiation.
  (Trade-off: a body error that only manifests for *specific* concrete arguments may
  be missed; that's the value-flow class PHPStan can't attribute to a template line
  anyway.)

```bash
vendor/bin/xphp check src                      # generic checks + PHPStan, one gate
vendor/bin/xphp check src --no-phpstan          # generic checks only
vendor/bin/xphp check src --phpstan-config=phpstan.neon.dist
```

In CI (GitHub Actions), one step gates the build and annotates the diff:

```yaml
- run: vendor/bin/xphp check src --format=github
```

## Quick index

| If the message contains... | Read |
|----------------------------|------|
| `captures \`$this\`` | [Caveats — `$this`-capturing arrows and closures](caveats.md#this-capturing-arrows-and-closures-rejected) |
| `static closures cannot yet be specialized` | [Caveats — `static` closures not supported](caveats.md#static-closures-not-supported) |
| `Variance markers \`out T\` / \`in T\` are not supported` | [Caveats — variance markers are class-level only](caveats.md#variance-markers-are-class-level-only) |
| `Variance violation in template` | [Variance](syntax/variance.md) |
| `Generic bound violated` | [Type bounds](syntax/type-bounds.md) |
| `Default for generic parameter \`...\` violates the parameter's bound` | [Type bounds](syntax/type-bounds.md) + [Defaults](syntax/defaults.md) |
| `has no default but follows a parameter with a default` | [Defaults — required-after-default rule](syntax/defaults.md) |
| `has an invalid default; only a single concrete or generic type is allowed` | [Caveats — invalid default expression shape](caveats.md#invalid-default-expression-shape) |
| `cannot use itself as a bound` | [Type bounds — F-bounded](syntax/type-bounds.md) |
| `already declared` ... `duplicate declaration` | [Caveats — duplicate generic template declaration](caveats.md#duplicate-generic-template-declaration) |
| `was instantiated but never defined` | The template was used but no source file declared it — typo or missing import |
| `could not be resolved to a declared generic method` | A turbofish method call names a generic method that can't be resolved on the receiver's type — check the method name or the receiver's type. |
| `Cannot verify generic bound` | [Type bounds — ground or fail](syntax/type-bounds.md#ground-or-fail) — the receiver's type argument isn't determinable; bind it to a typed local. |
| `Cannot determine the receiver's type` | [Turbofish — receiver-type analysis](syntax/turbofish.md#receiver-type-analysis-instance-methods) — give the receiver a declared type. |
| `Cannot specialize the self-call` | [Type bounds — ground or fail](syntax/type-bounds.md#ground-or-fail) — the forward targets a non-erasable method; forward to an erasable one or move the call to a typed-receiver context. |
| `was instantiated with N type argument(s) but parameter ... has no default` | [Defaults](syntax/defaults.md) — supply all required args or add defaults |
| `Nested generic specialization exceeded depth` | A generic refers to itself transitively too deeply (compiler aborts at depth 16) — usually a recursive instantiation cycle. Refactor to break the cycle. |
| `Parser returned null AST` | The source file isn't valid PHP after the generic strip pass. Run `php -l <file>.xphp` mentally on the cleaned source — most often a syntax error in the user code that's unrelated to generics. |

## Full error texts (verbatim)

For grep-from-output workflows, here are the message strings exactly
as the compiler emits them.

### `$this`-capturing arrows / closures

```
Generic <arrow|closure> `$<var>::<...>(...)` captures `$this`, which
is not yet supported. Rewrite as a method on the enclosing class, or
extract the value of $this->property into a local variable before
the <arrow|closure>.
```

### `static` closures

```
Generic static closures cannot yet be specialized at call sites.
Rewrite the call site for `$<var>::<...>(...)` to use a named
generic function at file scope.
```

### Variance markers on non-class templates

```
Variance markers `out T` / `in T` are not supported on methods,
functions, closures, or arrow functions — variance is a
class-level-only feature by design: a function or closure
specialization has no stable class identity to anchor a subtype
`extends` edge to. Move the generic to a class-level type parameter.
```

### Variance composition violation

```
Variance violation in template <Template>: type-parameter <+|->T
appears in <invariant|covariant|contravariant>-only position
(via slot N of <InnerTemplate>).
```

### Variance position violation

```
Generic parameter `<+|->T` appears in <position> position, which is not
allowed for <covariant|contravariant> variance.
```

`<position>` is the forbidding position — e.g. `method parameter`,
`method return`, `mutable property`, `readonly property`, or
`by-reference parameter`. A property only forbids variance when it is
**public or protected**; a *private* property (declared or promoted) is exempt,
because PHP doesn't type-check private slots across the `extends` chain. A
**by-reference parameter** (`T &$x`) is invariant (it is read and written back),
so neither `out T` nor `in T` is allowed there.

### Variant class declared `final`

```
A variant class cannot be declared `final`: its specializations participate
in `extends` subtype edges that a `final` class cannot anchor. Remove `final`.
```

A `out T` / `in T` class is specialized into a chain of `extends`-linked classes; a
`final` class can't be a parent in that chain. Drop `final` from the declaration.

### Bound violations

```
Generic bound violated while instantiating <Template><...>.
  type parameter <T> is bounded by <Bound>
  but the supplied concrete type is <Concrete>

  <reason>
```

```
Default for generic parameter `<T>` of "<Template>" violates the
parameter's bound.
  bound:   <Bound>
  default: <Default>
  reason:  <reason>
```

### Unprovable enclosing-parameter bound

A method-generic bound that references the enclosing class parameter
(`contains<U : E>`) is checked by grounding `E` to the receiver's element
type. When that can't be determined, the bound can't be proven and the
build fails — ground or fail, never an unchecked call. See
[type bounds — ground or fail](syntax/type-bounds.md#ground-or-fail).

```php
class Box<out E> {
    public function contains<U : E>(U $value): bool { /* ... */ }
}

function pick(Box $b): bool {          // raw Box — no element type to ground E
    return $b->contains::<Banana>(new Banana());
}
```

```
Cannot verify generic bound `U : E` for App\Box::contains: the receiver's type
argument is not determinable at this call site, so the bound cannot be proven.
Bind the receiver to a typed local (e.g. `Box<Fruit> $x = ...;`) or pass it as a
typed parameter so its type arguments are known here.
```

A `$this`-rooted self-call gets a variant of the message (the receiver is
`$this`, so "bind to a typed local" doesn't apply), and a static method whose
bound names a class parameter fails the same way (no instance to ground `E`):

```php
class Box<out E> {
    public function contains<U : E>(U $value): bool { /* ... */ }
    public function probe(): bool {
        return $this->contains::<Banana>(new Banana());   // E is abstract here
    }
}
```

```
Cannot verify generic bound `U : E` for App\Box::contains in a `$this`-rooted
self-call: the bound references the enclosing class's own type parameter, which
is abstract in the class template, so it can only be checked once the class is
instantiated. Move this call to a context where the receiver has a concrete
element type (e.g. a function taking `Box<Fruit> $b` then `$b->contains::<...>(...)`),
or don't turbofish an enclosing-parameter-bounded method on `$this`.
```

This is the **direct, concrete** self-call (`$this->contains::<Banana>()`). A
self-call that **forwards a method parameter** to an *erasable* method —
`probe<U:E>(U $v) { return $this->contains::<U>($v); }` — compiles and runs (see
[type bounds](syntax/type-bounds.md)); only a forward to a *non-erasable* target
fails (next).

### Unspecializable forwarded self-call

A `$this`-rooted self-call that forwards a type parameter compiles when the
target is *erasable* (its parameter is used only as a direct input) — both
methods lower to one `E`-typed member per instantiation and the forward resolves
to it. When the target is **not** erasable (the parameter appears nested, in the
return, or structurally), the forward can't be specialized:

```php
class Box<out E> {
    public function nested<U : E>(Box<U> $items): bool { /* ... */ } // not erasable (U nested)
    public function relay<U : E>(Box<U> $items): bool {
        return $this->nested::<U>($items);                           // forwards to a non-erasable target
    }
}
```

```
Cannot specialize the self-call `$this->nested::<...>()`: it forwards a type
parameter to a generic method, which has no concrete value in the class template.
Move the call to a context where the receiver has a concrete element type (e.g. a
function taking a typed `Box<Fruit>`), or call the method on a directly-constructed
value.
```

### Undeterminable turbofish receiver

A turbofish call is specialized at compile time, and the generic method is
stripped from its class, so the receiver must have a statically-known type. An
untyped `foreach` variable or a local whose type is ambiguous after a branch
can't be specialized — leaving the call would emit a non-existent method that
fatals at runtime, so it fails at compile time instead.

```php
function pick(array $boxes): void {
    foreach ($boxes as $box) {                 // $box has no static type
        $box->contains::<Banana>(new Banana());
    }
}

// Ambiguous after a branch:
$x = new Foo();
if (mt_rand(0, 1)) { $x = new Bar(); }
$r = $x->fooId::<int>(7);                       // Foo|Bar — undeterminable
```

```
Cannot determine the receiver's type for the generic call `contains::<...>()`. A
turbofish call is specialized at compile time, so the receiver must have a
statically-known type. Give it a declared type — a typed parameter or property,
or a local assigned from `new ...::<...>()` or a typed return.
```

### Defaults

```
Generic parameter `<T>` has a default value, which is not yet
supported on static closures. Drop the `static` modifier or assign
the closure to a named function.
```

```
Generic parameter `<T>` has an invalid default; only a single
concrete or generic type is allowed after `=` (no nullable or
union shapes).
```

```
Generic parameter `<T>` has no default but follows a parameter with
a default. Required type parameters must precede defaulted ones.
```

```
Generic template "<Template>" was instantiated with N type argument(s)
but parameter `<T>` (position N) has no default; supply it explicitly
or add defaults to every preceding required parameter.
```

### F-bounded self-reference at top level

In this section only, the example output shows the actual literal
text for `T` rather than a placeholder, to avoid an unparseable
`Box<<T>>` form:

```
Generic parameter `T` cannot use itself as a bound (self-reference
detected in the bound expression). Use a nested form like
`T : Box<T>` for F-bounded recursion, or remove the bound.
```

### Duplicate declarations

```
Generic template "<FQN>" already declared (in <path1>); duplicate
declaration in <path2>.
```

```
Generic function template "<FQN>" already declared (in <path1>);
duplicate declaration in <path2>.
```

### Missing definition / depth

```
Generic template "<FQN>" was instantiated but never defined
(generated as: <generated FQN>).
```

```
Nested generic specialization exceeded depth 16. Latest registry:
<list>
```

### Closure-signature conformance

```
Closure literal does not conform to the declared `Closure(...)` type:
<detail>
```

Emitted when a closure literal is returned against a `Closure(...)` return
type it doesn't satisfy. The `<detail>` names the exact mismatch, e.g.
`parameter 1: string is not wider than int` (a parameter must be the same
as or **wider** than the target's — contravariance), `return type: A is not
a subtype of B` (the return must be the same as or **narrower** — covariance),
`by-reference-ness must match exactly`, or an arity message. See
[closure types](syntax/closure-types.md). The check only ever reports a
*provable* mismatch: an unresolved class, a still-abstract type parameter, an
untyped (⇒ `mixed`) slot, a union/intersection, or a built-in supertype is
accepted rather than falsely rejected.

### Parse / AST

```
Parser returned null AST.
```
