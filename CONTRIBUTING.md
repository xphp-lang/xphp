# Contributing

## Test

```bash
make test/unit       # PHPUnit
make test/mutation   # Infection, MSI under a 95 % gate
```

CI gates every PR on both targets. `infection.json5` carries a curated set
of per-mutator `ignore` rules for genuinely-equivalent / defensive
mutations so the report only surfaces real test gaps.