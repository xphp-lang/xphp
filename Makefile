# Top-level Makefile for the xphp language core.
#
# Per-package tools (tools/lsp/, future tools/phpstorm-plugin/) ship their own
# Makefiles and are invoked directly via `make -C tools/<name> <target>` — see
# CONTRIBUTING.md for the monorepo convention. No pass-through targets here.

.PHONY: test/unit
test/unit:
	php vendor/bin/phpunit

.PHONY: test/mutation
test/mutation:
	php vendor/bin/infection --show-mutations=max --threads=max --min-covered-msi=93

.PHONY: playground
playground:
	playground/bin/run
