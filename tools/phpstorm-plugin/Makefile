# Makefile for the xphp PhpStorm plugin.
#
# Mirrors the per-package shape from tools/lsp/Makefile -- short, package-
# relative target names, no root delegation.  Run from this directory with
# `make <target>` or from the repo root via `make -C tools/phpstorm-plugin
# <target>`.  See CONTRIBUTING.md "Adding a new package" for the convention.
#
# Every gradle invocation routes through the `jdk` service declared in
# docker-compose.override.yml at the repo root: no host JDK install is
# required (or supported -- the build is reproducible against the exact
# JDK + X11 stack inside the container, full stop).  Rootless docker maps
# the container's UID back to the host user, so build outputs come out
# owned by you and don't need a follow-up chown.

# `--project-directory ../..` makes `docker compose` see the repo-root
# compose files without an actual `cd`, so $(MAKE)'s working directory
# (this package's dir) stays unchanged.  `-p tools/phpstorm-plugin` tells
# gradle which sub-project to act on, since the container's working_dir
# is the repo root (/opt/app), not the plugin package.
GRADLEW     := docker compose --project-directory ../.. run --rm jdk \
                 tools/phpstorm-plugin/gradlew
GRADLE_OPTS := -p tools/phpstorm-plugin

.PHONY: build
build:
	$(GRADLEW) $(GRADLE_OPTS) build

.PHONY: test
test:
	$(GRADLEW) $(GRADLE_OPTS) test

.PHONY: verify
verify:
	# Plugin Verifier compatibility check.  Catches binary-incompat issues
	# (removed/changed IntelliJ Platform APIs) against the IDE matrix the
	# IntelliJ Platform Gradle Plugin's `pluginVerification.ides {}` block
	# resolves -- currently the `recommended()` set for since-build 261.
	$(GRADLEW) $(GRADLE_OPTS) verifyPlugin

.PHONY: run-ide
run-ide: x11-grant
	# Boots a sandboxed PhpStorm with this plugin loaded.  Used for the
	# manual smoke tests: open a .xphp file, confirm diagnostics / hover /
	# go-to-definition / completion.  Sandbox is fully isolated from any
	# PhpStorm install on the host (separate config/system dirs, separate
	# JVM process); safe to run alongside your daily IDE.
	$(GRADLEW) $(GRADLE_OPTS) runIde

# Allow the jdk container's GUI clients to reach the host's X server so
# the sandbox PhpStorm window from `run-ide` actually displays.
# `xhost +local:docker` is idempotent (re-adding an existing rule is a
# no-op), so we can call it before every `run-ide` without leaking ACL
# entries.  Pre-req only for `run-ide`; headless targets don't need it.
.PHONY: x11-grant
x11-grant:
	xhost +local:docker

.PHONY: dist
dist:
	# Produce the installable plugin zip at build/distributions/.  Use this
	# when you want to install into your *existing* PhpStorm (not the
	# sandbox `run-ide` boots): once the zip exists, in your running IDE go
	# to Settings -> Plugins -> gear icon -> "Install Plugin from Disk..."
	# and pick the file.  Requires PhpStorm 2026.1 or newer (since-build=261);
	# older builds reject the install with an "incompatible build number"
	# error.  Restart of PhpStorm required after install.
	$(GRADLEW) $(GRADLE_OPTS) buildPlugin
	@echo
	@echo "Installable plugin distribution:"
	@ls -la build/distributions/ 2>/dev/null || \
	  echo "  (build/distributions/ not created; check gradle output above)"

.PHONY: clean
clean:
	$(GRADLEW) $(GRADLE_OPTS) clean
