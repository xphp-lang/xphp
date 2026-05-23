# Makefile for the xphp PhpStorm plugin.
#
# Mirrors the per-package shape from tools/lsp/Makefile -- short, package-
# relative target names, no root delegation.  Run from this directory with
# `make <target>` or from the repo root via `make -C tools/phpstorm-plugin
# <target>`.  See CONTRIBUTING.md "Adding a new package" for the convention.
#
# Every target is a thin shim around the Gradle wrapper -- there is exactly
# one source of truth for build behaviour (build.gradle.kts) and the Makefile
# never tries to second-guess it.

GRADLEW := ./gradlew

.PHONY: build
build:
	$(GRADLEW) build

.PHONY: test
test:
	$(GRADLEW) test

.PHONY: verify
verify:
	# Plugin Verifier compatibility check.  Catches binary-incompat issues
	# (removed/changed IntelliJ Platform APIs) against the IDE matrix the
	# IntelliJ Platform Gradle Plugin's `pluginVerification.ides {}` block
	# resolves -- currently the `recommended()` set for since-build 261.
	$(GRADLEW) verifyPlugin

.PHONY: run-ide
run-ide:
	# Boots a sandboxed PhpStorm with this plugin loaded.  Useful for the
	# manual smoke tests called out in the plan's verification section:
	# open a .xphp file, confirm diagnostics / hover / go-to-definition.
	$(GRADLEW) runIde

.PHONY: clean
clean:
	$(GRADLEW) clean
