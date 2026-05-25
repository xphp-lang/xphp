# Top-level Makefile for the xphp language core.
#
# Per-package tools (tools/lsp/, future tools/phpstorm-plugin/) ship their own
# Makefiles and are invoked directly via `make -C tools/<name> <target>` — see
# CONTRIBUTING.md for the monorepo convention. No pass-through targets here.

.PHONY: test/unit
test/unit:
	php vendor/bin/phpunit

.PHONY: test/mutation
# Gate at 95% (current is 100%): keeps a small headroom so a single
# new mutation can land in a follow-up commit and still pass while
# the test that kills it is being written.  Raise to 100% once the
# repo is stable enough that no new test gaps are expected.
test/mutation:
	php vendor/bin/infection --show-mutations=max --threads=max --min-covered-msi=95

.PHONY: playground
playground:
	playground/bin/run
