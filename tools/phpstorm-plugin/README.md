# xphp PhpStorm plugin

Editing intelligence for `.xphp` files inside PhpStorm -- diagnostics, hover,
go-to-definition, completion -- driven by the same Language Server Protocol
implementation that backs the VS Code extension at
[`tools/lsp/vscode-extension/`](../lsp/vscode-extension/). One server, one
TextMate grammar, two editor integrations.

| Feature | How |
|---|---|
| Diagnostics (parse errors, generic-bound violations, duplicate templates) | LSP `textDocument/publishDiagnostics` |
| Hover (specialized FQN + bound info) | LSP `textDocument/hover` |
| Go-to-definition (across files, into specialized classes) | LSP `textDocument/definition` |
| Completion (class names inside `<...>` type-arg positions) | LSP `textDocument/completion` |
| File-type recognition (`.xphp`) | IntelliJ `com.intellij.fileType` extension |
| Zero-config server install | Bundled PHAR auto-extracted on first plugin load |

## Requirements

- **PhpStorm 2026.1 or later** -- `since-build` is `261`. The IntelliJ Platform
  LSP API went free across all editions in 2025.2 and rounded out its feature
  set in 2026.1 (Code Lens, range formatting, Optimize Imports). Older
  baselines would mean shipping through LSP4IJ as a compatibility shim --
  significantly more code for an MVP than the two-versions-behind userbase
  saves.
- **JDK 21** for building the plugin. The Gradle wrapper picks up your
  toolchain automatically.
- **PHP 8.4 + Composer** for building the bundled LSP PHAR (`make -C
  tools/lsp build/phar`).

## Build

Everything below runs from this directory (`tools/phpstorm-plugin/`) or, from
the repo root, via `make -C tools/phpstorm-plugin <target>`.

```bash
# 1. Build the bundled LSP server (~ 2 MB PHAR):
make -C tools/lsp build/phar

# 2. Build the plugin:
make build         # compiles Kotlin, runs unit tests, packages plugin jar
make test          # unit tests only (JUnit 5)
make verify        # IntelliJ Plugin Verifier compatibility check
make run-ide       # boots a sandbox PhpStorm with the plugin pre-loaded
make clean
```

The wrapper resolves to Gradle 9.0.0 (SHA-256 of the distribution pinned in
`gradle/wrapper/gradle-wrapper.properties`). First invocation downloads
Gradle and the PhpStorm 2026.1.2 distribution into your user-level Gradle
cache; subsequent runs reuse them.

A plugin build without first running `make -C tools/lsp build/phar` succeeds
but warns and ships *without* a bundled LSP -- users would then have to point
Preferences -> Tools -> xPHP -> "xphp LSP binary" at an external binary. CI
runs both makes in order; for local development, doing the same is the
zero-config path.

## Install

Until the plugin lands on the JetBrains Marketplace:

```bash
make -C tools/lsp build/phar
make -C tools/phpstorm-plugin build
# tools/phpstorm-plugin/build/distributions/xphp-phpstorm-plugin-0.1.0.zip
```

In PhpStorm: Preferences -> Plugins -> "Install Plugin from Disk..." -> pick
the generated zip.

## Sandbox manual smoke test

```bash
make run-ide
# In the spawned PhpStorm:
#   1. Open the repo's playground/ directory as a project.
#   2. Open playground/src/Containers/Box.xphp .
#   3. Confirm: hover over Box<...> shows the specialized FQN,
#      F12 jumps to class Box<T>, completion fires inside <...>,
#      a deliberate Box<int> against a Stringable bound underlines.
#   4. Preferences -> Tools -> xPHP: confirm the "xphp LSP binary"
#      field is present and either points at the bundled PHAR or
#      lets you override it with an absolute path.
```

## How it's wired

```
Open file.xphp in PhpStorm
        |
        v
XphpLspServerSupportProvider.fileOpened()
        | (filters on XphpFileType)
        v
XphpLspServerDescriptor.createCommandLine()
        | (1) XphpSettings.lspPath if set, else
        | (2) PharExtractor.extract() -> system-dir/xphp/xphp-lsp.phar
        | (3) else error pointing at settings
        v
IntelliJ Platform LSP API spawns `php <path-to-phar>` over stdio
        |
        v
xphp Language Server (tools/lsp/) replies with diagnostics, hover,
definition, completion, semantic tokens -- the platform threads
them into the standard editor UI.
```

## Why this lives under `tools/`

The xphp monorepo separates the **compiler product** (root) from the **tools
that consume it** (`tools/<name>/`). The PhpStorm plugin consumes the
compiler's published behaviour by spawning the LSP -- never as a compile-time
dependency. See [CONTRIBUTING.md "Monorepo layout"](../../CONTRIBUTING.md) for
the convention; this package is the worked example for "package whose CI
needs both ecosystems (PHP + JDK)".

## Out of scope for now

- **Marketplace publication.** Requires signing keys; the `signPlugin` task
  is wired but never invoked here. Tracked as a deferred follow-up.
- **Native Kotlin lexer / parser.** Would unlock IntelliJ-grade refactoring
  and structure view, at the cost of weeks of work and a second AST to keep
  in sync. The LSP path delivers the same author experience without the
  maintenance burden -- revisit if and when the LSP path proves
  insufficient.
- **PhpStorm < 2026.1 support.** Locked out by `since-build=261`. LSP4IJ
  fallback path is the obvious workaround; not worth it for an MVP.
- **Compile-on-save action.** Useful (`bin/xphp compile` triggered from a
  run configuration / file watcher) but orthogonal; ship once LSP
  intelligence is rock-solid.
- **Headless PhpStorm CI integration tests.** JetBrains' IDE distribution
  isn't licensed for headless CI use in the open-source case. The plugin
  verifier covers binary compatibility; behavioural integration tests stay
  manual.
