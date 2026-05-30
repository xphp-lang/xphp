# xphp core

This is the package downstream `xphp` tools consume. e.g. LSP, PhpStorm plugin
and VSCode extension.

It contains an ahead-of-time compiler that converts `xphp` source into normal
`php` code compatible with `PSR-4`. It's installed as a `--dev` package,
**no need** for additional runtime packages.

## Status

The following features are already supported:

- Single-parameter generics (`class Box<T>`)
- Multi-parameter generics (`Pair<K, V>`, any arity)
- Nested generics at arbitrary depth (`Box<Lst<Plastic>>`)
- Type-hint positions everywhere (param / return / property / `new`)
- Transitive fixed-point specialization (16-iteration depth cap)
- Generic interfaces (`interface Container<T>`)
- Generic traits as templates (dropped after specialization)
- Method-scoped generics (`function NAME<T>(...)` inside a class; static calls)
- Free generic functions (`function NAME<T>(...)` at namespace scope)
- Type-parameter bounds (`class Box<T: \Stringable>`)
- `instanceof` against the original template (marker interface)
- Collision-safe generated FQCN (`XPHP\Generated\<template>\T_<sha256>`)
- Build-time hash-collision detection with widen command
- `XPHP_HASH_LENGTH` configurable (16..64)
- `bin/xphp compile <source-dir> <target-dir> <cache-dir>` CLI

## Install

```bash
composer require --dev xphp-lang/core
```

## Run

```bash
vendor/bin/xphp compile <source> <target> <cache>
```

- `<source>`
  - the directory tree of `xphp` files (`PSR-4` layout).
- `<target>`
  - where compiled `php` files land, mirroring the source layout.
- `<cache>`
  - where generated specialization classes go
  - one `XPHP\Generated\<template>\T_<hash>.php` per `type-arg` list

## See also:

- [Roadmap](./docs/roadmap.md)
- [Generics](./docs/type-system/generics/index.md)
- [Type-system comparison](./docs/type-system/comparison.md)
- [Compiler](./docs/compiler.md)