# Contributing

## Test

```bash
make test/unit          # PHPUnit on the default PHP 8.4 runtime
make test/unit/php85    # only tests tagged `@group php85`; needs a PHP 8.5 runtime
make test/phpstan-pass  # only tests tagged `@group phpstan` (the `xphp check` PHPStan pass)
make test/mutation      # Infection, MSI under a 95 % gate
```

The supported runtime is PHP `^8.4`. Tests that exercise newer-PHP syntax
(e.g. the 8.5 pipe operator) are tagged `@group php85`; `make test/unit`
excludes them and they self-skip via `#[RequiresPhp]` off an 8.5 host. CI
runs them in a dedicated PHP 8.5 job.

The `xphp check` PHPStan-pass tests are tagged `@group phpstan` — they shell out
to a real phpstan subprocess (slow), so `make test/unit` excludes them and they
self-skip when `vendor/bin/phpstan` is absent (the same self-skip idea as
`@group php85`). CI runs them in a dedicated job; locally use `make
test/phpstan-pass`. (Not to be confused with `make lint/phpstan`, which runs
PHPStan over `src/`.)

No PHP 8.5 locally? Run them in the bundled 8.5 container:

```bash
docker compose run --rm php85 make test/unit/php85
```

CI gates every PR on these targets. `infection.json5` carries a curated set
of per-mutator `ignore` rules for genuinely-equivalent / defensive
mutations so the report only surfaces real test gaps.

## Architecture decisions

Architecturally significant decisions — the ones that are expensive to reverse —
are recorded as [Architecture Decision Records](docs/adr/README.md). When you make
such a decision, add a new ADR under `docs/adr/` (copy
[`docs/adr/0000-adr-template.md`](docs/adr/0000-adr-template.md)).