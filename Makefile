# Makefile for the xphp core (compiler) package.
#
# Sibling packages (tools/lsp/, tools/phpstorm-plugin/,
# tools/vscode-extension/, playground/) ship their own Makefiles and
# are invoked directly via `make -C <package> <target>` -- see
# CONTRIBUTING.md for the monorepo convention.  No pass-through
# targets here.

.PHONY: test/unit
# Default runtime is PHP 8.4 (composer requires ^8.4). Two groups are excluded
# here: `php85` (newer-PHP syntax; runs on 8.5 via `make test/unit/php85`) and
# `phpstan` (the `xphp check` PHPStan pass, which shells out to a real phpstan
# subprocess and is slow; runs via `make test/phpstan-pass`).
test/unit:
	php vendor/bin/phpunit --exclude-group php85 --exclude-group phpstan

.PHONY: test/phpstan-pass
# The `xphp check` PHPStan-integration tests (tagged `@group phpstan`). They
# shell out to the consumer's phpstan binary and self-skip when vendor/bin/phpstan
# is absent. NOTE: distinct from `lint/phpstan`, which runs PHPStan over src/.
test/phpstan-pass:
	php vendor/bin/phpunit --group phpstan

.PHONY: test/unit/php85
# Runs only the PHP 8.5-specific syntax tests (e.g. the pipe operator).
# Requires a PHP 8.5 runtime -- CI uses a dedicated 8.5 container; on 8.4
# these self-skip via #[RequiresPhp].
test/unit/php85:
	php vendor/bin/phpunit --group php85

.PHONY: lint/phpstan
# Static analysis at level 7. Memory limit lifted because deep generic
# array shapes (BoundDict's recursive operand chains, marker shape
# stacks) push the default 256M ceiling.
lint/phpstan:
	php vendor/bin/phpstan analyse --memory-limit=2G --no-progress

.PHONY: test/mutation
# Single-machine full run (local dev / one-shot). Gate at 95% (current is
# 100%): keeps a small headroom so a single new mutation can land in a
# follow-up commit and still pass while the test that kills it is being
# written.  Raise to 100% once the repo is stable enough that no new test
# gaps are expected.  CI does NOT use this target -- it splits the work
# across parallel shards via the three targets below.
test/mutation:
	php -d memory_limit=-1 vendor/bin/infection --show-mutations=max --threads=max --min-covered-msi=95

# ---------------------------------------------------------------------------
# Sharded mutation testing (horizontal scaling for CI)
#
# The single run above is decomposed into three stages so CI can fan the
# mutant analysis out across many machines:
#
#   1. test/mutation/coverage  (run once)  -- generate the code coverage +
#      junit that every shard reuses, so no shard pays for an initial test
#      run or needs a coverage driver.
#   2. test/mutation/shard     (run N x)   -- each shard mutates a disjoint,
#      auto-balanced slice of src/ against the shared coverage.
#   3. test/mutation/gate      (run once)  -- aggregate the shards' summary
#      JSON into the true project-wide Covered MSI and enforce the gate.
#
# Because Covered MSI is a ratio, aggregating numerators/denominators makes
# the distributed gate numerically identical to the single-machine one.
# ---------------------------------------------------------------------------

# Where the reusable coverage lives; shared by the coverage + shard targets.
INFECTION_COVERAGE_DIR ?= var/infection-coverage

.PHONY: test/mutation/coverage
# Stage 1: generate the coverage Infection reuses across shards, in the
# layout its `--coverage` option expects (a directory containing
# coverage-xml/ and junit.xml). Runs the FULL default suite -- the same set
# `test/mutation` mutates against -- so every source line an integration or
# @group phpstan test touches in-process is recorded. pcov is used instead of
# xdebug: for line coverage (all Infection needs) it is several times faster
# and far lighter on memory, which is the point of moving this off the
# critical path.
test/mutation/coverage:
	php -d memory_limit=-1 -d pcov.enabled=1 vendor/bin/phpunit \
	  --coverage-filter src \
	  --coverage-xml $(INFECTION_COVERAGE_DIR)/coverage-xml \
	  --log-junit $(INFECTION_COVERAGE_DIR)/junit.xml

.PHONY: test/mutation/shard
# Stage 2: run one shard. SHARD_INDEX is 0-based in [0, SHARD_TOTAL).
#
# The slice is computed automatically -- there is no hand-maintained file
# list. Source files are greedily bin-packed by byte size, largest first,
# into SHARD_TOTAL balanced buckets (longest-processing-time scheduling);
# this shard runs bucket SHARD_INDEX. Byte size is a cheap proxy for mutant
# count, so buckets finish in roughly equal wall time, which is what caps the
# fan-out. Adding or removing source files just reshuffles the buckets.
#
# Reuses stage 1's coverage (--skip-initial-tests), so no coverage driver is
# needed here and no initial suite runs. The shard is a pure worker: it sets
# NO --min-covered-msi (an all-ignored slice would spuriously fail that) and
# writes a summary JSON for stage 3 to aggregate. Escaped mutants are surfaced
# inline as GitHub annotations.
SHARD_TOTAL ?= 1
SHARD_INDEX ?= 0
test/mutation/shard:
	@files=$$(find src -name '*.php' -printf '%s %p\n' | sort -rn | \
	  awk -v total=$(SHARD_TOTAL) -v idx=$(SHARD_INDEX) '\
	    { min = 0; for (b = 1; b < total; b++) if (load[b] < load[min]) min = b; \
	      load[min] += $$1; if (min == idx) print $$2 }' | paste -sd,); \
	if [ -z "$$files" ]; then \
	  echo "shard $(SHARD_INDEX)/$(SHARD_TOTAL): empty slice, nothing to mutate"; \
	  exit 0; \
	fi; \
	echo "shard $(SHARD_INDEX)/$(SHARD_TOTAL) mutating: $$files"; \
	php -d memory_limit=-1 vendor/bin/infection \
	  --coverage=$(INFECTION_COVERAGE_DIR) \
	  --skip-initial-tests \
	  --filter="$$files" \
	  --threads=max \
	  --logger-summary-json=var/infection-summary-$(SHARD_INDEX).json \
	  --logger-github

.PHONY: test/mutation/gate
# Stage 3: fold every shard's summary JSON into the project-wide Covered MSI
# and fail if it is below the gate. See the script header for the math.
# EXPECTED_SHARDS (optional) makes the gate fail if fewer summaries than
# shards arrived, so a silently-skipped shard can't pass on partial data.
MIN_COVERED_MSI ?= 95
EXPECTED_SHARDS ?=
test/mutation/gate:
	php .github/scripts/infection-aggregate-msi.php $(MIN_COVERED_MSI) 'var/infection-summary-*.json' $(EXPECTED_SHARDS)

.PHONY: test/check
# End-to-end self-test of the `check` gate: runs the real bin/xphp binary
# against the check fixtures and asserts the 0/1/2 exit contract plus that the
# text/json/github renderers all emit. Reused by release.yml against the built
# PHAR (override XPHP_BIN="php dist/xphp.phar"). Complements the in-process
# CheckCommandTest, which can't observe the shipped binary's process exit code.
test/check:
	sh test/smoke/check.sh

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
