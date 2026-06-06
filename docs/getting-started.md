# Getting started

Install xphp into a Composer project, set up the PSR-4 autoload so
the generated classes resolve, write your first generic, and run it.
Should take under ten minutes.

## Prerequisites

- **PHP 8.4+**.
- **Composer**.
- A project with a working `composer.json` (or run `composer init`
  first).

## 1. Install xphp as a dev dependency

```bash
composer require --dev xphp-lang/xphp
```

This puts the compiler at `vendor/bin/xphp` and pulls in the runtime
dependencies (`nikic/php-parser`, `symfony/console`).

## 2. Set up the PSR-4 autoload

xphp emits two kinds of files: rewritten user code (your `.xphp` with
the generic call sites resolved, now valid `.php`) and specialized
classes (each unique `Box<int>` / `Box<string>` / ... becomes its
own real class under the `XPHP\Generated\` namespace).

Both are picked up by Composer's standard PSR-4 autoloader once you
register them. Add to your project's `composer.json`:

```json5
{
  "autoload": {
    "psr-4": {
      // Specialized classes live in their own namespace
      // mirroring your template's namespace.
      "XPHP\\Generated\\": ".xphp-cache/Generated/",
      "App\\": [
        // Path where your normal php code lives.
        "src",
        // Path where xphp drops the rewritten .php files.
        // Same App\ namespace; PSR-4 finds the class in either.
        "dist"
      ]
    }
  }
}
```

Then rebuild the autoloader:

```bash
composer dump-autoload
```

> Replace `App\\` with your project's actual top-level namespace.

## 3. Write your first generic class

```php
// src/Container.xphp
<?php
declare(strict_types=1);

namespace App;

class Container<T> {
    public function __construct(public T $item) {}

    public function get(): T {
        return $this->item;
    }
}
```

```php
// src/Use.xphp
<?php
declare(strict_types=1);

namespace App;

$intContainer = new Container::<int>(42);
$strContainer = new Container::<string>('hello');

echo $intContainer->get(), PHP_EOL;
echo $strContainer->get(), PHP_EOL;
```

## 4. Compile

```bash
vendor/bin/xphp compile src dist .xphp-cache
```

| Argument        | Required | Default       | Purpose                                                                       |
|-----------------|----------|---------------|-------------------------------------------------------------------------------|
| `<source>`      | yes      | --            | Directory of `.xphp` files (PSR-4 layout).                                    |
| `<target>`      | no       | `dist`        | Where rewritten `.php` files land — your user code with generic args resolved. |
| `<cache>`       | no       | `.xphp-cache` | Where specialized classes live.                                                |

After the compile completes you'll have:

- `dist/Container.php` — the template stripped down to a marker
  interface.
- `dist/Use.php` — your script with `new Container::<int>(42)`
  rewritten to point at the specialized class.
- `.xphp-cache/Generated/App/Container/T_<hash>.php` — one
  specialized class file per unique instantiation.

Both `dist/` and `.xphp-cache/` can be gitignored — they're
generated artifacts your CI/CD pipeline rebuilds on every deploy.

## 5. Run it

Any normal PHP runtime that loads Composer's autoload will pick up
the generated classes via PSR-4. For example:

```php
// public/index.php (or wherever your entry point lives)
<?php
require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../dist/Use.php';
```

Expected output:

```
42
hello
```

## What just happened

The compiler:

1. Read the `.xphp` files and parsed the generic syntax.
2. Found two distinct instantiations: `Container<int>` and
   `Container<string>`.
3. Emitted two specialized classes under
   `.xphp-cache/Generated/App/Container/T_<hash>.php`, each with
   `int` or `string` baked into every signature.
4. Rewrote `src/Use.xphp` into `dist/Use.php`, replacing
   `new Container::<int>(...)` with a reference to the right
   specialized class.

PSR-4 routes the autoload, so neither you nor any downstream library
ever has to know the generated class names.

## Next

- [Syntax tour](syntax/index.md) — every shipped feature, one page each.
- [Caveats](caveats.md) — what's NOT supported and what to do
  instead.
- [Errors](errors.md) — verbatim catalog of every compile-time error
  message.
- [How it works](guides/how-it-works.md) — the compile pipeline,
  end to end.
- [Roadmap](roadmap.md) — what's coming next.
