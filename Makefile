.PHONY: test/unit
test/unit:
	php vendor/bin/phpunit

.PHONY: test/mutation
test/mutation:
	php vendor/bin/infection --show-mutations=max --threads=max --min-covered-msi=93

.PHONY: test/lsp
test/lsp:
	cd tools/lsp && composer install --quiet && php -d error_reporting='E_ALL & ~E_DEPRECATED' vendor/bin/phpunit

# Infection runs against tools/lsp via its PHAR distribution rather than a
# composer require: phpactor/language-server pins psr/log ^1.0 which composer
# can't reconcile with infection 0.33's ^2||^3, AND sharing the root vendor's
# infection with tools/lsp would collide on thecodingmachine/safe's global
# functions (both vendor trees install it). The PHAR ships its internal deps
# under PHP-Scoper-prefixed namespaces, so neither problem applies. Downloaded
# lazily into tools/lsp/var/infection.phar (gitignored).
INFECTION_VERSION := 0.33.1
INFECTION_PHAR := tools/lsp/var/infection.phar

$(INFECTION_PHAR):
	@mkdir -p $(dir $(INFECTION_PHAR))
	@echo "==> Downloading infection.phar $(INFECTION_VERSION)"
	@curl -fsSL -o $@ \
	  https://github.com/infection/infection/releases/download/$(INFECTION_VERSION)/infection.phar
	@chmod +x $@

.PHONY: test/lsp/mutation
test/lsp/mutation: $(INFECTION_PHAR)
	cd tools/lsp && composer install --quiet && \
	  php -d error_reporting='E_ALL & ~E_DEPRECATED' var/infection.phar \
	  --threads=max --min-covered-msi=93 --show-mutations --no-progress

.PHONY: build/lsp-extension
build/lsp-extension:
	cd tools/lsp/vscode-extension && npm install --no-audit --no-fund --silent && npm run compile

.PHONY: playground
playground:
	playground/bin/run
