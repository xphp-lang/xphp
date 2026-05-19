# xphp

**Real generics for PHP — single, multi-parameter, and nested — via monomorphization.**

`xphp` is a superset of PHP that adds `<T>` syntax for generics. Write `class Box<T>`, `class Pair<A, B>`, `class Map<K, V>`, even `class Box<Lst<Plastic>>` — any number of type parameters, any depth of nesting. A `.xphp` source file is compiled into plain `.php`: each unique generic instantiation becomes a real, specialized class with **native PHP type hints**, so reflection, OpCache, and the engine's own `TypeError` all work — because the output is just PHP.

Status: early. PHP 8.4+, MIT-licensed. Not yet on Packagist.

---

## Why not phpdoc `@template`?

`@template` is a hint for static analyzers. The engine doesn't see it, reflection doesn't see it, OpCache doesn't benefit from it, and `TypeError` never fires.

```php
/** @template T */
final class Box {
    /** @var T */ public mixed $item;
    /** @param T $v */
    public function set(mixed $v): void { $this->item = $v; }
}

$b = new Box(); // "Box<Plastic>" only in your IDE — not at runtime
$b->set('wrong type');     // no error
ReflectionProperty($b, 'item')->getType()->getName(); // "mixed"
```

`xphp` compiles `Box<Plastic>` into a real class where `$item` is genuinely typed `\App\Models\Plastic`. Assign the wrong thing and PHP throws.

---

## Quick example

**Write a generic template** (`src/Containers/Box.xphp`):

```php
<?php
declare(strict_types=1);
namespace App\Containers;

class Box<T>
{
    public T $item;

    public function set(T $val): void { $this->item = $val; }
    public function get(): T          { return $this->item; }
}
```

**Use it** (`src/Use.xphp`):

```php
<?php
declare(strict_types=1);
namespace App;

use App\Containers\Box;
use App\Models\Plastic;

$b = new Box<Plastic>();
$b->set(new Plastic('red'));
```

**Compile:**

```bash
bin/xphp compile src/ dist/ .xphp-cache/
```

**The generated specialization** (`.xphp-cache/Generated/App/Containers/Box/T_de1e0eaabedbafa176d971782f59025438ff880765b54fb07b0698700f21a0cd.php`):

```php
<?php
declare(strict_types=1);
namespace XPHP\Generated\App\Containers\Box;

class T_de1e0eaabedbafa176d971782f59025438ff880765b54fb07b0698700f21a0cd
{
    public \App\Models\Plastic $item;
    public function set(\App\Models\Plastic $val): void { $this->item = $val; }
    public function get(): \App\Models\Plastic          { return $this->item; }
}
```

**The rewritten call site** (`dist/Use.php`):

```php
<?php
declare(strict_types=1);
namespace App;

use App\Models\Plastic;

$b = new \XPHP\Generated\App\Containers\Box\T_de1e0eaabedbafa176d971782f59025438ff880765b54fb07b0698700f21a0cd();
$b->set(new Plastic('red'));
```

**At runtime, reflection sees the real type and `TypeError` fires on misuse:**

```php
(new ReflectionProperty(\XPHP\Generated\App\Containers\Box\T_de1e0eaabedbafa176d971782f59025438ff880765b54fb07b0698700f21a0cd::class, 'item'))
    ->getType()->getName();
// => "App\Models\Plastic"

$b->set(new App\Models\Metal(7));
// TypeError: ...::set(): Argument #1 ($val) must be of type App\Models\Plastic, App\Models\Metal given
```

---

## Multiple type parameters

Any number of type params, mixed scalars and classes, and full nesting all work the same way. Write:

```php
<?php
namespace App\Containers;

class Pair<A, B>
{
    public function __construct(public A $first, public B $second) {}
    public function swap(): Pair<B, A> { return new Pair<B, A>($this->second, $this->first); }
}

class Map<K, V>
{
    public function set(K $key, V $value): void { /* … */ }
}
```

Use them:

```php
$userPlastic = new Pair<User, Plastic>(new User('alice'), new Plastic('red'));
$nameAge     = new Map<string, int>();
$nested      = new Pair<Map<string, int>, Pair<Plastic, User>>($nameAge, $userPlastic->swap());
```

The compiler produces a distinct specialized class per unique arg combination — including the ones it discovers *transitively*: instantiating `Pair<User, Plastic>` and calling `swap()` causes `Pair<Plastic, User>` to be generated too, because the return type forces it.

