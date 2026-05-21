# Contributing 

## Testing

### Unit tests

```bash
make test/unit
```

Most tests are pure unit tests against `XPHP\Transpiler\Monomorphize\*`. The handful of
**integration tests** compile a fixture under `test/fixture/compile/<name>/` end-to-end and
either assert on the emitted text or autoload the result and call into it at runtime. Those
runtime tests have one isolation gotcha worth knowing before you add a new one.

#### Cross-fixture class-table collisions

`Registry::generatedFqn` (`src/Transpiler/Monomorphize/Registry.php`) names every specialized
class as:

```
XPHP\Generated\<template-FQN>  \  T_<sha256(canonical-args)>
└── namespace, mirrors template ─┘  └── hash of args ONLY ──┘
```

The hash covers the **argument list**, not the template's own FQN — the template's FQN is
already encoded in the namespace path. That's deliberate and right for a single compile: it
collapses identical instantiations into one class file.

It bites in tests because **multiple fixtures redeclare the same template path**. Five
fixtures define an `App\Containers\Box<T>` (with subtly different bodies — some have a
constructor, the `generic_interface` one implements `Container<T>`, etc.) and all of them
instantiate it with `App\Models\Plastic`. Same template path + same arg means same generated
FQN: `XPHP\Generated\App\Containers\Box\T_de1e0eaa…`. The on-disk files live in different
per-test work directories, but **PHP's class table is process-wide** — once any integration
test `new`s up that FQN, PHP caches *that* body for the rest of the phpunit run. A
later test loading from a different fixture's work dir gets the stale class.

The failure mode is shape-dependent and the test ordering is randomized, so this surfaces as
an intermittent flake. Two known-good patterns to avoid it:

1. **Reflection-only assertions** when you're verifying the structural contract (an interface
   chain, an `implements` link, the type of a property) rather than runtime behavior.
   `ReflectionClass::implementsInterface($marker)`, `::getParentClass()`,
   `(new ReflectionProperty($fqn, 'item'))->getType()->getName()` all read the class metadata
   without depending on which body PHP's class table happens to hold. **Example:**
   `test/Transpiler/Monomorphize/CompilerIntegrationTest.php::testInstanceofAgainstOriginalTemplateMatchesAllSpecializations`.

2. **Fixture-unique concrete types** when you legitimately need `new $fqn(...)` at runtime.
   Add a model class that's only declared inside your fixture (e.g.
   `test/fixture/compile/generic_interface/source/Models/Polymer.xphp`) and instantiate
   against `Box<Polymer>`. Other fixtures don't redefine `Box<Polymer>`, so the hash is
   unique and PHP's class table can't cache a competing body. **Example:**
   `test/Transpiler/Monomorphize/GenericInterfaceIntegrationTest.php::testSpecializedClassIsInstanceOfOriginalInterfaceMarker`.

Rule of thumb: if your test does `new $generatedFqn(...)` and the concrete type is `Plastic`,
`Metal`, `User`, `Food`, or any other name that already lives in another fixture, switch to
one of the two patterns above before pushing — random-order CI will catch it eventually, and
the diagnostic (`ArgumentCountError`, `instanceof returns false`) won't point at the root
cause.

### Mutation tests

Mutation testing is the headline quality signal -- the test suite isn't just covering lines, it's surviving deliberate
code perturbations. Run via [Infection](https://infection.github.io/):

```bash
make test/mutation
```

**Current state (581 mutants generated):**

| Outcome              | Count   | Notes                                                             |
|----------------------|---------|-------------------------------------------------------------------|
| Killed by tests      | 542     | An assertion failed under the mutated code                        |
| Killed by timeout    | 8       | The mutation caused an infinite loop (e.g. the depth-cap fixture) |
| **Escaped**          | **31**  | See breakdown below                                               |
| **Covered Code MSI** | **94%** |                                                                   |

The 31 escapes split cleanly:

- **8 mathematically equivalent** -- `break` vs `continue` after `unset`; `>` vs `>=` on a bound whose message is
  constant either way; double-slash paths the filesystem normalizes; ltrim calls on values that are already-trimmed at
  insertion. No test can kill these without the source becoming less defensive.
- **22 scanner boundary checks** -- `<` vs `<=` on `$i < $n` end-of-stream guards in the manual token walker. Killing
  them requires synthesizing token streams that end exactly at the boundary the mutation flips. High effort per mutant,
  low signal for real-world correctness; well-formed PHP source never hits them.
- **0 mutations corresponding to a real-world bug class** that the suite isn't catching.

The CI workflow runs Infection on every PR and every push to `main`, failing the build if MSI drops below **93%**.
`infection.json5` carries a curated set of per-mutator `ignore` rules for equivalent / cosmetic cases so the report only
surfaces genuine test gaps when they appear.