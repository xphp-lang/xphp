.PHONY: test/unit
test/unit:
	php vendor/bin/phpunit

.PHONY: test/mutation
test/mutation:
	php vendor/bin/infection --show-mutations=max --threads=max --min-covered-msi=93

.PHONY: playground
playground:
	playground/bin/run