Arg order matters: `Pair<User, Plastic>` and `Pair<Plastic, User>` are different classes (different argument order → different hash → different specialization). Specialized constructors enforce each slot independently — passing `(Plastic, User)` to `Pair<User, Plastic>` raises `TypeError` on the very first argument.

---

## What `xphp` supports today

- Single-type generics: `class Box<T>`
- Multi-type generics: `class Pair<A, B>`, `class Map<K, V>` (any number of params)
- Instantiation: `new Box<Plastic>()`
- Type-hint positions: `public Box<T> $b`, `function f(Box<T> $x): Box<T>`
- Nested generics: `new Box<Lst<Plastic>>()` — at any depth
- Transitive specialization: a `class Wrapper<T> { public Box<T> $b; }` instantiated as `Wrapper<Plastic>` automatically generates `Box<Plastic>` too
- Scalar type args: `Map<string, int>`
- Collision-safe naming: `App\X\Box<P>` and `App\Y\Box<P>` produce distinct specialized classes (no short-name collisions)

## What's not yet supported (and the rough order it's coming)

1. **`instanceof Box` checks.** `Box` itself no longer exists at runtime — only its specializations do — so `$plasticBox instanceof Box` returns `false` today. Planned fix: each specialization implements a generated base interface (`\XPHP\Internal\BoxInterface`) so the check works.
2. **Automatic composer autoload registration.** Right now you add the PSR-4 entry manually (see [Autoload the generated classes](#autoload-the-generated-classes)). A composer plugin would register `XPHP\Generated\ => .xphp-cache/Generated/` for you.
3. **Stream-wrapper / live transpilation.** Today `bin/xphp compile` is a build step. A custom stream wrapper would intercept `.xphp` includes and transpile in memory — no pre-build.
4. **Multi-segment generic constraints.** `class Box<T extends SomeInterface>` is not yet parsed or enforced; today every type parameter is unbounded.
5. **Phpdoc substitution.** Real type hints (`public T $item` → `public \App\Models\Plastic $item`) substitute correctly. Docblocks like `@var array<int, T>` are preserved as-is — readable but not rewritten.
6. **Cycle detection beyond a depth cap.** The specialization loop aborts after 16 iterations to catch pathological cases like `class Lst<T> { public Lst<Lst<T>> $r; }`. A real cycle detector would name the loop instead of just the depth.

---

## Install

Not on Packagist yet. Clone and use the bundled Docker setup:

```bash
git clone <repo-url> xphp
cd xphp
docker compose up -d
docker run --rm -v "$(pwd):/app" -w /app composer:2 install
```

`composer.json` pins `config.platform.php = "8.4.21"` so package resolution targets the project's PHP version regardless of which PHP runs composer itself — no `--ignore-platform-reqs` needed.

---

## Usage

### Compile

```bash
docker compose exec php php bin/xphp compile <source-dir> <target-dir> <cache-dir>
```

- `<source-dir>` — directory of `.xphp` files
- `<target-dir>` — where rewritten `.php` files land (your user code, generic call sites replaced)
- `<cache-dir>` — where specialized classes live (`.xphp-cache/` by default; gitignore it)

### Autoload the generated classes

Add a PSR-4 entry to your `composer.json` so composer finds the specialized classes without manual `require`s:

```json
{
    "autoload": {
        "psr-4": {
            "XPHP\\Generated\\": ".xphp-cache/Generated/"
        }
    }
}
```

Then `composer dump-autoload` once. From that point, every recompile makes new specializations immediately available via the standard autoloader — no further dumping needed (PSR-4 reads files lazily at runtime).

### Project layout that works well

```
your-project/
├── src/                  # your .xphp source
├── dist/                 # rewritten .php  (gitignored, generated)
├── .xphp-cache/          # specialized classes (gitignored, generated)
├── bin/build             # `xphp compile src/ dist/ .xphp-cache/`
└── composer.json         # PSR-4: XPHP\Generated\ => .xphp-cache/Generated/
```

---

## How it works

The pipeline runs in four phases:

1. **Parse + collect.** Each `.xphp` file is tokenized by PHP's own tokenizer; a custom scanner picks out `class X<…>` and `Name<…>` clauses (handles arbitrary nesting via depth tracking), strips them to plain PHP, then parses with `nikic/php-parser`. Generic metadata is attached back to the AST as node attributes.
2. **Fixed-point specialization.** For every concrete instantiation in the registry, the template AST is cloned and `T` substituted with the concrete type. Specializing a template body can introduce *new* instantiations (e.g. `Wrapper<Plastic>`'s body contains `Box<T>` → `Box<Plastic>`). The loop runs until no new entries appear.
3. **Rewrite.** Each `Name` carrying generic args is replaced with a `FullyQualified` reference to the specialized class. Generic class definitions are stripped from the target output.
4. **Emit.** Specialized classes go under `.xphp-cache/Generated/<original-fqcn-as-path>/T_<hash>.php`; rewritten user files go to the target directory.

The specialized class name is `T_` + the full 64-hex-char `sha256` digest over the canonical arg-list string. The namespace mirrors the original template's FQCN, so collisions across packages with the same short class name are impossible.

The hash length is configurable via the `XPHP_HASH_LENGTH` environment variable (16–64, default 64). Shorter is cosmetically nicer in stack traces and filenames; longer is more collision-resistant. The minimum of 16 hex chars (64 bits) keeps even pathologically large codebases safely below birthday-paradox collision risk. The variable is read once at CLI boot; invalid values fail the run loudly with a non-zero exit code.

```bash
XPHP_HASH_LENGTH=16 bin/xphp compile src/ dist/ .xphp-cache/
# → .xphp-cache/Generated/App/Containers/Box/T_de1e0eaabedbafa1.php
```

If two distinct generic instantiations would ever produce the same specialized FQCN, the compiler **fails loudly** with the colliding instantiations, the current hash length, and a copy-pasteable command to re-run with a longer hash. You don't need to read docs to figure out the fix.

### Why monomorphization (and what it costs)

The compiler emits one class per unique instantiation. `Box<Plastic>`, `Box<Metal>`, `Box<Lst<Plastic>>` are three separate generated classes. That's the cost: a codebase that instantiates the same generic with hundreds of distinct type combinations produces hundreds of generated files, each a near-identical body with the types substituted.

In exchange you get **zero runtime overhead** (the generated classes are plain PHP — OpCache treats them like any other class), **honest reflection** (`ReflectionParameter::getType()->getName()` returns the real concrete type), and **native `TypeError` enforcement** at the boundary, without having to write a single line of reflection-aware glue.

The alternative — type erasure (the path phpdoc `@template` and attribute-based generics take) — would generate fewer files, but it would re-introduce the very problem this project exists to solve. So: code-size cost in exchange for the type system actually being there at runtime.

---

## Development

Run the test suite (99 tests covering MVP, nested generics, multi-type generics, FQCN-collision safety, depth-cap cycle detection, visitor guard conditions, use-alias resolution, FQN-prefix args, and runtime `TypeError` verification):

```bash
docker compose exec php php vendor/bin/phpunit
```

A specific suite with readable names:

```bash
docker compose exec php php vendor/bin/phpunit \
    test/Transpiler/Monomorphize/MultiTypeGenericsIntegrationTest.php --testdox
```

Quick end-to-end compile against a bundled fixture:

```bash
mkdir -p var/play && cp -r test/fixture/compile/multi_type/source var/play/src
docker compose exec php php bin/xphp compile var/play/src var/play/dist var/play/.xphp-cache
find var/play/.xphp-cache -type f
```

### Mutation testing

The project tracks mutation-test coverage via [Infection](https://infection.github.io/). Current **MSI is 94%** (Mutation Score Indicator — the fraction of injected mutants killed by the test suite). The CI workflow fails any push/PR that drops it below 93%.

```bash
docker compose exec php php vendor/bin/infection --threads=4
```

`infection.json5` ships a curated set of per-mutator ignores for known-equivalent mutations (e.g. defensive `rtrim` calls on directory paths, `mkdir` permission octals, JSON pretty-print flags) so the report only surfaces real test gaps. The remaining ~30 escaped mutants are mostly token-stream boundary checks in the scanner (`<` vs `<=` on `$i < $n` style bound checks) — killing those requires synthesizing token streams that end exactly at the boundary, which is high effort per mutant and low signal for real-world correctness.

---

## License

MIT — see [LICENSE](LICENSE) (if present) or the `license` field in `composer.json`.
