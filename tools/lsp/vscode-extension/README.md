# xphp VS Code extension

Minimal client that wires VS Code to the xphp Language Server (`../bin/xphp-lsp`).
Provides `.xphp` file association, syntax highlighting (PHP grammar with light
`<T>` adjustments), and LSP transport over stdio.

## Status

Skeleton stub — not yet wired. The `package.json`, `extension.ts`, and the
TextMate grammar fork land in the dedicated VS-Code-extension commit (see the
`feat/lsp` branch plan, step 8).

Until then, the LSP server is usable from the command line via:

```bash
tools/lsp/bin/xphp-lsp --lint path/to/file.xphp
```

## Build / debug (planned)

Once `package.json` lands:

```bash
cd tools/lsp/vscode-extension
npm install
npm run compile
```

Then in VS Code, open the `tools/lsp/vscode-extension/` folder and hit **F5** to
launch an Extension Development Host window with the extension loaded.

## Publishing

Out of scope for the initial branch. Marketplace publication happens after the
core features stabilise.
