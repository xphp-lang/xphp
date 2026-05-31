# Contributing

## Monorepo layout

The repository hosts the `xphp` language plus the tooling that grows around it.

Every shippable artifact lives in its own sub-project with its own build system,
lockfile, tests, and CI workflow. The xphp compiler lives under `core/`;
satellites (LSP, PhpStorm plugin, etc.) live under `tools/<name>/`.

```
xphp-lang/
+-- core/                   # the xphp compiler
+-- docs/                   # language-level documentation
+-- playground/             # demo workspace that depends on the core
+-- tools/
|   +-- lsp/                # Language Server (PHP, phpactor/language-server)
|   +-- phpstorm-plugin/    # JetBrains plugin (Kotlin + Gradle)
|   `-- vscode-extension/   # VS Code client (TypeScript)
+-- .github/workflows/
|   +-- ci-core.yml         # phpunit + infection for core/
`   -- ci-<package>.yml     # one file per package under tools/
```

The split is a single principle in disguise: **the core compiler is the
product; everything else is a way to consume it**. A package that depends on
the core's published behavior (LSP analyzing `.xphp` source, an editor plugin
spawning the LSP, a CI lint tool calling `bin/xphp`) is a tool. The core
itself never depends on external `xphp` tools.

### Adding a new package

The current shape was settled when `tools/lsp/` was a dded and validated when
through `tools/phpstorm-plugin/`.

To add a tool:

1. **Create the directory** under `tools/<name>/`. Pick a name that names the
   thing concretely (`lsp`, `phpstorm-plugin`) rather than abstractly
   (`server`, `editor-integration`).

2. **Self-contained build**: each package owns its build system. The LSP has
   `tools/lsp/composer.json`; the PhpStorm plugin has
   `tools/phpstorm-plugin/build.gradle.kts` + `gradle.properties` (every
   version pin lives there so a single edit propagates to since-build,
   IDE target, Kotlin runtime, JVM toolchain). The root never collects
   per-package dependencies.

3. **Cross-package dependency on the core**: PHP packages do this via a
   path-repo back to `core/`, the way `tools/lsp/composer.json` declares
   `"xphp-lang/xphp": "@dev"` with `repositories: [{type: path, url:
   "../../core/"}]`. Other languages need their own analog. The PhpStorm plugin
   takes a different route: it doesn't compile against xphp at all -- it
   spawns the LSP as a subprocess and bundles the LSP's pre-built PHAR
   (via `processResources` copying `../lsp/var/xphp-lsp.phar`) into the
   plugin jar at build time. That's the cleanest cross-package dependency
   when the consumer doesn't actually need symbols from the dependency.

4. **Per-package Makefile**: each package ships its own `Makefile` at
   `tools/<name>/Makefile` with short, package-relative target names:

   ```make
   test:           # phpunit / gradle test / cargo test / ...
   test/mutation:  # if the package has a mutation surface
   ```

   Invoke from the repo root with `make -C tools/<name> <target>` or
   directly from inside the package with `make <target>`. The root
   `Makefile` does NOT have pass-through targets — each Makefile is the
   single source of truth for its package's recipes, no delegation
   layer to drift.

5. **Per-package CI workflow** at `.github/workflows/ci-<name>.yml`. Mirror
   the existing `ci-lsp.yml` or `ci-phpstorm-plugin.yml` shape: one
   workflow per package, each with its own concurrency group, each
   running unconditionally (no path filters — see the next section).
   `ci-phpstorm-plugin.yml` doubles as the worked example for a package
   whose CI needs **both** ecosystems (PHP to build the bundled PHAR,
   then JDK + Gradle to build the plugin around it).

6. **Add the package to the roadmap**
   ([`docs/roadmap.md`](/docs/roadmap.md) Shipped → Tooling once it ships)
   and consider a one-line entry in the [README](/README.md) pointing at
   it.

### CI: one workflow file per package, no path filters

`.github/workflows/ci-core.yml`, `ci-lsp.yml`, and `ci-phpstorm-plugin.yml`
are parallel, independent workflows. Each runs on every PR and every push
to `main`. A new package gets a fourth file in the same shape.

