# Stage 5 -- Rewriting and emission

[← How the xphp compiler works](../how-it-works.md)

Two transformations happen during rewrite, both implemented in
[`CallSiteRewriter`](../../../src/Transpiler/Monomorphize/CallSiteRewriter.php):

1. **Generic Name nodes become FullyQualified references.** Every
   Name node carrying `ATTR_GENERIC_ARGS` (with all args fully
   concrete) is replaced with a `FullyQualified` Name pointing at
   the Registry's generated FQN. This catches `new Box::<Plastic>(...)`,
   `Box<Plastic> $b`, `function f(): Box<Plastic>`, and similar --
   every position where a generic instantiation can appear.
2. **Generic ClassLike definitions become empty marker interfaces.**
   The original `class Box<T> { ... }` declaration is replaced at
   compile time with an empty `interface Box {}` at the same FQN.
   Every specialization (`Box_T_<hash>`) `implements` (or `extends`
   for interfaces) that marker. Generic traits are dropped entirely
   -- PHP can't `instanceof` a trait, so a marker would be useless.

After the rewrite, two file groups get written:

- **Specialized classes** to `<cacheDir>/Generated/<template-path>/T_<hash>.php`,
  one per unique `(template, args)` instantiation. Emitted by
  [`SpecializedClassGenerator::emit()`](../../../src/Transpiler/Monomorphize/SpecializedClassGenerator.php).
- **Rewritten user files** to `<targetDir>/<mirrored-source-path>.php`,
  preserving the source's PSR-4 layout. The original `.xphp`
  is pretty-printed back to PHP via `nikic/php-parser`'s
  `Standard` printer.

A summary of the registry (every template and every instantiation
with its generated FQN) is written to `<cacheDir>/registry.json` as
the final step.

---

Prev: [Stage 4.5 -- Variance-edge emission](04b-variance-edges.md) · [Index](../how-it-works.md) · Next: [Stage 6 -- Bound validation](06-bound-validation.md)
