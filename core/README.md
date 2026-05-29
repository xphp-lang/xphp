# xphp core (compiler)

Ahead-of-time compiler that turns `.xphp` source -- PHP plus generic
templates (`class Box<T>`, `function identity<T>(T $x): T`, `T[]`
sugar, `T: Bound` type-parameter bounds) -- into vanilla `.php` that
runs on any stock PHP 8.4 runtime, with no runtime support library.

This is the package downstream tools consume.  The Language Server
(`tools/lsp/`), the demo workspace (`playground/`), and indirectly
the PhpStorm plugin and VS Code extension all build on top of this
package's `XphpSourceParser` + `Registry` + `TypeHierarchy` +
`Specializer`.

## Status

| Feature | Status |
|---|---|
| Single-parameter generics (`class Box<T>`) | shipped |
| Multi-parameter generics (`Pair<K, V>`, any arity) | shipped |
| Nested generics at arbitrary depth (`Box<Lst<Plastic>>`) | shipped |
| Type-hint positions everywhere (param / return / property / `new`) | shipped |
| Transitive fixed-point specialization (16-iteration depth cap) | shipped |
| Generic interfaces (`interface Container<T>`) | shipped |
| Generic traits as templates (dropped after specialization) | shipped |
| Method-scoped generics (`function NAME<T>(...)` inside a class; static calls) | shipped |
| Free generic functions (`function NAME<T>(...)` at namespace scope) | shipped |
| Type-parameter bounds (`class Box<T: \Stringable>`) | shipped |
| `instanceof` against the original template (marker interface) | shipped |
| Collision-safe generated FQCN (`XPHP\Generated\<template>\T_<sha256>`) | shipped |
| Build-time hash-collision detection with widen command | shipped |
| `XPHP_HASH_LENGTH` configurable (16..64) | shipped |
| `bin/xphp compile <source-dir> <target-dir> <cache-dir>` CLI | shipped |

See [`docs/generics.md`](/docs/generics.md) for the full language
reference and [`roadmap.md`](roadmap.md) for what's next.

## Install

```bash
cd core
composer install
```

The package's published Composer name is `xphp-lang/xphp`.  Downstream
packages in this monorepo (`tools/lsp/`, `playground/`) declare a
path-repo dependency at `../../core/` / `../core/` and pull this
package via `composer install` in their own directory.

## Run

```bash
core/bin/xphp compile <source-dir> <target-dir> <cache-dir>
```

- `<source-dir>` -- the directory tree of `.xphp` files (PSR-4
  layout).
- `<target-dir>` -- where compiled `.php` files land, mirroring the
  source layout.
- `<cache-dir>` -- where generated specialization classes go (one
  `XPHP\Generated\<template>\T_<hash>.php` per unique type-arg list).

`playground/bin/run` is a thin wrapper that invokes this binary
against `playground/src/` for the demo workspace.

## Layout

```
core/
|-- composer.json              # xphp-lang/xphp, depends on nikic/php-parser + symfony/console
|-- bin/xphp                   # Symfony Console entrypoint -> compile command
|-- src/
|   |-- Console/
|   |   |-- ApplicationConsole.php
|   |   `-- Command/CompileCommand.php
|   |-- FileSystem/            # tiny adapter layer (FileFinder, FileReader, FileWriter)
|   `-- Transpiler/Monomorphize/
|       |-- XphpSourceParser.php           # scans the .xphp source, strips <...> clauses
|       |-- ByteOffsetMap.php              # stripped-source <-> original-source positions
|       |-- TypeRef.php, TypeParam.php     # type-system value objects
|       |-- Registry.php                   # template + instantiation registry
|       |-- RegistryCollector.php          # walks AST, populates Registry
|       |-- TypeHierarchy.php              # cross-file subtype relation for bound validation
|       |-- Specializer.php                # substitutes type params, mangles names
|       |-- SpecializedClassGenerator.php  # emits the generated .php file per specialization
|       |-- CallSiteRewriter.php           # rewrites call sites to mangled FQNs
|       |-- GenericMethodCompiler.php      # method- and function-scope generics
|       `-- Compiler.php                   # orchestrates the fixed-point loop
|-- test/                                  # PHPUnit suite (unit + integration fixtures)
|-- Makefile                               # `test/unit` and `test/mutation`
|-- infection.json5
`-- phpunit.xml.dist
```

## Test

```bash
make -C core test/unit        # PHPUnit
make -C core test/mutation    # Infection, MSI under a 95 % gate
```

The mutation suite finishes in ~45s on a 4-core machine; CI gates
every PR on both targets.  `infection.json5` carries a curated set
of per-mutator `ignore` rules for genuinely-equivalent / defensive
mutations so the report only surfaces real test gaps.

Cross-fixture class-table collisions are a known integration-test
gotcha -- see [CONTRIBUTING.md](/CONTRIBUTING.md) for the two
patterns (reflection-only assertions or fixture-unique concrete
types) that keep them out.

## Platform

- PHP `^8.4` runtime
- Direct runtime deps: `nikic/php-parser`, `symfony/console`
- Test deps: PHPUnit 13, Infection 0.33
- `composer.json` pins `config.platform.php = "8.4.21"` so package
  resolution targets the runtime regardless of which PHP runs
  Composer.

## Why this layer is monomorphization, not erasure

xphp specializes generics at compile time -- every `Box<Plastic>`
expands to a distinct, fully-typed class file with `Plastic` baked
into every signature.  The Zend Engine then sees plain PHP classes
and runs them without any awareness that xphp existed.

The consequence: `instanceof T`, `T::class`, `is_a($x, T::class)` all
work at runtime (T resolves to the concrete type at specialization
time), which is something Java / Kotlin / TypeScript can't promise
because they erase generics.  Variance annotations on this base
become real subtype edges between specialized classes -- a roadmap
item ([`roadmap.md`](roadmap.md)).

For the side-by-side type-system comparison see
[`docs/generics-comparison.md`](/docs/generics-comparison.md).
