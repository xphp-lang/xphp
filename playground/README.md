# xphp playground

Self-contained sandbox for poking at xphp features. Has its own `composer.json`
that depends on `xphp-lang/xphp` via a Composer path repository pointing
one level up — the same shape any downstream consumer would use.

## Usage

```bash
playground/bin/run
```

The first invocation runs `composer install` (which symlinks the
`core/` package into `playground/vendor/` via the path repo). After
that it compiles every `.xphp` under `src/`, then runs all the demos
in turn.

## What's in here

```
playground/
|-- composer.json        # own package, path-repo'd at ../core/
|-- bin/run              # compile + run all demos
|-- src/
|   |-- Models/          # plain final classes used as type args
|   |   |                #   (Animal, Cat, Dog, Food, Plastic, Stats, Tag,
|   |   |                #    User, Wolf -- Wolf is the subclass-protected
|   |   |                #    LSP completion fixture)
|   |-- Containers/      # the generic templates
|   |   |-- Box.xphp                #   Box<T>
|   |   |-- Pair.xphp               #   Pair<K, V>
|   |   |-- Map.xphp                #   Map<K, V>
|   |   |-- Collection.xphp         #   Collection<T> -- uses T[] + ?T sugar
|   |   |-- Wrapper.xphp            #   Wrapper<T> { Box<T> $box; } -- transitive
|   |   |-- Reified.xphp            #   Reified<T> -- new T(), T::class, instanceof T
|   |   |-- Repository.xphp         #   Repository<T> -- interface
|   |   |-- InMemoryRepository.xphp #   in-memory Repository<T> impl
|   |   |-- StringableBox.xphp      #   Box<T: \Stringable> -- bound demo
|   |   `-- Util.xphp               #   Util::identity<T> -- method/free-function generics
|   |-- Demos/           # top-level scripts that exercise each feature
|   |                    #   bucket -- compiled + executed by bin/run.
|   |                    #   (SingleType, MultiType, NestedTransitive,
|   |                    #    ArraySugar, Bounds, GenericInterface,
|   |                    #    GenericMethod, GenericFunction, Inheritance,
|   |                    #    InstanceofTemplate, Reified)
|   `-- LspFixtures/     # LSP-only fixtures -- opened in the IDE to verify
|                        #   editor behaviour (completion, hover, GTD).
|                        #   Compiled by bin/run but NOT in its execute list.
|                        #   (Phase3BoundAware, Phase3ClosedFile,
|                        #    Phase3ScopeAwareVars, Phase3StaticProp,
|                        #    Phase3TieBreak, Phase3Utf16)
`-- var/
    |-- cache/           # generated specialized classes (gitignored)
    `-- dist/            # rewritten .xphp -> .php (gitignored)
```

Each demo reflects on the compiled class to prove that the generic parameter
became a real native type — `ReflectionProperty::getType()->getName()` returns
the concrete class, not `mixed` or `T`. The reflection output is the load-bearing
assertion: it's what DI containers and serializers actually look at.

## Iterating

Edit any `.xphp` file under `src/`, then re-run `playground/bin/run`. The
compile step is idempotent and only rewrites changed paths' output. To start
fresh, `rm -rf playground/var/` and re-run.
