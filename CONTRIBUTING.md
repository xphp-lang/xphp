# Contributing

## Test

```bash
make test/unit        # PHPUnit on the default PHP 8.4 runtime
make test/unit/php85  # only tests tagged `@group php85`; needs a PHP 8.5 runtime
make test/mutation    # Infection, MSI under a 95 % gate
```

The supported runtime is PHP `^8.4`. Tests that exercise newer-PHP syntax
(e.g. the 8.5 pipe operator) are tagged `@group php85`; `make test/unit`
excludes them and they self-skip via `#[RequiresPhp]` off an 8.5 host. CI
runs them in a dedicated PHP 8.5 job.

CI gates every PR on these targets. `infection.json5` carries a curated set
of per-mutator `ignore` rules for genuinely-equivalent / defensive
mutations so the report only surfaces real test gaps.