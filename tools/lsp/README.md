# xphp Language Server

LSP implementation that powers diagnostics, hover, go-to-definition, and completion for `.xphp`
files in VS Code (and any LSP-aware editor). Reuses the `xphp-parser` AST + `Registry` +
`TypeHierarchy` from the parent package directly — no separate parser, no duplication.

This is a separate Composer package living under `tools/lsp/` so it can declare its own
dependencies (notably `phpactor/language-server` for the JSON-RPC scaffolding) without
weighing down the core parser.

## Status

| Feature | Status |
|---|---|
| `--lint <file>` headless mode (parse + bound checks) | ✅ shipped |
| `textDocument/publishDiagnostics` over stdio | 🚧 wiring next |
| `textDocument/hover` | 🚧 |
| `textDocument/definition` | 🚧 |
| `textDocument/completion` | 🚧 |
| VS Code extension client | 🚧 lives at `vscode-extension/` |

The plan that drives this implementation is in `agent-os/product/specs/feat-lsp.md`
(internal) / referenced from `docs/roadmap.md`.

## Layout

```
tools/lsp/
├── composer.json              path-references the parent xphp-parser
├── bin/xphp-lsp               CLI entry; --lint mode works today, LSP mode pending
├── src/
│   ├── Server.php             entry-point router; dispatches --lint vs LSP
│   ├── PositionMap.php        byte offset <-> {line, char} for LSP positions
│   ├── Analyzer/
│   │   ├── Analyzer.php       per-file parse + syntax-error collection
│   │   ├── WorkspaceAnalyzer  cross-file Registry + TypeHierarchy + bound check
│   │   ├── Diagnostic.php     framework-neutral diagnostic value object
│   │   ├── DiagnosticSeverity LSP-aligned enum
│   │   └── ParseResult.php    {ast, diagnostics}
│   ├── Handler/               (one file per LSP request type — coming soon)
│   └── Workspace/             document store + indexer
├── test/                      PHPUnit suite
└── vscode-extension/          minimal VS Code client (spawn server over stdio)
```

## Install

```bash
cd tools/lsp
composer install
```

The parent `xphp-parser` package is path-referenced via composer (`repositories: type=path`),
so any local edits there are picked up immediately without re-publishing.

## Run

### Lint mode (works today)

```bash
tools/lsp/bin/xphp-lsp --lint path/to/file.xphp [more.xphp...]
```

Output format: `<file>:<line>:<col>: <severity>: [<code>] <message>` — the same shape PHPStan
and PHP itself emit, so editors / CI grep it without ceremony. Exits non-zero if any file has
diagnostics, zero otherwise.

This is genuinely useful in CI today, independent of the LSP wiring: run it over a `.xphp`
glob to catch bound violations and syntax errors in PRs before merge.

### LSP mode (coming next)

```bash
tools/lsp/bin/xphp-lsp        # speaks LSP over stdio
```

Currently exits cleanly with a "not yet wired" stderr message. The next commit wires the
phpactor/language-server bootstrap and `textDocument/publishDiagnostics`.

## Test

```bash
cd tools/lsp
vendor/bin/phpunit
```

Or from the repo root:

```bash
make test/lsp
```

## VS Code extension

See `vscode-extension/README.md` for client-side setup (a follow-up commit; the directory is
currently a placeholder).

## Why a separate composer package

The parser package (`xphp-lang/xphp-parser`) has a deliberately minimal dependency surface —
`nikic/php-parser` + `symfony/console`. The LSP needs `phpactor/language-server` and its
transitive deps (`amphp/`, `webmozart/`, …). Keeping the two as separate composer packages
means a downstream consumer who only wants the parser (e.g. a CI pipeline running `bin/xphp
compile`) doesn't pull in any of the LSP machinery.

The path-repo precedent for this setup is `playground/composer.json` — the layout here
follows the same pattern.
