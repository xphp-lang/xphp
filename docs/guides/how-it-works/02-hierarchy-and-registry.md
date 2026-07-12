# Stage 2 -- Hierarchy and Registry construction

[← How the xphp compiler works](../how-it-works.md)

Two parallel structures get populated from the parsed ASTs.

**`TypeHierarchy`** ([source](../../../src/Transpiler/Monomorphize/TypeHierarchy.php))
is a direct-ancestor map keyed by FQN: for every `class Foo extends Bar
implements Baz` declaration, it records `Foo => [Bar, Baz]`. Transitive
ancestors are walked on demand via `isSubtype()`. A small whitelist of
PHP built-in interfaces (`Stringable`, `Countable`, `Iterator`, `Traversable`,
`ArrayAccess`, `JsonSerializable`, `Throwable`, etc.) is treated as known
even without a source declaration -- a user class that explicitly
`implements \Stringable` resolves its bound without the hierarchy
needing to model PHP's internal class table.

**`Registry`** ([source](../../../src/Transpiler/Monomorphize/Registry.php))
is the bookkeeping core of monomorphization. It holds two maps:

- `definitions: array<string, GenericDefinition>` keyed by template
  FQN -- one entry per `class Foo<T>` / `interface Foo<T>` / `trait
  Foo<T>` / `function foo<T>(...)` template.
- `instantiations: array<string, GenericInstantiation>` keyed by
  the generated FQN -- one entry per concrete `(template, args)`
  pair seen anywhere in the source set, including transitively
  through specialization.

Each map value is a small value object:

- [`GenericDefinition`](../../../src/Transpiler/Monomorphize/GenericDefinition.php)
  carries `(templateFqn, templateShortName, typeParams, templateAst,
  sourceFile)`.
- [`GenericInstantiation`](../../../src/Transpiler/Monomorphize/GenericInstantiation.php)
  carries `(templateFqn, concreteTypes, generatedFqn)`.

**`RegistryCollector`** ([source](../../../src/Transpiler/Monomorphize/RegistryCollector.php))
walks an AST and calls `Registry::recordDefinition()` for every
template ClassLike and `Registry::recordInstantiation()` for every
Name node carrying concrete generic args.

`recordInstantiation()` is recursive: when called on
`Wrapper<Box<Plastic>>`, it first records `Box<Plastic>` (so the
inner generic shows up before the outer one) and then `Wrapper<...>`.
Bounds are validated inside `recordInstantiation()` -- see
[Stage 6 -- Bound validation](06-bound-validation.md).

---

Prev: [Stage 1 -- Source parsing](01-source-parsing.md) · [Index](../how-it-works.md) · Next: [Stage 3 -- Method- and function-scope specialization](03-method-function-specialization.md)
