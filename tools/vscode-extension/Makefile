# Makefile for the xphp VS Code extension.
#
# Mirrors the per-package shape from tools/lsp/Makefile and
# tools/phpstorm-plugin/Makefile -- short, package-relative target names,
# no root delegation.  Run from this directory with `make <target>` or
# from the repo root via `make -C tools/vscode-extension <target>`.
# See CONTRIBUTING.md "Adding a new package" for the convention.
#
# The extension is a thin LSP client: it spawns the xphp-lsp binary
# from `tools/lsp/bin/xphp-lsp` (or `tools/lsp/var/xphp-lsp.phar` when
# installed standalone) over stdio.  This Makefile builds the
# TypeScript-side client; the LSP itself is built separately via
# `make -C tools/lsp build/phar`.

.PHONY: build
build:
	npm install --no-audit --no-fund --silent && npm run compile
