.PHONY: test/unit
test/unit:
	php vendor/bin/phpunit

.PHONY: test/mutation
test/mutation:
	php vendor/bin/infection --show-mutations=max --threads=max --min-covered-msi=93

.PHONY: test/lsp
test/lsp:
	cd tools/lsp && composer install --quiet && php -d error_reporting='E_ALL & ~E_DEPRECATED' vendor/bin/phpunit

# Note: there is intentionally no `test/lsp/mutation` target. Infection is
# incompatible with phpactor/language-server's transitive dep set
# (psr/log ^1.0, plus a thecodingmachine/safe version that double-declares
# global functions when sharing a vendor with infection). Until phpactor
# loosens these, mutation coverage for the LSP package is tracked as a
# follow-up in tools/lsp/README.md.

.PHONY: build/lsp-extension
build/lsp-extension:
	cd tools/lsp/vscode-extension && npm install --no-audit --no-fund --silent && npm run compile

.PHONY: playground
playground:
	playground/bin/run
