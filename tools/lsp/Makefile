# Makefile for the xphp LSP package.
#
# Run from this directory directly (`make test`) or from the repo root with
# `make -C tools/lsp <target>`. There is intentionally no root-level
# delegator — the per-package Makefile is the single source of truth.

.PHONY: test
# 512M memory_limit covers the one-time worse-reflection stub-map build
# (phpactor's tolerant-php-parser walks every jetbrains/phpstorm-stubs
# .php file to discover FQNs and serializes a map to /tmp on first
# invocation). Once the map is cached, steady-state memory stays well
# under the default 128M -- but a fresh checkout (or a /tmp cleanup)
# would OOM at the default.
test:
	composer install --quiet && \
	  php -d error_reporting='E_ALL & ~E_DEPRECATED' -d memory_limit=512M vendor/bin/phpunit

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
# 2048M memory_limit covers Infection's initial Xdebug-instrumented PHPUnit
# run plus the per-mutation subprocesses; Infection forwards INI flags to
# its PHPUnit children, so a single -d here propagates.  Without it the
# initial run silently hits PHP's default cap (no useful error -- Infection
# just reports "Project tests must be in a passing state").
test/mutation: $(INFECTION_PHAR)
	composer install --quiet && \
	  php -d error_reporting='E_ALL & ~E_DEPRECATED' -d memory_limit=2048M var/infection.phar \
	  --threads=max --min-covered-msi=93 --show-mutations --no-progress

.PHONY: build-extension
build-extension:
	cd vscode-extension && npm install --no-audit --no-fund --silent && npm run compile

# The xphp-lsp PHAR is built via Humbug Box, distributed as a PHAR itself
# (same lazy-download pattern as infection.phar so we don't fight phpactor's
# psr/log ^1.0 pin in composer-resolved Box). The output at var/xphp-lsp.phar
# is what the PHPStorm plugin bundles for zero-config install.
BOX_VERSION := 4.6.6
BOX_PHAR := var/box.phar

$(BOX_PHAR):
	@mkdir -p $(dir $(BOX_PHAR))
	@echo "==> Downloading box.phar $(BOX_VERSION)"
	@curl -fsSL -o $@ \
	  https://github.com/box-project/box/releases/download/$(BOX_VERSION)/box.phar
	@chmod +x $@

.PHONY: build/phar
build/phar: $(BOX_PHAR)
	# Build sequence:
	#  1) --no-dev + --classmap-authoritative trims phpunit and noisy dev classes.
	#  2) The path-repo entry in composer.json pins "symlink": true (great for
	#     dev: parent edits show up live). That same setting is fatal for the
	#     PHAR -- PHARs can't follow symlinks, so Box would embed a dangling
	#     pointer and the runtime can't resolve XPHP\Transpiler\* classes.
	#     Replace the symlinked package with a real copy of just src/ +
	#     composer.json (the only paths xphp-parser's PSR-4 autoload reaches)
	#     and re-dump the autoloader so the classmap targets the copy.
	#  3) Restore the symlinked dev install for ongoing `make test` runs.
	composer install --no-dev --classmap-authoritative --quiet --no-interaction
	@if [ -L vendor/xphp-lang/xphp-parser ]; then \
	  parser_src="$$(readlink -f vendor/xphp-lang/xphp-parser)"; \
	  rm vendor/xphp-lang/xphp-parser; \
	  mkdir -p vendor/xphp-lang/xphp-parser/src; \
	  cp -RL "$$parser_src/src/." vendor/xphp-lang/xphp-parser/src/; \
	  cp -L "$$parser_src/composer.json" vendor/xphp-lang/xphp-parser/composer.json; \
	  composer dump-autoload --classmap-authoritative --no-dev --quiet --no-interaction; \
	fi
	php -d phar.readonly=0 var/box.phar compile --no-interaction
	composer install --quiet --no-interaction
	@echo "==> Built $$(ls -lh var/xphp-lsp.phar | awk '{print $$5, $$9}')"
