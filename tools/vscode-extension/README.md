# xphp Language extension for VS Code

VS Code client for the [xphp Language Server](../lsp/README.md). Provides
`.xphp` file association, light syntax highlighting layered on top of the
bundled PHP grammar, and live LSP features:

- **Diagnostics** — syntax errors, bound violations, duplicate templates.
- **Hover** — `Box<Plastic>` shows the specialized FQN; `T` shows its bound
  and owning template.
- **Go to definition** — F12 on a generic instantiation jumps to the
  template declaration.
- **Completion** — `<` inside a type-arg position suggests workspace
  classes plus xphp scalars.

The server is `tools/lsp/bin/xphp-lsp` and runs in PHP. The extension
spawns it over stdio.

## Local development (F5 workflow)

From the repo root, ensure the server is installed:

```sh
make -C tools/lsp test    # composer install inside tools/lsp + run the suite
```

Then in the extension directory:

```sh
cd tools/vscode-extension
make build                # npm install + npm run compile
```

Open `tools/vscode-extension/` in VS Code and press **F5**. A new
Extension Development Host window opens with the extension loaded; the
host opens the repo root as its workspace so `.xphp` files under
`playground/src/` are immediately available to test.

Useful files to open in the host:

- `playground/src/Containers/Box.xphp` — generic class template; hover
  on `T` shows the type-param info.
- `playground/src/Demos/GenericInterface.xphp` — uses `Box<Plastic>`;
  F12 jumps to the template.
- `playground/src/Demos/Bounds.xphp` — exercises `StringableBox<Tag>`;
  editing the type arg to `int` should surface a red squiggle.

## Configuration

| Setting          | Default | Purpose |
|------------------|---------|---------|
| `xphp.serverPath` | `""`    | Absolute path to `xphp-lsp`. If empty, the extension looks first beside its own install dir, then in the workspace at `tools/lsp/bin/xphp-lsp`. |
| `xphp.phpPath`    | `"php"` | PHP binary used to run the server. Override if PHP isn't on `PATH`. |

## Server logs

The "xphp Language Server" output channel (View → Output) carries the
extension's own messages plus everything the server writes to stderr.

## Publishing

Out of scope for this branch. The MVP runs from the F5 dev workflow only;
marketplace packaging via `vsce package` lands when the feature surface
stabilises.
