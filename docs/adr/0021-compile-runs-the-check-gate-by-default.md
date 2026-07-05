# 21. `xphp compile` runs the check gate by default

- Status: Accepted — 2026-06

## Context and Problem Statement

`xphp compile` and `xphp check` were independent commands. `compile` transpiles `.xphp` → `.php`; `check`
runs the generic validators **and** PHPStan over the compiled output ([ADR-0009](0009-phpstan-over-compiled-output.md))
and is the authoritative "is this program sound?" gate. Because `compile` did not run that gate, a class of
errors slipped through it silently and surfaced only at runtime — most notably a **type argument that names
no real class** (`new Box::<Nonexistent>()`), which `compile` emitted as a reference to a phantom FQN
(`\App\Nonexistent`), fataling when the code ran.

Earlier attempts to catch this *inside* the transpiler were rejected: a hard error on an unresolved type
argument, and a fallback that scanned the project's `.php` files, both **false-rejected valid code** — a
plain-`.php` domain class used as a type argument (`new Producer::<Book>()`, intentionally supported) is
indistinguishable from a typo when the transpiler only sees its own source roots and cannot read the autoload
map. The transpiler is the wrong place to answer "does this class exist?"; PHPStan, running with the project's
real autoloader, already answers it soundly.

The question: how should `compile` stop emitting code that the gate would reject, without re-implementing
(unsoundly) the existence check PHPStan already does, and without taxing the fast edit/compile loop with a
multi-second PHPStan run on every build?

## Decision

**`compile` runs the same gate as `check` by default, before emitting anything**, and fails the build
(emitting nothing) when the gate reports an error. The shared gate is `CheckGate` (the generic validators
plus, on a clean pass, the PHPStan layer); `compile` and `check` both run it. One escape valve keeps it cheap:

- **`--no-check`** skips the gate entirely and compiles directly (the previous behavior) — for a fast local
  iteration build when the gate has already been run separately.

A missing PHPStan degrades to a non-failing warning (the build proceeds), matching `check`. `--no-phpstan`
runs only the generic validators (still a real gate, just without the PHPStan layer).

### A skip-when-unchanged marker was considered and rejected as unsound

An earlier draft added a content-hash marker (`<cacheDir>/.check-ok`) that skipped the gate when the inputs
hashed identically to the last green run, to spare no-op rebuilds the multi-second PHPStan floor. It was
dropped: the marker's key can only ever be a *proxy* for the gate's true dependency set, and the proxy is
strictly narrower than the real set. The gate's verdict also depends on the PHPStan config's transitive
`includes:` closure and on the whole autoload universe the emitted code can reference — neither of which the
key can cheaply capture. So the marker could report "current" for an input whose gate result had actually
changed, **skip a gate that would now fail, and re-emit rejected output at exit 0** — silently re-opening the
exact runtime-fatal hole the gate exists to close. A slower-but-sound default beats a fast one that
occasionally lies. Fast warm rebuilds are deferred to a sound mechanism (reusing PHPStan's own result cache,
which tracks its config and analyzed-file set) rather than a home-grown skip.

## Consequences

- **Good:** a typo'd or undeclared type argument (and every other PHPStan-detectable error) now fails at
  `compile`, not at runtime — "compile error over runtime error" holds for the default build. The soundness
  comes from PHPStan's real autoloader visibility, so no valid plain-`.php` domain class is ever
  false-rejected, and the transpiler gains no unsound `.php`-scanning machinery.
- **Good:** `--no-check` is always available for a guaranteed-fast build when the gate has already been run.
- **Trade-off:** the default `compile` now pays the PHPStan floor on every build (≈10× a bare compile on a
  small project), since the unsound skip marker was dropped. This is the deliberate safety/speed trade;
  `--no-check` bounds its reach for local iteration.
- **Trade-off:** `compile` now depends on PHPStan being resolvable for the full gate (it degrades to a
  warning when absent), where before it had no such dependency.
- **Deferred:** fast warm rebuilds via a *sound* cache — reusing PHPStan's own result cache (stabilize the
  representative emission paths and persist its cache directory) so it re-analyzes only what changed, without
  a home-grown skip that could false-hit. A single-emit optimization (run PHPStan over the real emitted output
  instead of a separately compiled representative set) and a `--require-phpstan` strict mode are further
  follow-ups.
