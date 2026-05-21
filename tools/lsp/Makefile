# Makefile for the xphp LSP package.
#
# Run from this directory directly (`make test`) or from the repo root with
# `make -C tools/lsp <target>`. There is intentionally no root-level
# delegator — the per-package Makefile is the single source of truth.

.PHONY: test
test:
	composer install --quiet && \
	  php -d error_reporting='E_ALL & ~E_DEPRECATED' vendor/bin/phpunit

# Infection runs against this package via its PHAR distribution rather than a
# composer require: phpactor/language-server pins psr/log ^1.0 which composer
# can't reconcile with infection 0.33's ^2||^3, AND sharing the root vendor's
# infection with tools/lsp would collide on thecodingmachine/safe's global
# functions (both vendor trees install it). The PHAR ships its internal deps
# under PHP-Scoper-prefixed namespaces, so neither problem applies. Downloaded
# lazily into var/infection.phar (gitignored).
INFECTION_VERSION := 0.33.1
INFECTION_PHAR := var/infection.phar

$(INFECTION_PHAR):
	@mkdir -p $(dir $(INFECTION_PHAR))
	@echo "==> Downloading infection.phar $(INFECTION_VERSION)"
	@curl -fsSL -o $@ \
	  https://github.com/infection/infection/releases/download/$(INFECTION_VERSION)/infection.phar
	@chmod +x $@

.PHONY: test/mutation
test/mutation: $(INFECTION_PHAR)
	composer install --quiet && \
	  php -d error_reporting='E_ALL & ~E_DEPRECATED' var/infection.phar \
	  --threads=max --min-covered-msi=93 --show-mutations --no-progress

.PHONY: build-extension
build-extension:
	cd vscode-extension && npm install --no-audit --no-fund --silent && npm run compile
