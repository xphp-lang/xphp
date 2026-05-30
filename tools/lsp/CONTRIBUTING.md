# Contributing

## Test

```bash
make test            # PHPUnit
make test/mutation   # Infection, MSI under a 93 % gate
```

`test/mutation` downloads `infection.phar` lazily into `var/` and runs against
the same source + test set. The PHAR distribution sidesteps the
`thecodingmachine/safe` / `psr/log` conflicts that prevent a composer-installed
Infection from coexisting with `phpactor/language-server`. Curated
equivalent-mutation ignores live in `infection.json5` with per-mutator `ignore`
rules and inline rationale.

## How it works

PHP-semantic GTD / hover / completion is backed by
[`phpactor/worse-reflection`](https://github.com/phpactor/worse-reflection)
and [`jetbrains/phpstorm-stubs`](https://github.com/JetBrains/phpstorm-stubs).
xphp-specific paths run FIRST (template instantiation, type-args inside `<...>`
clauses); when those don't apply we fall through to the worse-reflection path so
behaviour on `.xphp` files matches PhpStorm's PHP intelligence on regular `.php`
files. The same `PhpHoverResolver` / `PhpDefinitionResolver` /
`PhpCompletionResolver` triad also drives `signatureHelp`, `inlayHint`, and
`callHierarchy` so all five features agree on receiver / member resolution.

## LSP capabilities advertised at `initialize`

For LSP-client developers wiring this server into a non-bundled editor:

- `textDocumentSync: 1` (Full)
- `hoverProvider`, `definitionProvider`, `typeDefinitionProvider`,
  `referencesProvider`, `implementationProvider`
- `documentHighlightProvider`, `documentSymbolProvider`,
  `workspaceSymbolProvider`
- `renameProvider`
- `foldingRangeProvider`
- `completionProvider` with `triggerCharacters: ["<", ",", ">", ":"]`
  and `resolveProvider: true`
- `signatureHelpProvider` with `triggerCharacters: ["(", ","]`
- `inlayHintProvider`
- `codeActionProvider` with `resolveProvider: true`
- `codeLensProvider` with `resolveProvider: true`
- `callHierarchyProvider`, `typeHierarchyProvider`
- `executeCommandProvider` for `xphp.showReferences` (no-op server-
  side; both clients dispatch `editor.action.showReferences` directly)
- `semanticTokensProvider` (full file; standard LSP-spec token
  legend including `typeParameter`)
- Pull-mode `diagnosticProvider`
- `workspace.fileOperations.willRename` (LSP 3.17)
- `workspace.didChangeWatchedFiles` (dynamic registration)
