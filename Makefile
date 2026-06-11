# Makefile for the xphp core (compiler) package.
#
# Sibling packages (tools/lsp/, tools/phpstorm-plugin/,
# tools/vscode-extension/, playground/) ship their own Makefiles and
# are invoked directly via `make -C <package> <target>` -- see
# CONTRIBUTING.md for the monorepo convention.  No pass-through
# targets here.

.PHONY: test/unit
test/unit:
	php vendor/bin/phpunit

.PHONY: lint/phpstan
# Static analysis at level 7. Memory limit lifted because deep generic
# array shapes (BoundDict's recursive operand chains, marker shape
# stacks) push the default 256M ceiling.
lint/phpstan:
	php vendor/bin/phpstan analyse --memory-limit=2G --no-progress

.PHONY: test/mutation
# Gate at 95% (current is 100%): keeps a small headroom so a single
# new mutation can land in a follow-up commit and still pass while
# the test that kills it is being written.  Raise to 100% once the
# repo is stable enough that no new test gaps are expected.
test/mutation:
	php -d memory_limit=-1 vendor/bin/infection --show-mutations=max --threads=max --min-covered-msi=95

# Humbug Box is the standard tool for compiling a Composer-managed
# PHP project into a single self-contained PHAR.  Pinned to a known-
# good release (Box 4.6.6 supports PHP 8.4) so a new Box version
# can't silently break the build.  Bump after validating locally.
BOX_VERSION := 4.6.6
BOX_PHAR := var/box.phar

$(BOX_PHAR):
	@mkdir -p $(dir $(BOX_PHAR))
	@echo "==> Downloading box.phar $(BOX_VERSION)"
	@curl -fsSL -o $@ \
	  https://github.com/box-project/box/releases/download/$(BOX_VERSION)/box.phar
	@chmod +x $@

.PHONY: build/phar
# Builds dist/xphp.phar -- the release artifact attached to every
# `v*` tag by .github/workflows/release.yml.  Box reads box.json at
# the repo root and auto-discovers what to include from
# composer.json.
#
# `composer install` runs twice on purpose: once with --no-dev to
# strip phpunit/infection/etc. from the PHAR (smaller artifact, no
# test machinery shipped to users), then once more in dev mode so
# the next `make test/unit` keeps working without a separate
# install step.
build/phar: $(BOX_PHAR)
	composer install --no-dev --classmap-authoritative --quiet --no-interaction
	php -d phar.readonly=0 $(BOX_PHAR) compile --no-interaction
	composer install --quiet --no-interaction
	@echo "==> Built $$(ls -lh dist/xphp.phar | awk '{print $$5, $$9}')"
