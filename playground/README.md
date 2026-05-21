# xphp playground

Self-contained sandbox for poking at xphp features. Has its own `composer.json`
that depends on `xphp-lang/xphp-parser` via a Composer path repository pointing
one level up — the same shape any downstream consumer would use.

## Usage

```bash
playground/bin/run
```

The first invocation runs `composer install` (which symlinks the parent
project into `playground/vendor/` via the path repo). After that it compiles
every `.xphp` under `src/`, then runs all the demos in turn.

The Makefile at the repo root has a `playground` target wrapping the same
command:

```bash
make playground
```

## What's in here

```
playground/
├── composer.json        # own package, path-repo'd at ../
├── bin/run              # compile + run all demos
├── src/
│   ├── Models/          # plain final classes used as type args
│   ├── Containers/      # the generic templates
│   │   ├── Box.xphp     #   Box<T>
│   │   ├── Pair.xphp    #   Pair<K, V>
│   │   ├── Map.xphp     #   Map<K, V>
│   │   ├── Collection.xphp  # Collection<T> — uses T[] + ?T sugar
│   │   └── Wrapper.xphp     # Wrapper<T> { Box<T> $box; } — transitive
│   └── Demos/           # top-level scripts that exercise each feature bucket
└── var/
    ├── cache/           # generated specialized classes (gitignored)
    └── dist/            # rewritten .xphp → .php (gitignored)
```

Each demo reflects on the compiled class to prove that the generic parameter
became a real native type — `ReflectionProperty::getType()->getName()` returns
the concrete class, not `mixed` or `T`. The reflection output is the load-bearing
assertion: it's what DI containers and serializers actually look at.

## Iterating

Edit any `.xphp` file under `src/`, then re-run `playground/bin/run`. The
compile step is idempotent and only rewrites changed paths' output. To start
fresh, `rm -rf playground/var/` and re-run.
