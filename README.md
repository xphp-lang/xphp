# xphp

## What it is

`xphp` is a superset of `php` that gives developers real generics,
powered by monomorphization at compile time -- one specialized class
per concrete instantiation, no runtime dispatch overhead.

In a more inspirational mood, it is a fast lane for the `php` language, a bridge
between what developers need today and what `php` will support in the future.

> **Heads up**: `xphp` is heavily inspired by
> [PHP RFC: bound-erased generic types](https://wiki.php.net/rfc/bound_erased_generic_types),
> and the RFC drives the surface syntax -- turbofish `Name::<...>` at call
> sites, bare `<...>` at declarations and type-hint positions, `:` for bounds.
> The **intent** is that any `.xphp` source you write today stays valid against
> a future PHP runtime.
>
> Runtime semantics may diverge. `xphp` monomorphizes each generic
> instantiation into a distinct, fully-typed class -- the concrete type is
> baked in and visible to reflection. The RFC erases bounds at runtime
> instead. Both are honest design choices for different goals, and the gap
> may widen as the RFC evolves. `xphp` will track the syntax wherever
> practical and call out any divergence explicitly in the docs.

## How it works

Generics specialize into concrete classes with native typehints the engine
enforces, so the safety is real and the abstraction compiles away to nothing.

The compiler turns `xphp` into regular `php`. The runtime sees ordinary
classes; richer abstractions live entirely in the source you write and
at build time.

## Ecosystem and community first

The single biggest asset of any programming language is the community and
ecosystem around it, much more than its syntax and features.

We believe that meeting a community where it is, respecting their culture,
history and work compounds far better than asking them to leave all of that
behind.

The design choice to compile to vanilla `php` is a deliberate commitment to
**contribute** to the `php` community and its ecosystem.

## Principles

Whenever we make an architectural decision, it must be supported by the
following non-negotiable principles:

### 1. Zero Runtime Penalty

Abstractions should not cost performance. By relying on monomorphization rather
than runtime reflection hacks, the output is plain `php` classes. `opcache`
likes that, and execution speed remains identical to handwritten, optimized
`php` code.

### 2. Maximum Runtime Safety

`xphp` bakes the types directly into the generated `php` code. If a boundary is
crossed or a third-party plain `php` library misuses your code, it triggers a
native `php` error. The runtime never lies.

### 3. Progressive Enhancement

It must play nicely with normal `php` codebases. A team should be able to write
a single `xphp` file in a `php` application, compile it, and use it seamlessly.

No custom runtimes, no `HHVM` style ecosystem splits.

### 4. Developer Experience

The tooling must be fast and native as in every modern ecosystem. IDEs should
be able to read `xphp` files, while the `php` runtime happily consumes the
compiled `php` files.

## Generics: the start, not the finish line

Adding native generics to `php` --
a [long-awaited php feature](https://wiki.php.net/rfc/generics) --
is
genuinely [hard work](https://thephp.foundation/blog/2024/08/19/state-of-generics-and-collections/).

The object model that's served the ecosystem for two decades doesn't bend
easily.

Supporting generics — and now type aliases — proves that the compile-to-vanilla
model handles non-trivial type-system additions. Further features are on
the [roadmap](docs/roadmap.md):
literal types, mapped and conditional types to name a few.

## Quick start

```bash
composer require --dev xphp-lang/xphp
```

Add the autoload mapping to your `composer.json` and run
`composer dump-autoload`:

```json
{
  "autoload": {
    "psr-4": {
      "XPHP\\Generated\\": ".xphp-cache/Generated/",
      "App\\": ["src", "dist"]
    }
  }
}
```

Write a generic class and use it:

```php
// src/Collection.xphp
namespace App;

class Collection<T> {
    private array $items = [];

    public function add(T $item): void { $this->items[] = $item; }
    public function first(): ?T { return $this->items[0] ?? null; }
}

// src/Use.xphp
namespace App;

$users = new Collection::<User>();
$users->add(new User('Alice'));
$users->add(new User('Bob'));
echo $users->first()->name;
```

Compile:

```bash
vendor/bin/xphp compile src dist .xphp-cache
```

That's the whole loop: install, set up autoload, write `.xphp`,
compile. `dist/` holds your rewritten code; `.xphp-cache/Generated/`
holds the specialized classes. Both can be gitignored and rebuilt
in CI.

For a real project — and **required** once you consume another package's
generics — drop an `xphp.json` manifest at the project root instead of
repeating the paths on every invocation:

```json
{
  "sources": ["src"],
  "include": ["vendor/**"],
  "target": "dist",
  "cache": ".xphp-cache"
}
```

Then `compile`/`check` take no positional source — they resolve the
manifest (auto-detected in the working directory, or via `--config`):

```bash
vendor/bin/xphp compile        # compiles this package + every included one
vendor/bin/xphp check
```

`include` globs auto-discover installed xphp packages (`vendor/**` finds
every one, at any depth, with no edit when you add another), so the
downstream build compiles the whole union into its own output. See
[Getting started](docs/getting-started.md#the-recommended-project-setup-an-xphpjson-manifest)
for the distribution model. The single-directory form above keeps working
unchanged.

To validate generics without emitting anything — a CI gate that reports
every bound/variance/etc. problem with a `file:line`:

```bash
vendor/bin/xphp check src            # exit 1 if any error; --format=text|json|github
```

`check` runs all of xphp's generic validation (the specialization-loop guards
aside) and then, when those pass, runs **your** PHPStan over the compiled output
and maps the findings back to the `.xphp` template — one config, one gate (pass
`--no-phpstan` to skip it). You still run `compile` to emit the PHP. See
[Errors and diagnostics](docs/errors.md#xphp-check--validate-without-emitting).

## See also

- [Getting started](docs/getting-started.md) -- full walkthrough including PSR-4 details, runtime semantics, and what the generated PHP looks like
- [Syntax tour](docs/syntax/index.md)
- [Caveats](docs/caveats.md)
- [Type-system comparison](docs/guides/comparison.md)
- [Roadmap](docs/roadmap.md)
- [Changelog](CHANGELOG.md)
