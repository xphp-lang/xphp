# Stage 1 -- Source parsing

[← How the xphp compiler works](../how-it-works.md)

PHP doesn't recognise `class Box<T>` or `function identity<T>(T $x)`
as legal syntax. nikic/php-parser would reject the source at the
first `<`. The compiler works around this with a token-level **strip
and reattach** trick implemented in
[`XphpSourceParser`](../../../src/Transpiler/Monomorphize/XphpSourceParser.php):

1. **Tokenise** the source via PHP's own `token_get_all`.
2. **Scan** for generic clauses -- `Name<...>` patterns -- with
   depth tracking so arbitrarily nested `Box<List<Plastic>>` constructs
   are handled.
3. **Blank** every `<...>` clause by overwriting it with spaces of
   equal length, producing plain valid PHP while keeping every byte
   offset and line number identical to the original source.
4. **Parse** the stripped source with nikic.
5. **Reattach** the generic metadata to the resulting AST as node
   attributes (`ATTR_GENERIC_PARAMS` on ClassLike declarations,
   `ATTR_GENERIC_ARGS` + `ATTR_TEMPLATE_FQN` on instantiation Names,
   `ATTR_METHOD_GENERIC_PARAMS` / `ATTR_METHOD_GENERIC_ARGS` on
   method-scope generics).

Because the `<...>` clauses are blanked with equal-length spaces,
generic-clause stripping needs no position bookkeeping at all -- AST
offsets round-trip to the original source for free. The one
length-changing rewrite is the `T[]` array-suffix sugar: `T[]` (3
bytes) becomes `array` (5 bytes), which shifts every offset to its
right. That's what
[`ByteOffsetMap`](../../../src/Transpiler/Monomorphize/ByteOffsetMap.php)
solves: it records each length-changing segment so any later byte
offset in the stripped source can be translated back into the
original -- which matters for editor diagnostics ("the offending
`<int>` is at line 12, column 5"). When no length-changing
replacement happened the map is the identity and returns the offset
unchanged. The pair of (AST, ByteOffsetMap) is returned together as
[`ParseWithMapResult`](../../../src/Transpiler/Monomorphize/ParseWithMapResult.php).

The parser exposes strict and tolerant modes, each in a with-map and
without-map variant:

- `parse()` / `parseWithMap()` -- strict mode, throws on parse
  errors. `bin/xphp compile` uses the strict path because
  compilation must fail on broken source.
- `parseTolerant()` / `parseTolerantWithMap()` -- recover from
  trailing parse errors by feeding the stripped source through
  nikic's error-handler-collecting mode. Used when callers need
  partial results from incomplete source.

---

[Index](../how-it-works.md) · Next: [Stage 2 -- Hierarchy and Registry construction](02-hierarchy-and-registry.md)
