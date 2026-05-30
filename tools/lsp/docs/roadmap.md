# Roadmap

Forward-looking inventory for the `xphp` Language Server. Intended for `php`
developers working with `xphp` generics: what the LSP delivers today, what
lands next, and what's still being scoped.

1. **Shipped** -- already in production, exercised by the test suite. Full
  descriptions in [`README.md`](../README.md#features).
2. **Planned** -- design is understood, no open questions. Effort sized as
  T-shirt sizes (S / M / L).
3. **Exploratory** -- value is real but the shape isn't. Each item carries a
  checklist of open questions, prior art, and a proposed initial step.

---

## Out of scope

### Native non-LSP integrations

The LSP is the canonical delivery channel. Editor-specific bindings should
consume LSP, not bypass it.

### Static analysis tools

Out of scope for the LSP itself -- those tools have their own LSP wrappers and
the user can stack them.

### IDE-specific integrations

Any features that would depend on the implementation details of a specific IDE.

e.g. "Extract method", "Inline variable", and similar refactoring operations
**MUST** be handled via IDE plugin/extension on top of the LSP features.

--- 

## Overview

Features grouped by theme, not chronological or priority ordering:

```mermaid
timeline
    section Shipped
        Navigation: definition: typeDefinition: references: implementation: call hierarchy: type hierarchy: documentSymbol: workspaceSymbol: documentHighlight
        Editing: rename: willRenameFiles: codeAction + resolve: codeLens + resolve
        Understanding: hover: signatureHelp: inlayHint: foldingRange: semanticTokens
        Validation: parse: bound: duplicate-template: undefined-bareword: ctor-arg-mismatch
        Completion: type-arg + member + static + variable: scope-aware insertText: completionItem/resolve
        Performance: warm AST cache: stub cache: tolerant parse: UTF-16 columns: short-name tie-break: lint mode
    section Planned
        Editing: prepareRename: selectionRange
        Navigation: documentLink
        Validation: argument-type checker V2: cross-file broadcast
    section Exploratory
        Editing: bound name hover/jump: formatting: documentColor
        Understanding: lowering preview: specialization explorer: instantiation inlay hints: bound-error fix-its: demangle FQN to source template
```

---

## Planned

Known shape, no open design questions. Listed in rough priority order.

### `prepareRename` -- pre-fill the rename dialog (S)

Currently, the editor pops the rename dialog with an empty input and lets the
user type the new name from scratch. `prepareRename` returns the symbol's
current span so the dialog opens pre-filled and the user just edits in place.
One handler, one AST walk to find the identifier under the cursor.

### `selectionRange` -- Ctrl+W expand-selection (S)

`textDocument/selectionRange` returns a chain of enclosing AST scopes for each
cursor. PhpStorm and VS Code both bind Ctrl+W / Ctrl+Shift+W to it.
Implementation is a tree walk producing `SelectionRange { range, parent }` per
anchor.

### Argument-type checker V2 -- methods, statics, free functions (M)

`xphp.ctor-arg-mismatch` (cosntructor arguments mismatch) V1 covers `new C(...)`
and `new C<T>(...)` only. V2 extends the same diagnostic to `$obj->m(...)`,
`Cls::m(...)`, and `freeFn(...)`. The hard parts (receiver-type resolution,
substitution through generics) already work for hover / signature help; the V2
checker reuses them and emits the same diagnostic shape as V1.

### `documentLink` -- clickable URLs in comments (S)

`textDocument/documentLink` returns ranges + URIs for URLs and PSR-4-style
references inside comments / docblocks. Editors underline them and `Cmd+Click`
opens. Low value compared to the above, listed for completeness.

### Cross-file diagnostic broadcast (M)

Today: editing `Box.xphp` re-publishes `Box.xphp`'s diagnostics only; `Use.xphp`
(which instantiates `Box<X>`) catches up the next time it's touched. The fix is
straightforward -- after each diagnostic pass, also re-publish for every file
whose AST cites the changed file's templates. The remaining work is the right
batching window so a rapid edit storm doesn't flood every consumer on every
keystroke.

---

## Exploratory

Each item has real user value but the design surface isn't pinned down.
Open questions, prior art, and a proposed initial step are captured per item;
settling those is a prerequisite to any implementation work.

### Lowering preview -- "show me the generated PHP"

**What it'd do.** A code lens or peek-window above any `new Foo<X>(...)` site
that opens the generated PHP for that specialization, side-by-side with the
source. Same affordance for generic method calls.

**Open questions.**

- Where do the generated sources live at edit time? The compiler writes to
  `var/dist/`; should those be surfaced as-is, or re-lowered on demand?
- How is the preview kept in sync as the source template changes? Re-lower on
  debounce, or invalidate on `didChange`?
- Webview / panel / lens-popup -- which surface fits PhpStorm and VS Code
  without diverging?

**Prior art to study.** Roslyn's "Show IL" feature; Rust analyzer's
"View Hir" / "Expand Macro Recursively" peek; TypeScript's "Run
Generic Inference" debugging view.

**Initial step.** A single read-only code lens that displays the
contents of `var/dist/<file>.php` for a hard-coded file is enough
to validate round-trip latency at typical project sizes before
the dynamic re-lowering path is designed.

### Specialization explorer -- every concrete `Box<X>` for a template

**What it'd do.** Cursor on `class Box<T>`, open a tool window that
lists every `Box<Tag>`, `Box<User>`, `Box<int>` instantiation across
the project, grouped by call site.

**Open questions.**

- VS Code has no native "tool window" concept beyond webviews;
  what's the best surface that doesn't diverge from PhpStorm?
- The `Registry` already knows the answer, but it's a per-session
  in-memory map. Persist, or re-derive on demand?
- What's the right grouping when one instantiation is reachable
  through multiple call sites?

**Prior art to study.** IntelliJ's "Hierarchy" toolwindow; PhpStorm's
"Type Hierarchy" but for type-args rather than supertypes. C++ tools'
template instantiation diagnostics.

**Initial step.** A server-side handler that, given a template
FQN, returns the `Registry`'s list of concrete instantiations,
exposed through `workspace/executeCommand xphp.listInstantiations`.
Prototype consumption from a single client (PhpStorm) before
unifying.

### Instantiation inlay hints -- show the specialized FQN inline

**What it'd do.** Render `// → Box_T_d59a1...` (or a shortened
hash) as an inlay hint at every `new Box<X>(...)` site so the
specialization a given call resolves to is visible without leaving
the editor.

