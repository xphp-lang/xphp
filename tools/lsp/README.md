# xphp Language Server

LSP implementation that powers diagnostics, hover, go-to-definition, and completion for `.xphp`
files in VS Code (and any LSP-aware editor). Reuses the `xphp` AST + `Registry` +
`TypeHierarchy` from the parent package directly — no separate parser, no duplication.

A separate Composer package living under `tools/lsp/` so it can declare its own dependencies
(notably `phpactor/language-server` for the JSON-RPC scaffolding) without weighing down the
core parser.

## Status

| Feature | Status |
|---|---|
| `--lint <file>` headless mode (parse + bound checks) | shipped |
| `textDocument/publishDiagnostics` over stdio — parse errors, bound violations, duplicate templates, undefined-bareword warnings, `xphp.ctor-arg-mismatch` (post-monomorphization constructor argument-type check, `new C<T>(…)` and `new C(…)`) | shipped |
| `textDocument/hover` (xphp generics + PHP semantic: class / function / method / property / native funcs; parameter and return-type substitution at static / instance / free-function call sites; generic-T → concrete type substituted through property fetches) | shipped |
| `textDocument/definition` (xphp generics + PHP semantic: class / function / method / property / `use` imports / native funcs / closed-file targets via FqnIndex; union/intersection receivers fan out to a per-constituent picker) | shipped |
| `textDocument/typeDefinition` (Go To Type Declaration through xphp generics — `$users = new Collection<User>()` jumps to `class User`, not `class Collection`) | shipped |
| `textDocument/completion` (`<...>` type-arg positions with bound-aware filtering + `$obj->` member access + `Cls::` static access (incl. static properties + constants) + `Cls::$` static property + scope-aware variables + visibility-aware filtering inside same class / subclass + union/intersection receiver fan-out + string / comment suppression; explicit `textEdit` ranges preserve the `$` sigil on accept) | shipped |
| `completionItem/resolve` (lazy class-docblock fetch) | shipped |
| `textDocument/signatureHelp` (parameter list + active-arg highlight; static/instance/free-function call sites; type-arg substitution baked into the rendered signature) | shipped |
| `textDocument/references` for classes, functions, methods, properties (with inheritance walks into subclass receivers AND interface-implementation walks in both directions: cursor on `Iface::m` matches every impl call site; cursor on `Impl::m` matches interface-typed receivers) | shipped |
| `textDocument/rename` (alias-aware short-name rewriting; `RenameFile` gated on client `resourceOperations`) | shipped |
| `textDocument/documentHighlight` (in-file occurrence highlighting) | shipped |
| `textDocument/documentSymbol` (hierarchical ClassLike / function / method tree) | shipped |
| `textDocument/foldingRange` (class / method / closure bodies + xphp `<…>` generic clauses) | shipped |
| `textDocument/inlayHint` (inline `: <substituted type>` between variable and `=` for any `$x = …` whose RHS resolves through `GenericResolver`) | shipped |
| `textDocument/codeAction` + `codeAction/resolve` — Import class · Simplify FQN · Optimize Imports · "Did you mean null/true/false?" typo fixes for `UndefinedName` diagnostics | shipped |
| `textDocument/codeLens` ("Show references" lens above every class / interface / trait / enum / function / method; click forwards to `workspace/executeCommand xphp.showReferences`) | shipped |
| `textDocument/prepareCallHierarchy` + `callHierarchy/incomingCalls` + `callHierarchy/outgoingCalls` | shipped |
| `textDocument/semanticTokens/full` (AST-driven; type-param `T` paints with the standard `typeParameter` color) | shipped |
| `workspace/symbol` (cross-file FQN search via FqnIndex) | shipped |
| `workspace/didChangeWatchedFiles` (bulk invalidation of the filesystem index for long sessions) | shipped |
| `workspace/executeCommand xphp.showReferences` (codeLens click target) | shipped |
| Durable per-user stub cache root (`XPHP_LSP_CACHE_DIR` → XDG → `~/.cache` / `~/Library/Caches` / `%LOCALAPPDATA%` / `<sys_temp>` fallback) | shipped |
| Tolerant-parse fallback so the in-memory locator survives mid-edit syntax errors (`$x->|` and similar) | shipped |
| UTF-16 column counting (positions correct past supplementary-plane codepoints) | shipped |
| PhpStorm plugin at `tools/phpstorm-plugin/` | shipped |
| VS Code extension at `tools/vscode-extension/` (sibling package; consumer of this server) | shipped |

