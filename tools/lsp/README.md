# xphp Language Server

LSP implementation that powers diagnostics, hover, go-to-definition, and completion for `.xphp`
files in VS Code (and any LSP-aware editor). Reuses the `xphp-parser` AST + `Registry` +
`TypeHierarchy` from the parent package directly — no separate parser, no duplication.

A separate Composer package living under `tools/lsp/` so it can declare its own dependencies
(notably `phpactor/language-server` for the JSON-RPC scaffolding) without weighing down the
core parser.

## Status

| Feature | Status |
|---|---|
| `--lint <file>` headless mode (parse + bound checks) | ✅ shipped |
| `textDocument/publishDiagnostics` over stdio | ✅ shipped |
| `textDocument/hover` | ✅ shipped |
| `textDocument/definition` | ✅ shipped |
| `textDocument/completion` (inside `<…>` type-arg positions) | ✅ shipped |
| VS Code extension client at `vscode-extension/` | ✅ shipped |

121 PHPUnit cases, 276 assertions — `make test/lsp`.

See `docs/roadmap.md` (Shipped → Tooling) for the broader feature inventory.

## Layout

```
tools/lsp/
├── composer.json              path-references the parent xphp-parser
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
│   │   ├── XphpHoverHandler            textDocument/hover
│   │   ├── XphpDefinitionHandler       textDocument/definition
│   │   ├── XphpCompletionHandler       textDocument/completion
│   │   ├── TypeArgPositionDetector     backwards-scanner for cursor-in-<…>
│   │   └── WorkspaceSymbols            collect ClassLike FQNs across open docs
│   └── (phpactor's own Workspace handles document open/change/close; no
│        local DocumentStore wrapper needed)
├── test/                      PHPUnit suite (121 cases)
└── vscode-extension/          VS Code client — spawns server over stdio (F5 dev loop)
```

## Install

```bash
cd tools/lsp
composer install
```

The parent `xphp-parser` package is path-referenced via composer (`repositories: type=path`),
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
`languages.toml`, etc.). The bundled VS Code extension under `vscode-extension/` does this
spawn for you.

Capabilities advertised at `initialize`:

- `textDocumentSync: 1` (Full)
- `hoverProvider`
- `definitionProvider`
- `completionProvider` with `triggerCharacters: ["<", ","]`

## Test

```bash
make test/lsp           # PHPUnit, 121 cases / 276 assertions
make test/lsp/mutation  # Infection, 95 % MSI under a 93 % gate
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

`test/lsp/mutation` downloads `infection.phar` lazily into `tools/lsp/var/` and runs against
the same source + test set. The PHAR distribution ships its internal deps under PHP-Scoper
prefixes, so it sidesteps the `thecodingmachine/safe` / `psr/log` conflicts that prevent
composer-installed Infection from coexisting with `phpactor/language-server` (`phpactor` pins
`psr/log ^1.0` while Infection 0.33 needs `^2.0 || ^3.0`; older Infection lines that allow
`psr/log ^1.0` in turn require `symfony/console ^7` instead of the parent package's `^8`).
The PHAR avoids all of that.

Curated equivalent-mutation ignores live in `infection.json5` with per-mutator
`ignore` rules and inline rationale — mirrors the pattern at the repo root.

## VS Code extension

See `vscode-extension/README.md` for the client-side setup. Quick start:

```bash
make build/lsp-extension      # npm install + tsc
# then open tools/lsp/vscode-extension/ in VS Code and hit F5
```

## Why a separate composer package

The parser package (`xphp-lang/xphp-parser`) has a deliberately minimal dependency surface —
`nikic/php-parser` + `symfony/console`. The LSP needs `phpactor/language-server` and its
transitive deps (`amphp/`, `webmozart/`, …). Keeping the two as separate composer packages
means a downstream consumer who only wants the parser (e.g. a CI pipeline running `bin/xphp
compile`) doesn't pull in any of the LSP machinery.

The path-repo precedent for this setup is `playground/composer.json` — the layout here
follows the same pattern.

## Out-of-scope follow-ups

Each one is also documented inline at the relevant call site so a reader doing a code dive
finds the same caveat at the source. Highlights:

- **Indexer for unopened files.** Today only documents the editor has open contribute to
  cross-file diagnostics + go-to-definition + completion. Walking `**/*.xphp` at `initialize`
  is the obvious next pass.
- **Cross-file diagnostic broadcast.** Editing `Box.xphp` doesn't re-publish diagnostics for
  every `Use.xphp` that instantiates it; the diagnostic catches up when those files are
  re-touched.
- **Bound-aware completion filtering.** `Box<T: \Stringable>` still suggests non-Stringable
  classes; the diagnostic catches the violation after selection.
- **Use-alias short-form completion.** `insertText` is always the full FQN today.
- **Hover/jump on bound names in template headers.** XphpSourceParser strips the `<…>` clause
  so there's no AST node positioned over the bound text.
- **Marketplace publication** of the VS Code extension.
