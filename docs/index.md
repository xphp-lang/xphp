# xphp documentation

**xphp** is a superset of PHP that gives developers real generics:
classes, interfaces, traits, methods, functions, closures, and arrow
functions can all take type parameters, with bounds, defaults,
variance, and forward-compatible turbofish syntax at every call site.

The compiler emits plain PHP. Specialized classes are real PHP
classes with concrete types baked in — reflection sees them, native
`TypeError` enforces them, and the runtime sees ordinary classes;
the compiler runs entirely at build time.

```php
<?php
class Container<T> {
    public function __construct(public T $item) {}
    public function get(): T { return $this->item; }
}

$intContainer = new Container::<int>(42);
$strContainer = new Container::<string>('hello');

echo $intContainer->get(), $strContainer->get();   // 42hello
```

## Where to go

| Topic | Use when |
|-------|----------|
| [Getting started](getting-started.md) | First time. Install + compile a generic class. |
| [Syntax tour](syntax/index.md) | Working through the new syntax feature-by-feature. |
| [Caveats](caveats.md) | Bumped into a rejection or surprising behavior. Look here first. |
| [Errors](errors.md) | Searchable reference of every compile-time error string. |
| [How it works](guides/how-it-works.md) | Curious about the compile pipeline (parse → specialize → emit). |
| [Runtime semantics](guides/runtime-semantics.md) | What the generated code looks like and why reflection/serializers behave the way they do. |
| [Comparison](guides/comparison.md) | TS / Kotlin / Rust experience — what carries over and what's different. |
| [Roadmap](roadmap.md) | What's shipped, what's queued. Generics are the start. |
| [Architecture decisions](adr/README.md) | Why xphp is shaped the way it is — the significant design choices and their trade-offs. |

## Heads up — divergence from the RFC

xphp is heavily inspired by
[PHP RFC: bound-erased generic types](https://wiki.php.net/rfc/bound_erased_generic_types),
and the RFC drives the surface syntax: turbofish `Name::<...>` at
call sites, bare `<...>` at declarations and type-hint positions,
`:` for bounds. The **intent** is that any `.xphp` source you write
today stays valid against a future native PHP runtime.

Runtime semantics may diverge. xphp monomorphizes each generic
instantiation into a distinct, fully-typed class — the concrete type
is baked in and visible to reflection. The RFC erases bounds at
runtime instead. Both are honest design choices for different goals,
and the gap is explicit in [comparison](guides/comparison.md) and
[caveats](caveats.md).

## More than generics

Generics are the first substantial chunk of work in xphp, but the
roadmap is much broader. See [roadmap](roadmap.md) for what's
shipped, what's coming next (PhpStorm syntax highlighting, LSP,
Composer plugin, source maps), and the long-term explorations (type
aliases, mapped types, variadic generics, generic enums, AST macros).