**Open questions.**

- Hash characters are noise to read. Render the human-readable
  `Box<App\Models\Tag>` form instead? At what verbosity setting?
- Does this fight with PhpStorm's existing inlay-hint UX or
  complement it?
- Hint placement: end-of-line, after the `>`, or before the `(`?

**Prior art.** Rust analyzer's chained-call type hints;
PhpStorm's existing parameter-name hints.

**Initial step.** A new inlay-hint kind alongside the existing
variable-type one, gated by a config flag and validated against
the bundled playground fixtures.

### Reverse-map mangled FQN back to source template

**What it'd do.** When a stack trace or generated-PHP error
mentions `\XPHP\Generated\App\Containers\Box\T_d59a1...`, the
editor offers Ctrl+Click on that string to jump to `class Box<T>`
in the source.

**Open questions.**

- Surface origin: stack traces in run output? Manual paste into a
  search? A "Reveal source template" action on a hover over the
  mangled name in generated PHP?
- Hash length is configurable per project; how should resolution
  behave when two projects use different hash lengths?

**Prior art.** Java's stack-trace mangled-name resolution in
IntelliJ; Rust's `rustc-demangle` for symbol names.

**Initial step.** Expose the FQN → template lookup as a server
method `xphp.demangle`. Prototype consumption in PhpStorm's
"Analyze stacktrace" dialog as a transformation pass.

### Bound-error fix-its -- "implement missing interface" / "swap type-arg"

**What it'd do.** Today, a `Generic bound violated` diagnostic shows
the explanation but no quick fix. The fix-it would offer:

1. "Add `implements \Stringable` to `class App\Models\User`" -- one
   text edit on the supplied concrete type's declaration.
2. "Swap type-arg to `<Tag>`" -- show the list of project classes
   that already satisfy the bound, picked via the existing bound-
   aware completion filter.

**Open questions.**

- For (1), insertion of `implements` is a straightforward parser
  walk; the leading `\` qualification is the same problem
  `ClassNameImportContext` solves and can be reused.
- For (2), candidate ranking: alphabetical, by frequency in the
  project, or by current import status?
- Cross-file edits: the type-arg site and the class declaration
  may live in different files. LSP `WorkspaceEdit` handles this
  in the protocol, but the UI feedback in PhpStorm for multi-file
  actions is uneven.

**Prior art.** TypeScript LSP's "Add missing properties" quick
fix; Rust analyzer's "Implement trait" assist.

**Initial step.** Fix (2) wired end-to-end first (single-file
edit, reuses existing completion infrastructure). Fix (1) deferred
until the multi-file edit story is ironed out via the rename loop.

### Hover / jump on bound names in template headers

**What it'd do.** Cursor on `Stringable` inside
`class Box<T: \Stringable>` should hover the interface and Ctrl+Click
to its declaration. Today the `<...>` clause is stripped by the
XphpSourceParser before nikic sees the source, so no AST node is
positioned over the bound text.

**Open questions.**

- The parser strips the clause for a reason: it's not valid PHP
  and would crash nikic. Re-emit the bound as a synthetic `Name`
  node with the original source span attached? Or extend the
  LSP-side `AstPositionResolver` to recognise the bound region by
  string-matching?
- Same question for type-args: cursor on `T` inside
  `<T: \Stringable>` -- should that resolve as a type-param
  declaration?

**Prior art.** TypeScript LSP's handling of `<T extends U>`
positions; nikic's existing attribute system for source-span
retention.

**Initial step.** Detect the bound region via TextDocument regex
(XphpSourceParser already knows the strip ranges) and synthesize
a definition response without changing the parser. Hover follows
the same approach independently.

### `textDocument/formatting` + `rangeFormatting` + `onTypeFormatting`

**What it'd do.** Format-on-save for `.xphp` files.

**Open questions.**

- An xphp formatter doesn't exist yet. Either ship the PHP
  formatter (php-cs-fixer / nikic pretty-printer) over the
  stripped form, or write an xphp-aware formatter that preserves
  `<T>` clauses verbatim -- which one fits best?
- If a PHP formatter handles the stripped form, how should the
  generic clause round-trip without being eaten as a syntax error?

**Prior art.** Prettier's PHP plugin; PhpStorm's built-in
formatter when generics are present in PHPDoc.

**Initial step.** Formatter survey before any LSP plumbing -- the
formatter question gates the rest.

### `textDocument/documentColor` + `colorPresentation`

**What it'd do.** Detect color literals (`#fff`, `rgb(...)`) in
strings and surface a color picker on hover.

**Open questions.**

- Is there a meaningful PHP use case beyond CSS-in-PHP / template
  libraries?
- Listed for completeness; the value isn't validated yet, and the
  item should drop off entirely if no PHP-shaped use case
  materialises.
