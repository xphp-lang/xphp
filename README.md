# xphp

## What it is

`xphp` is a superset of `php` that gives developers real generics,
powered by [monomorphization](https://en.wikipedia.org/wiki/Monomorphization) at compile time.

In a more inspirational mood, it is a fast lane for the `php` language, a bridge
between what developers need today and what `php` will support in the future.

## How it works

Generics specialize into concrete classes with native typehints the engine
enforces, so the safety is real and the abstraction compiles away to nothing.

The compiler turns `xphp` into regular `php`. In the end it's good ~~old~~
modern `php`, but developers and AI agents have richer abstractions to design
better solutions.

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

Adding native generics to `php` -- a [long-awaited php feature](https://wiki.php.net/rfc/generics) --
is genuinely [hard work](https://thephp.foundation/blog/2024/08/19/state-of-generics-and-collections/).

The object model that's served the ecosystem for two decades doesn't bend easily.

Supporting generics proves that the compile-to-vanilla model handles non-trivial
type-system additions. The remaining features are on the [roadmap](docs/roadmap.md):
type aliases, literal types, mapped and conditional types to name a few.

## Getting started

### 1. Install the xphp package

```bash
composer require --dev xphp-lang/xphp
```

### 2. Enhance your PSR-4 autoload config

Add a PSR-4 entry to `composer.json` so the standard autoloader finds the
specialized classes without manual `require`.

```json5
{
  "autoload": {
    "psr-4": {
      // generics will be converted into specialized classes,
      // they need to have their own namespace.
      "XPHP\\Generated\\": "<cache>/Generated/",
      "App\\": [
        // path to your normal/regular php code
        "<source>",
        // some `xphp` files just need to be rewritten into native php to use specialized classes,
        // but their namespace will remain the same.
        "<target>"
      ],
    }
  }
}
```

After that, update your autoload file via `composer dump-autoload`.

### 3. Write `xphp` code

You can define a generic class and instantiate it exactly as you would expect:

```php
// <source>/Collection.xphp
namespace App;

class Collection<T> {
    private T[] $items;

    // The generic 'T' is used directly in the constructor signature
    public function __construct(T ...$items) {
        $this->items = $items;
    }

    public function first(): ?T {
        return $this->items[0] ?? null;
    }
}

// <source>/main.xphp
namespace App;

$users = new Collection<User>(
    new User('Alice'),
    new User('Bob')
);
```

### 4. Compile

```bash
vendor/bin/xphp compile <source> <target> <cache>
```

| Argument   | Required | Default        | Purpose                                                                              |
|------------|----------|----------------|--------------------------------------------------------------------------------------|
| `<source>` | yes      | --             | Directory of `.xphp` files (PSR-4 layout)                                            |
| `<target>` | no       | `dist`         | Where rewritten `.php` files land -- your user code with generic call sites replaced |
| `<cache>`  | no       | `.xphp-cache`  | Where specialized classes live                                                       |

p.s. you can `gitignore` files in `<target>` and `<cache>` as they can be generated in your CI/CD pipeline.

#### Sample output

The sample below uses a readable name (`Collection_User`) for clarity. The compiler actually emits hashed FQNs of the form `\XPHP\Generated\App\Collection\T_<hash>` -- see the [generics reference](docs/type-system/generics/index.md) for the real scheme.

```php
// <cache>/Generated/Collection_User.php
namespace XPHP\Generated;

use App\User;

class Collection_User {
    private array $items;

    // The generic 'T' is replaced natively with the concrete 'User' type
    public function __construct(User ...$items) {
        $this->items = $items;
    }

    public function first(): ?User {
        return $this->items[0] ?? null;
    }
}

// <target>/main.php
namespace App;

use XPHP\Generated\Collection_User;

// The generic instantiation is mapped directly to the generated class
$users = new Collection_User(
    new User('Alice'),
    new User('Bob')
);
```

### 5. Deploy

The compiler monomorphizes the generic classes and converts downstream code
into native `php` code.

Meaning every place where generics are declared or used is converted into normal
`php` code. No impact on the runtime. You still deploy `php` code.

### Project structure

```
<root>/
├── <source>         # php/xphp source files (PSR-4: namespace mirrors directory structure)
├── <target>         # rewritten .php (gitignored, generated)
├── <cache>          # specialized classes (gitignored, generated)
└── composer.json    # PSR-4: XPHP\Generated\ => <cache>/Generated/
```

## See also

- [Type-system comparison](docs/type-system/comparison.md)
- [Full generics reference](docs/type-system/generics/index.md)
