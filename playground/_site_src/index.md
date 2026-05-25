---
title: "xphp playground — unknown"
layout: default
---

# xphp playground

Browse the `.xphp` sources and the compiled artifacts from a recent run.

| | |
|---|---|
| Branch | `unknown` |
| Commit | [`unknown`](https://github.com/xphp-lang/xphp) |
| Built | `2026-05-21T05:47:38Z` |
| Source | [https://github.com/xphp-lang/xphp](https://github.com/xphp-lang/xphp) |

## Source `.xphp` files (22)
{: id="source"}

Authored input that the xphp compiler consumes. Generic templates live under `Containers/`; the demo scripts under `Demos/` instantiate them.

- [`Containers/Box.xphp`](src/Containers/Box.xphp.md)
- [`Containers/Collection.xphp`](src/Containers/Collection.xphp.md)
- [`Containers/InMemoryRepository.xphp`](src/Containers/InMemoryRepository.xphp.md)
- [`Containers/Map.xphp`](src/Containers/Map.xphp.md)
- [`Containers/Pair.xphp`](src/Containers/Pair.xphp.md)
- [`Containers/Repository.xphp`](src/Containers/Repository.xphp.md)
- [`Containers/StringableBox.xphp`](src/Containers/StringableBox.xphp.md)
- [`Containers/Util.xphp`](src/Containers/Util.xphp.md)
- [`Containers/Wrapper.xphp`](src/Containers/Wrapper.xphp.md)
- [`Demos/ArraySugar.xphp`](src/Demos/ArraySugar.xphp.md)
- [`Demos/Bounds.xphp`](src/Demos/Bounds.xphp.md)
- [`Demos/GenericFunction.xphp`](src/Demos/GenericFunction.xphp.md)
- [`Demos/GenericInterface.xphp`](src/Demos/GenericInterface.xphp.md)
- [`Demos/GenericMethod.xphp`](src/Demos/GenericMethod.xphp.md)
- [`Demos/InstanceofTemplate.xphp`](src/Demos/InstanceofTemplate.xphp.md)
- [`Demos/MultiType.xphp`](src/Demos/MultiType.xphp.md)
- [`Demos/NestedTransitive.xphp`](src/Demos/NestedTransitive.xphp.md)
- [`Demos/SingleType.xphp`](src/Demos/SingleType.xphp.md)
- [`Models/Food.xphp`](src/Models/Food.xphp.md)
- [`Models/Plastic.xphp`](src/Models/Plastic.xphp.md)
- [`Models/Tag.xphp`](src/Models/Tag.xphp.md)
- [`Models/User.xphp`](src/Models/User.xphp.md)

## Rewritten user code (`var/dist/`) (22)
{: id="dist"}

Each `.xphp` source rewritten to native PHP — generic call sites get pointed at the matching specialized class.

- [`Containers/Box.php`](var/dist/Containers/Box.php.md)
- [`Containers/Collection.php`](var/dist/Containers/Collection.php.md)
- [`Containers/InMemoryRepository.php`](var/dist/Containers/InMemoryRepository.php.md)
- [`Containers/Map.php`](var/dist/Containers/Map.php.md)
- [`Containers/Pair.php`](var/dist/Containers/Pair.php.md)
- [`Containers/Repository.php`](var/dist/Containers/Repository.php.md)
- [`Containers/StringableBox.php`](var/dist/Containers/StringableBox.php.md)
- [`Containers/Util.php`](var/dist/Containers/Util.php.md)
- [`Containers/Wrapper.php`](var/dist/Containers/Wrapper.php.md)
- [`Demos/ArraySugar.php`](var/dist/Demos/ArraySugar.php.md)
- [`Demos/Bounds.php`](var/dist/Demos/Bounds.php.md)
- [`Demos/GenericFunction.php`](var/dist/Demos/GenericFunction.php.md)
- [`Demos/GenericInterface.php`](var/dist/Demos/GenericInterface.php.md)
- [`Demos/GenericMethod.php`](var/dist/Demos/GenericMethod.php.md)
- [`Demos/InstanceofTemplate.php`](var/dist/Demos/InstanceofTemplate.php.md)
- [`Demos/MultiType.php`](var/dist/Demos/MultiType.php.md)
- [`Demos/NestedTransitive.php`](var/dist/Demos/NestedTransitive.php.md)
- [`Demos/SingleType.php`](var/dist/Demos/SingleType.php.md)
- [`Models/Food.php`](var/dist/Models/Food.php.md)
- [`Models/Plastic.php`](var/dist/Models/Plastic.php.md)
- [`Models/Tag.php`](var/dist/Models/Tag.php.md)
- [`Models/User.php`](var/dist/Models/User.php.md)

## Specialized classes (`var/cache/`) (10)
{: id="cache"}

Monomorphized class per unique generic instantiation. Loaded via the `XPHP\Generated\` PSR-4 prefix. Class shortnames are SHA-256-derived hashes of the canonical argument list.

- [`Generated/App/Containers/Box/T_3036c16466fa04e2ed024d6b75086b6a023199482af4f6a278a33d8bd9ebe44f.php`](var/cache/Generated/App/Containers/Box/T_3036c16466fa04e2ed024d6b75086b6a023199482af4f6a278a33d8bd9ebe44f.php.md)
- [`Generated/App/Containers/Box/T_de1e0eaabedbafa176d971782f59025438ff880765b54fb07b0698700f21a0cd.php`](var/cache/Generated/App/Containers/Box/T_de1e0eaabedbafa176d971782f59025438ff880765b54fb07b0698700f21a0cd.php.md)
- [`Generated/App/Containers/Collection/T_d59a159d1113876f898c70f94a3971da6bb3165b1a8f0c07236c92d911df85ed.php`](var/cache/Generated/App/Containers/Collection/T_d59a159d1113876f898c70f94a3971da6bb3165b1a8f0c07236c92d911df85ed.php.md)
- [`Generated/App/Containers/InMemoryRepository/T_d59a159d1113876f898c70f94a3971da6bb3165b1a8f0c07236c92d911df85ed.php`](var/cache/Generated/App/Containers/InMemoryRepository/T_d59a159d1113876f898c70f94a3971da6bb3165b1a8f0c07236c92d911df85ed.php.md)
- [`Generated/App/Containers/Map/T_ddc02d7f9d24d965819939d5b07227efa95da520d87d6728a4c41906ecce5bca.php`](var/cache/Generated/App/Containers/Map/T_ddc02d7f9d24d965819939d5b07227efa95da520d87d6728a4c41906ecce5bca.php.md)
- [`Generated/App/Containers/Pair/T_307894382bcf10563dfe258ab0eef6cf6d925ef67d412e2b8743703540f18bdd.php`](var/cache/Generated/App/Containers/Pair/T_307894382bcf10563dfe258ab0eef6cf6d925ef67d412e2b8743703540f18bdd.php.md)
- [`Generated/App/Containers/Pair/T_3ca1518773f5e15bb180bc027c8394e76250619a79189c7cb33652fd9815b2ed.php`](var/cache/Generated/App/Containers/Pair/T_3ca1518773f5e15bb180bc027c8394e76250619a79189c7cb33652fd9815b2ed.php.md)
- [`Generated/App/Containers/Repository/T_d59a159d1113876f898c70f94a3971da6bb3165b1a8f0c07236c92d911df85ed.php`](var/cache/Generated/App/Containers/Repository/T_d59a159d1113876f898c70f94a3971da6bb3165b1a8f0c07236c92d911df85ed.php.md)
- [`Generated/App/Containers/StringableBox/T_9ee204cfe5d624be0e92ae24c31f6ca5ac1055bf09f6e08d6c63ea8845032b8c.php`](var/cache/Generated/App/Containers/StringableBox/T_9ee204cfe5d624be0e92ae24c31f6ca5ac1055bf09f6e08d6c63ea8845032b8c.php.md)
- [`Generated/App/Containers/Wrapper/T_de1e0eaabedbafa176d971782f59025438ff880765b54fb07b0698700f21a0cd.php`](var/cache/Generated/App/Containers/Wrapper/T_de1e0eaabedbafa176d971782f59025438ff880765b54fb07b0698700f21a0cd.php.md)

