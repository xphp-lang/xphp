# Errors and diagnostics

Every compile-time rejection from xphp lists the error message
verbatim below, paired with the docs section that explains the
constraint. Search this page for the text your compile output shows.

## Quick index

| If the message contains... | Read |
|----------------------------|------|
| `captures \`$this\`` | [Caveats — `$this`-capturing arrows and closures](caveats.md#this-capturing-arrows-and-closures-rejected) |
| `static closures cannot yet be specialized` | [Caveats — `static` closures not supported](caveats.md#static-closures-not-supported) |
| `Variance markers \`+T\` / \`-T\` are not yet supported` | [Caveats — variance markers are class-level only](caveats.md#variance-markers-are-class-level-only) |
| `Variance violation in template` | [Variance](syntax/variance.md) |
| `Generic bound violated` | [Type bounds](syntax/type-bounds.md) |
| `Default for generic parameter \`...\` violates the parameter's bound` | [Type bounds](syntax/type-bounds.md) + [Defaults](syntax/defaults.md) |
| `has no default but follows a parameter with a default` | [Defaults — required-after-default rule](syntax/defaults.md) |
| `has an invalid default; only a single concrete or generic type is allowed` | [Caveats — invalid default expression shape](caveats.md#invalid-default-expression-shape) |
| `cannot use itself as a bound` | [Type bounds — F-bounded](syntax/type-bounds.md) |
| `already declared` ... `duplicate declaration` | [Caveats — duplicate generic template declaration](caveats.md#duplicate-generic-template-declaration) |
| `was instantiated but never defined` | The template was used but no source file declared it — typo or missing import |
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
Variance markers `+T` / `-T` are not yet supported on methods,
functions, closures, or arrow functions; move the generic to a
class-level type parameter.
```

### Variance composition violation

```
Variance violation in template <Template>: type-parameter <+|->T
appears in <invariant|covariant|contravariant>-only position
(via slot N of <InnerTemplate>).
```

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

### Parse / AST

```
Parser returned null AST.
```
