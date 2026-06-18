# 11. PHAR distribution via Humbug Box

- Status: Accepted — 2026-06

## Context and Problem Statement

xphp is a build-time CLI ([ADR-0002](0002-build-time-transpiler.md)). Composer
users get `vendor/bin/xphp` for free, but plenty of places that want to run it
aren't Composer projects: CI scripts, Docker images, ops tooling, a quick
one-off. They need a way to run the compiler with stock PHP and nothing else. And
when xphp *is* installed as a Composer dependency, `bin/xphp` must find the right
autoloader regardless of how it was installed.

## Decision Drivers

- A single self-contained artifact runnable with stock PHP, no Composer.
- Don't ship development-only dependencies (notably PHPStan) to end users.
- Verifiable, reproducible release artifacts.
- `vendor/bin/xphp` must work whether xphp is the root project or a dependency.

## Considered Options

- **A single PHAR built with Humbug Box**, published on every release tag with a
  checksum; PHPStan stripped via a no-dev install.
- **Distribution only via Composer.**
- **A separate, hand-assembled binary repository.**

## Decision Outcome

Chosen: a **PHAR built with Humbug Box**, attached to every `v*` release tag
alongside a SHA-256 sum. The build does a `composer install --no-dev` before
packaging so development-only dependencies (PHPStan) are excluded, then restores
the dev install. The runtime dependency needed for the optional PHPStan pass
(`symfony/process`) *is* included, since the runner ships in the PHAR even though
PHPStan itself does not. Separately, `bin/xphp` probes for the Composer autoloader
in priority order — the Composer bin-proxy global, then the as-a-dependency path,
then the standalone path — so it works installed either way.

### Consequences

- Good: `curl` the PHAR and run it with stock PHP — no Composer, no install dance;
  CI and Docker get a one-file tool.
- Good: PHPStan is never shipped to users; they install it themselves and upgrade it
  independently of xphp's release cycle (see
  [ADR-0009](0009-phpstan-over-compiled-output.md)).
- Good: the published checksum lets consumers verify the download.
- Trade-off: a second distribution channel (PHAR + Composer) to build and test; the
  Box version is pinned so a new Box release can't silently change the artifact.

### Confirmation

[`box.json`](../../box.json) and the `build/phar` target in the
[`Makefile`](../../Makefile) (the `--no-dev` package-then-restore); the autoloader
probing in [`bin/xphp`](../../bin/xphp). The release workflow builds the PHAR and a
SHA-256 sum on every `v*` tag.

## Pros and Cons of the Options

### PHAR via Humbug Box

- Good: self-contained; stock-PHP runnable; dev deps stripped; checksum-verifiable.
- Bad: a second channel to maintain; depends on a pinned external packaging tool.

### Composer-only

- Good: nothing extra to build.
- Bad: excludes every non-Composer consumer (CI/Docker/ops/one-offs).

### Hand-assembled binary repo

- Good: full control over contents.
- Bad: manual, error-prone, and duplicative of what Box automates.

## More Information

- [Getting started](../getting-started.md).
- [ADR-0009](0009-phpstan-over-compiled-output.md) — why PHPStan is dev-only and the
  process dependency ships.