PHP-semantic GTD / hover / completion is backed by
[`phpactor/worse-reflection`](https://github.com/phpactor/worse-reflection)
and [`jetbrains/phpstorm-stubs`](https://github.com/JetBrains/phpstorm-stubs).
xphp-specific paths run FIRST (template instantiation, type-args inside
`<…>` clauses); when those don't apply we fall through to the
worse-reflection path so behaviour on .xphp files matches PhpStorm's PHP
intelligence on regular .php files. The same `PhpHoverResolver` /
`PhpDefinitionResolver` / `PhpCompletionResolver` triad also drives
`signatureHelp`, `inlayHint`, and `callHierarchy` so all five features
agree on receiver / member resolution.

`make -C tools/lsp test` runs the PHPUnit suite.

See [`roadmap.md`](roadmap.md) for the broader feature inventory.

## Layout

```
tools/lsp/
├── composer.json              path-references the parent xphp
├── bin/xphp-lsp               CLI entry — `--lint <file>` for CI, no args for LSP stdio
├── src/
│   ├── Server.php             entry-point router (--lint vs LSP transport)
│   ├── LspDispatcherFactory   wires phpactor middleware + DiagnosticsService + handlers
│   ├── PositionMap.php        byte offset ↔ {line, char} for LSP positions
│   ├── Analyzer/
│   │   ├── Analyzer.php       per-file parse + syntax-error collection
│   │   ├── WorkspaceAnalyzer  cross-file Registry + TypeHierarchy + bound check
│   │   ├── Diagnostic.php     framework-neutral diagnostic value object
│   │   ├── DiagnosticSeverity LSP-aligned enum
│   │   └── ParseResult.php    {ast, diagnostics}
│   ├── Diagnostics/
│   │   ├── XphpDiagnosticsProvider     phpactor DiagnosticsProvider impl
│   │   └── DiagnosticTranslator        framework-neutral → wire-format
│   ├── Handler/
│   │   ├── AstPositionResolver         find smallest Name at byte offset
│   │   ├── XphpHoverHandler            textDocument/hover (xphp + PHP fall-through)
│   │   ├── XphpDefinitionHandler       textDocument/definition (xphp + PHP fall-through)
│   │   ├── XphpCompletionHandler       textDocument/completion (xphp + PHP fall-through)
│   │   ├── TypeArgPositionDetector     backwards-scanner for cursor-in-<…>
│   │   └── WorkspaceSymbols            collect ClassLike FQNs across open docs
│   ├── Reflection/
│   │   ├── ReflectorFactory            builds worse-reflection Reflector for the session
│   │   ├── WorkspaceSourceLocator      serves open documents (stripped to PHP) to worse-reflection
│   │   └── FilesystemSourceLocator     serves on-disk .xphp / .php files (stripped to PHP)
│   ├── Resolver/
│   │   ├── PhpDefinitionResolver       PHP-semantic GTD via worse-reflection (classes / funcs / methods / props / native stubs)
│   │   ├── PhpHoverResolver            signature + docblock hover via worse-reflection
│   │   ├── PhpCompletionResolver       member / static-member completion via worse-reflection
│   │   └── PhpCompletionContext        source-level detector for `$obj->` / `Cls::` cursor positions
│   └── (phpactor's own Workspace handles document open/change/close; no
│        local DocumentStore wrapper needed)
└── test/                      PHPUnit suite
```

The VS Code client that spawns this server over stdio lives at the
sibling `tools/vscode-extension/` -- see its README for the F5 dev
loop and the configurable `xphp.serverPath` it uses to locate this
package's `bin/xphp-lsp`.

## Install

```bash
cd tools/lsp
composer install
```

The parent `xphp` package is path-referenced via composer (`repositories: type=path`),
so local edits there are picked up immediately without re-publishing.

## Run

### Lint mode (CI-friendly)

```bash
tools/lsp/bin/xphp-lsp --lint path/to/file.xphp [more.xphp ...]
```

Output format: `<file>:<line>:<col>: <severity>: [<code>] <message>` — the same shape PHPStan
and PHP itself emit, so editors and CI grep it without ceremony. Exits non-zero if any file
has diagnostics, zero otherwise.

Genuinely useful in CI today, independent of the LSP transport: run it over a `.xphp` glob to
catch bound violations and syntax errors in PRs before merge.

### LSP mode (stdio)

```bash
tools/lsp/bin/xphp-lsp        # speaks LSP over stdio; no arguments
```

Use this as the `command` in any LSP client (Neovim's `vim.lsp.start`, Helix's
`languages.toml`, etc.). The sibling VS Code extension under `tools/vscode-extension/`
does this spawn for you.

Capabilities advertised at `initialize`:

- `textDocumentSync: 1` (Full)
- `hoverProvider`
- `definitionProvider`
- `typeDefinitionProvider`
- `referencesProvider`
- `documentHighlightProvider`
- `documentSymbolProvider`
- `workspaceSymbolProvider`
- `renameProvider`
- `foldingRangeProvider`
- `completionProvider` with `triggerCharacters: ["<", ",", ">", ":"]`
  and `resolveProvider: true`
- `signatureHelpProvider` with `triggerCharacters: ["(", ","]`
- `inlayHintProvider`
- `codeActionProvider` with `resolveProvider: true`
- `codeLensProvider`
- `callHierarchyProvider`
- `executeCommandProvider` for `xphp.showReferences`
- `semanticTokensProvider` (full file; standard LSP-spec token-type
  legend including `typeParameter` for xphp `T` references in
  generic-syntax positions)

## Test

```bash
# From the repo root:
make -C tools/lsp test            # PHPUnit
make -C tools/lsp test/mutation   # Infection, MSI under a 93 % gate

# Or from this directory:
cd tools/lsp
make test
make test/mutation
```

`test/lsp` runs `composer install --quiet` then PHPUnit with
`php -d error_reporting='E_ALL & ~E_DEPRECATED'` so noisy PHP 8.4 implicit-nullable warnings
from phpactor transitive deps stay out of the output. Running phpunit directly works too —
the suppression lives in `phpunit.xml.dist`'s `<source ignoreIndirectDeprecations="true">`
block:

```bash
cd tools/lsp
vendor/bin/phpunit
```

### Mutation testing

`test/mutation` downloads `infection.phar` lazily into `tools/lsp/var/` and runs against
the same source + test set. The PHAR distribution ships its internal deps under PHP-Scoper
prefixes, so it sidesteps the `thecodingmachine/safe` / `psr/log` conflicts that prevent
composer-installed Infection from coexisting with `phpactor/language-server` (`phpactor` pins
`psr/log ^1.0` while Infection 0.33 needs `^2.0 || ^3.0`; older Infection lines that allow
`psr/log ^1.0` in turn require `symfony/console ^7` instead of the parent package's `^8`).
The PHAR avoids all of that.

Curated equivalent-mutation ignores live in `infection.json5` with per-mutator
`ignore` rules and inline rationale — mirrors the pattern at the repo root.

## Build a self-contained PHAR

```bash
make -C tools/lsp build/phar     # produces tools/lsp/var/xphp-lsp.phar
```

The PHAR is the distribution format the JetBrains plugin under `tools/phpstorm-plugin/`
bundles -- zero-config install for editors that can't reasonably depend on a Composer-managed
working tree. Same lazy-download pattern as `infection.phar`: the build downloads
`box.phar` 4.6.6 into `tools/lsp/var/` on first run, then runs Humbug Box against a
`--no-dev` install.

One quirk worth knowing: the path-repo entry in `composer.json` pins
`"symlink": true` for the live dev workflow (edits to the parent `xphp` are
picked up immediately). PHARs can't traverse symlinks, so the `build/phar` target
swaps the symlinked `vendor/xphp-lang/xphp` for a real copy of its `src/` +
`composer.json`, regenerates the classmap, and restores the symlinked install at the
end so subsequent `make test` runs keep the live behavior. Net: building the PHAR
does not disturb your dev install.

Smoke test:

```bash
php tools/lsp/var/xphp-lsp.phar --lint playground/src/Demos/Bounds.xphp
# byte-for-byte identical output to:
tools/lsp/bin/xphp-lsp --lint playground/src/Demos/Bounds.xphp
```

## VS Code extension

The VS Code client lives as a peer package at
[`tools/vscode-extension/`](/tools/vscode-extension/) (separate sibling
under `tools/`, not nested under `lsp/`).  See
[`tools/vscode-extension/README.md`](/tools/vscode-extension/README.md)
for the F5 dev loop and the `xphp.serverPath` setting it uses to find
this server.

## Why a separate composer package

The parser package (`xphp-lang/xphp`) has a deliberately minimal dependency surface —
`nikic/php-parser` + `symfony/console`. The LSP needs `phpactor/language-server` and its
transitive deps (`amphp/`, `webmozart/`, …). Keeping the two as separate composer packages
means a downstream consumer who only wants the parser (e.g. a CI pipeline running `bin/xphp
compile`) doesn't pull in any of the LSP machinery.

The path-repo precedent for this setup is `playground/composer.json` — the layout here
follows the same pattern.

## Out-of-scope follow-ups

Each one is also documented inline at the relevant call site so a reader doing a code dive
finds the same caveat at the source. Highlights:

- **Cross-file diagnostic broadcast.** Editing `Box.xphp` doesn't re-publish diagnostics for
  every `Use.xphp` that instantiates it; the diagnostic catches up when those files are
  re-touched.
- **Use-alias short-form completion.** `insertText` is always the full FQN today.
- **Hover/jump on bound names in template headers.** XphpSourceParser strips the `<…>` clause
  so there's no AST node positioned over the bound text.
- **`textDocument/formatting` + `rangeFormatting` + `onTypeFormatting`.** Deferred-by-design:
  needs an xphp formatter to exist first.
- **`textDocument/documentColor` + `colorPresentation`.** Low value for PHP.
- **`textDocument/prepareTypeHierarchy` + `typeHierarchy/supertypes` + `typeHierarchy/subtypes`.**
  Deferred until `phpactor/language-server-protocol` ships the `TypeHierarchyItem` types
  (or until we accept raw-array params through the framework's untyped path).
- **`codeLens/resolve` with reference counts.** Today's lens carries a static "Show references"
  title; turning it into "N references" needs per-(uri, version) cached counts so the
  workspace walk doesn't fire per-lens on every re-render.
- **Method / static / function-call argument-type checker.** V2 of `xphp.ctor-arg-mismatch`
  extending the same idea from `new C(…)` to `$obj->m(…)`, `Cls::m(…)`, `freeFn(…)`.
- **Marketplace publication** of the VS Code extension.