We deliberately **do not** path-filter workflows at the `on:` level. GitHub's
branch protection requires named status checks to actually run — a
path-filtered workflow that doesn't trigger on an unrelated change reports
"expected, never received" and blocks the merge. Running every workflow
every time costs a small amount of CI minutes (the jobs are parallel); the
alternative is a coordination tax with sharp edges.

If CI minutes ever become a real concern, the fix is to add job-level `if:`
guards using `paths-filter` action results, NOT to add `paths:` at the
workflow level. Leave required status checks intact.

## Testing

### Unit tests

```bash
make test/unit
```

Most tests are pure unit tests against `XPHP\Transpiler\Monomorphize\*`. The
handful of **integration tests** compile a fixture under
`core/test/fixture/compile/<name>/` end-to-end and either assert on the emitted
text or autoload the result and call into it at runtime. Those
runtime tests have one isolation gotcha worth knowing before you add a new one.

#### Cross-fixture class-table collisions

`Registry::generatedFqn` (`core/src/Transpiler/Monomorphize/Registry.php`) names
every specialized class as:

```
XPHP\Generated\<template-FQN>  \  T_<sha256(canonical-args)>
└── namespace, mirrors template ─┘  └── hash of args ONLY ──┘
```

The hash covers the **argument list**, not the template's own FQN — the
template's FQN is already encoded in the namespace path. That's deliberate and
right for a single compile: it collapses identical instantiations into one class
file.

It bites in tests because **multiple fixtures redeclare the same template path
**.
Five fixtures define an `App\Containers\Box<T>` (with subtly different bodies —
some
have a constructor, the `generic_interface` one implements `Container<T>`, etc.)
and
all of them instantiate it with `App\Models\Plastic`. Same template path + same
arg means
same generated FQN: `XPHP\Generated\App\Containers\Box\T_de1e0eaa…`. The on-disk
files live in
different per-test work directories, but **PHP's class table is process-wide** —
once any
integration test `new`s up that FQN, PHP caches *that* body for the rest of the
phpunit run.
A later test loading from a different fixture's work dir gets the stale class.

The failure mode is shape-dependent and the test ordering is randomized, so this
surfaces as an intermittent flake. Two known-good patterns to avoid it:

1. **Reflection-only assertions** when you're verifying the structural
   contract (an interface chain, an `implements` link, the type of a property)
   rather than runtime behavior.
   `ReflectionClass::implementsInterface($marker)`, `::getParentClass()`,
   `(new ReflectionProperty($fqn, 'item'))->getType()->getName()` all read the
   class metadata without depending on which body PHP's class table happens to
   hold.
   **Example**
   `test/Transpiler/Monomorphize/CompilerIntegrationTest.php::testInstanceofAgainstOriginalTemplateMatchesAllSpecializations`.

2. **Fixture-unique concrete types** when you legitimately need `new $fqn(...)`
   at runtime.
   Add a model class that's only declared inside your fixture (e.g.
   `test/fixture/compile/generic_interface/source/Models/Polymer.xphp`) and
   instantiate
   against `Box<Polymer>`. Other fixtures don't redefine `Box<Polymer>`, so the
   hash is
   unique and PHP's class table can't cache a competing body. **Example:**
   `test/Transpiler/Monomorphize/GenericInterfaceIntegrationTest.php::testSpecializedClassIsInstanceOfOriginalInterfaceMarker`.

Rule of thumb: if your test does `new $generatedFqn(...)` and the concrete type
is `Plastic`,
`Metal`, `User`, `Food`, or any other name that already lives in another
fixture, switch to
one of the two patterns above before pushing — random-order CI will catch it
eventually, and
the diagnostic (`ArgumentCountError`, `instanceof returns false`) won't point at
the root
cause.

### Mutation tests

Mutation testing is the headline quality signal -- the test suite isn't just
covering lines, it's surviving deliberate
code perturbations. Run via [Infection](https://infection.github.io/):

The CI workflow runs Infection on every PR and every push to `main`, failing the
build if MSI drops below **95%**.
`infection.json5` carries a curated set of per-mutator `ignore` rules for
equivalent / cosmetic cases so the report only
surfaces genuine test gaps when they appear.