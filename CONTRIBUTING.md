# Contributing 

## Testing

### Unit tests

### Mutation tests

Mutation testing is the headline quality signal -- the test suite isn't just covering lines, it's surviving deliberate
code perturbations. Run via [Infection](https://infection.github.io/):

```bash
make test/mutation
```

**Current state (581 mutants generated):**

| Outcome              | Count   | Notes                                                             |
|----------------------|---------|-------------------------------------------------------------------|
| Killed by tests      | 542     | An assertion failed under the mutated code                        |
| Killed by timeout    | 8       | The mutation caused an infinite loop (e.g. the depth-cap fixture) |
| **Escaped**          | **31**  | See breakdown below                                               |
| **Covered Code MSI** | **94%** |                                                                   |

The 31 escapes split cleanly:

- **8 mathematically equivalent** -- `break` vs `continue` after `unset`; `>` vs `>=` on a bound whose message is
  constant either way; double-slash paths the filesystem normalizes; ltrim calls on values that are already-trimmed at
  insertion. No test can kill these without the source becoming less defensive.
- **22 scanner boundary checks** -- `<` vs `<=` on `$i < $n` end-of-stream guards in the manual token walker. Killing
  them requires synthesizing token streams that end exactly at the boundary the mutation flips. High effort per mutant,
  low signal for real-world correctness; well-formed PHP source never hits them.
- **0 mutations corresponding to a real-world bug class** that the suite isn't catching.

The CI workflow runs Infection on every PR and every push to `main`, failing the build if MSI drops below **93%**.
`infection.json5` carries a curated set of per-mutator `ignore` rules for equivalent / cosmetic cases so the report only
surfaces genuine test gaps when they appear.