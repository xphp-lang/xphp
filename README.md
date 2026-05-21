# xphp

`xphp` is a superset of `php` that compiles directly into zero-overhead native, opcache-friendly `php` and gives
developers **runtime safety without runtime penalty**.

`xphp` contributes to the community in a pragmatic way. With that in mind, it works around the current Zend Engine
limitations by moving the heavy lifting to an Ahead-of-Time (`AOT`) compilation step.

---

## How it works

### 1. The `xphp` source

You define a generic class and instantiate it exactly as you would expect:

```php
// src/Collection.xphp
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

// src/main.xphp
$users = new Collection<User>(
    new User('Alice'),
    new User('Bob')
);
```

### 2. The compile command

```bash
`xphp` compile <source> <target> <cache>
```

| Argument   | Purpose                                                                              |
|------------|--------------------------------------------------------------------------------------|
| `<source>` | Directory of `.xphp` files (PSR-4 layout)                                            |
| `<target>` | Where rewritten `.php` files land -- your user code with generic call sites replaced |
| `<cache>`  | Where specialized classes live                                                       |

p.s. you can `gitignore` files in `<target>` and `<cache>` as they can be generated in your CI/CD pipeline.

### 3. The compiled native php

The compiler monomorphizes the generic `Collection<T>` class into a concrete `Collection_User` class. No impact on
the runtime, it's native `php` code.

```php
// <cache>/Generated/Collection_User.php
namespace XPHP\Generated;

use App\User;

// p.s. in reality it uses a hash in the class name,
// but developers won't touch that,
// only php runtime will see the hashed names.
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

### 4. Autoload the generated classes

Add a PSR-4 entry to `composer.json` so the standard autoloader finds the specialized classes without manual `require`,
then update your autoload file via `composer dump-autoload`.

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

### Project structure

```
your-project/
├── <source>         # php/xphp source files (PSR-4: namespace mirrors directory structure)
├── <target>         # rewritten .php (gitignored, generated)
├── <cache>          # specialized classes (gitignored, generated)
└── composer.json    # PSR-4: XPHP\Generated\ => <cache>/Generated/
```

---

## Ecosystem and community matter much more than syntax and features

The single biggest asset of any programming language is the community and ecosystem around it, much more than its
syntax and features. We believe that meeting a community where it is, respecting their culture, history and work
compounds far better than asking them to leave all of that behind.

As `xphp` is simply a superset of `php`, existing `php` code can be easily converted into `xphp` and `xphp` code can
seamlessly consume `php` -- little to zero effort either way.

The design choice to compile to vanilla `php` is a deliberate commitment to contribute to the `php` community and its
ecosystem, **not** to compete against them.

---

## Turning static illusions into runtime reality

For years, we've relied on a shared agreement to keep our `php` codebases safe: we use docblocks to tell our IDEs and
static analyzers what our data should look like. It's a wonderful system that has pushed the language to new heights.

However, there is a fundamental limit to this approach. The `php` engine doesn't read our static analysis rules. At
runtime, those type guarantees disappear, leaving our applications vulnerable exactly when it matters most.

`xphp` doesn't ask you to change how you think about types, but it does change how they are enforced. It relies on the
actual `php` engine.

Through a process called monomorphization, `xphp` reads your generic code and safely compiles it into specialized,
native `php` classes. If you write `Collection<User>` in `xphp`, the compiler automatically generates a physical
`Collection_User` class. Crucially, the native type hints are baked right in, meaning your code is protected by the
engine itself, not just a comment.

---

## Designing for the runtime, building for developers

Whenever we make an architectural decision, it must be supported by the following non-negotiable principles:

### 1. Zero Runtime Penalty

Abstractions should not cost performance. By relying on monomorphization rather than runtime reflection hacks, the
output is plain `php` classes (`Box_Int`, `Map_String_User`). `opcache` loves this, and execution speed remains
identical to hand-written, hyper-optimized `php`.

### 2. Maximum Runtime Safety

`xphp` bakes the types directly into the generated `php` code. If a boundary is crossed or a third-party plain `php`
library misuses your code, it triggers a native `php` error. The runtime never lies.

### 3. Progressive Enhancement

It must play nicely with legacy codebases. A team should be able to write one `xphp` class in a legacy `php`
application, compile it, and use it seamlessly. No custom runtimes, no `HHVM` style ecosystem splits.

### 4. Developer Experience First

The tooling must feel as fast and native as every modern web tool. IDEs should be able to read `xphp` files, while the
`php` runtime happily consumes the compiled `php` files.

---

## Generics: the start, not the finish line

Adding native generics to `php` -- a [long-awaited php feature](https://wiki.php.net/rfc/generics) -- is genuinely [hard
work](https://thephp.foundation/blog/2024/08/19/state-of-generics-and-collections/) (reification / variance / OpCache
cost / backwards compatibility). The object model that's served the
ecosystem for two decades doesn't bend easily.

Supporting generics proves that the compile-to-vanilla model handles non-trivial type-system additions. The remaining
features are on the [roadmap](docs/roadmap.md): type aliases, literal types, mapped and conditional types to name a few.

`xphp` doesn't wait for `php` internals to ship these features. It delivers them today, on top of the runtime the
community and ecosystem already trust.

- Full generics reference: [docs/generics.md](docs/generics.md)
- Side-by-side comparison against TypeScript, Kotlin, and Rust (what's there, what's missing, what's uniquely possible
  with monomorphization): [docs/generics-comparison.md](docs/generics-comparison.md)

---
