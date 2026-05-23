# xphp PhpStorm plugin

Editing intelligence for `.xphp` files inside PhpStorm: diagnostics, hover,
go-to-definition, completion. All powered by the same language server that
backs the VS Code extension under `tools/lsp/` — single source of truth across
both editors.

## Status

This package is being built up chunk-by-chunk on the `feat/phpstorm-plugin`
branch. Where each chunk lands:

| Chunk | Status | What it adds |
|-------|--------|--------------|
| 1. PHAR build for the LSP server | shipped | `make -C tools/lsp build/phar` produces a self-contained `xphp-lsp.phar` |
| 2. Plugin scaffold                | shipped | Gradle + Kotlin skeleton, plugin descriptor, wrapper, Makefile |
| 3. File type + TextMate grammar   | shipped | Recognises `.xphp`, bundles the shared `tmLanguage.json`; LSP semantic tokens (chunk 4) carry the highlighting |
| 4. LSP wiring                     | pending | Implements `LspServerSupportProvider`, settings UI, manual LSP path |
| 5. Bundle PHAR + auto-extract     | pending | Zero-config install: bundled PHAR extracted at first run |
| 6. CI                             | pending | `.github/workflows/ci-phpstorm-plugin.yml` matching the per-package convention |
| 7. Docs + roadmap                 | pending | README final pass, roadmap promotion, `CONTRIBUTING.md` worked example |

## Requirements

- PhpStorm 2026.1 or later (the `since-build` is `261`)
- JDK 21 (Gradle picks up the toolchain via the IntelliJ Platform plugin)
- The xphp LSP PHAR (produced by `make -C tools/lsp build/phar`)

The 2026.1 baseline is deliberate: the IntelliJ Platform LSP API went *free*
in 2025.2 and rounded out its feature set in 2026.1 (Code Lens, range
formatting, Optimize Imports). Older baselines would force us to ship through
LSP4IJ as a compatibility shim — significantly more code for an MVP than the
two-versions-behind userbase saves.

## Dev workflow

Everything below runs from this directory (`tools/phpstorm-plugin/`) or, from
the repo root, via `make -C tools/phpstorm-plugin <target>`.

```bash
make build      # compiles Kotlin, runs unit tests, assembles plugin zip
make test       # unit tests only
make run-ide    # boots a sandbox PhpStorm with the plugin pre-loaded
make verify     # plugin verifier compatibility check across the IDE matrix
make clean
```

`./gradlew` is committed verbatim from the official Gradle 8.10.2 distribution
(SHA-256 of the binary distribution checked into `gradle/wrapper/gradle-
wrapper.properties`). First invocation downloads Gradle into your user-level
Gradle cache; subsequent runs reuse it.

## Sandbox manual smoke test (after chunk 4 lands)

```bash
make run-ide
# In the spawned PhpStorm:
#   1. Open the repo's playground/ directory as a project.
#   2. Open playground/src/Containers/Box.xphp .
#   3. Confirm: syntax highlighting, hover over Box<...> shows the specialized
#      FQN, F12 jumps to class Box<T>, completion fires inside <…>.
```

## Why this lives under `tools/`

The xphp monorepo separates the **compiler product** (root) from the **tools
that consume it** (`tools/<name>/`). The PhpStorm plugin consumes the
compiler's published behaviour by spawning the LSP — never as a compile-time
dependency. See `CONTRIBUTING.md` "Monorepo layout" for the full convention.

## Out of scope for now

- **Marketplace publication.** Requires signing keys; the `signPlugin` task
  is wired but never invoked here. Tracked as a deferred follow-up.
- **Native Kotlin lexer/parser.** Would unlock IntelliJ-grade refactoring and
  structure view, at the cost of weeks of work and a second AST to keep in
  sync. The LSP path delivers the same author experience without the
  maintenance burden.
- **PhpStorm < 2026.1 support.** Locked out by `since-build=261`.
- **Compile-on-save action.** Orthogonal: implement once the LSP intelligence
  is rock-solid.
