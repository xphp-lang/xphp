# Features

This is a reference for every feature from the
[overview mindmap](../../README.md), grouped by the same six themes:

1. Navigate
2. Edit
3. Understand
4. Validate
5. Find
6. Performance

Each section names the LSP wire method where applicable and describes the `xphp`
specific behaviour layered on top.

For forward-looking work (planned, exploratory), see
[`roadmap`](../roadmap.md).

---

## Navigate

### Go to Definition

LSP method: `textDocument/definition`.

Resolves the symbol under the cursor to its declaration. Works on
classes, functions, methods, properties, `use` import aliases, and
PHP / phpstorm-stubs native symbols. Crucially, resolution flows
through xphp generics: if `$users` is declared as `Collection<User>`
and the cursor sits on `$users->first()`, the jump lands on the
correct `User` method, not on the template's placeholder `T`. Union
and intersection receivers fan out to a per-constituent picker so
each branch is reachable individually.

### Go to Type Definition

LSP method: `textDocument/typeDefinition`.

Jumps to the class behind a variable's type, walking through generic
substitution. Cursor on `$users` declared as `Collection<User>` jumps
to `class User` rather than `class Collection`. Useful for verifying
that type-arg inference matches the developer's mental model.

### Find References

LSP method: `textDocument/references`.

Project-wide reference search for classes, functions, methods, and
properties. Two distinguishing behaviours:

- Subclass receivers are walked, so a search from `Base::m()` finds
  call sites on instances typed against `Derived` (or any further
  subclass).
- Interface-implementation walks run in BOTH directions: cursor on
  `Iface::m` matches every implementor's call site; cursor on
  `Impl::m` matches receivers typed against the interface.

### Find Implementations

LSP method: `textDocument/implementation`.

Lists every implementor of an interface or abstract method, plus
subclass overrides. Complements Go to Definition (which lands on the
declaration) by enumerating the concrete downstream sites.

### Call Hierarchy

LSP methods: `textDocument/prepareCallHierarchy`,
`callHierarchy/incomingCalls`, `callHierarchy/outgoingCalls`.

Bidirectional call graph for a selected method or function. V1 is
intentionally lenient on receiver-type disambiguation (matches by
name only), matching IntelliJ Java's behaviour for the same surface.

### Type Hierarchy

LSP methods: `textDocument/prepareTypeHierarchy`,
`typeHierarchy/supertypes`, `typeHierarchy/subtypes`.

Bidirectional supertype / subtype tree for any class or interface.
Walks both directions of the `extends` / `implements` graph from the
selected ClassLike.

### Document Symbol

LSP method: `textDocument/documentSymbol`.

Hierarchical outline of every ClassLike, function, and method
declaration in the current file. Powers Cmd+O / Ctrl+F12 Structure
popups in IDE editors.

### Workspace Symbol

LSP method: `workspace/symbol`.

Cross-file FQN search backed by an in-memory index built lazily on
first query and refreshed via `workspace/didChangeWatchedFiles`.
Powers Go to Class / Go to Symbol popups.

### Document Highlight

LSP method: `textDocument/documentHighlight`.

In-file occurrence highlighting. Placing the cursor on any symbol
underlines every other use of that symbol within the same file.

---

## Edit

### Rename

LSP method: `textDocument/rename`.

Alias-aware short-name rewriting across the project. When a class is
aliased via `use Foo\Bar as Baz;`, the rename respects the alias
boundary at each reference site.

The PhpStorm plugin closes the PSR-4 loop end-to-end on top of the
base LSP behaviour:

- Shift+F6 on a class renames the file to match the new class name.
- Renaming a file in the project tree updates the class declaration
  and every reference site.
- Cross-directory file moves also update the namespace declaration
  and every consuming `use` import.

### Workspace file rename

LSP method: `workspace/willRenameFiles` (LSP 3.17).

Pre-rename hook that returns text edits the editor applies before
the rename commits. Used to keep class declarations and references
in sync when the editor (not a user-triggered Shift+F6) initiates
the file rename.

### Code Actions

LSP methods: `textDocument/codeAction`, `codeAction/resolve`.

Quick fixes and refactorings, computed lazily via the resolve
round-trip so cursor movement stays responsive. Currently offered:

- **Import class** -- when a bare short name resolves to a known
  FQN, offer one action per candidate.
- **Simplify FQN** -- shrinks `\App\Models\User` to `User` and adds
  the matching `use` statement.
- **Optimize Imports** -- drops unused `use` lines from the active
  file.
- **"Did you mean `null` / `true` / `false`?"** typo fixes attached
  to `UndefinedName` diagnostics, using Levenshtein distance against
  the small set of constants frequently misspelled as a bareword.

### Code Lens

LSP methods: `textDocument/codeLens`, `codeLens/resolve`.

"Show references" lens above every class / interface / trait / enum
/ function / method declaration. The resolve step fills in a lazy
reference count; clicking the lens opens a chooser popup
(`editor.action.showReferences`) -- natively in VS Code, dispatched
client-side by the PhpStorm plugin so the popup anchors at the lens
position rather than the caret.

---

## Understand

### Hover

LSP method: `textDocument/hover`.

Quick documentation for whatever the cursor sits on, with xphp
generics folded in. Beyond standard class / function / method /
property / native function info, hover renders:

- Parameter and return-type substitution at static, instance, and
  free-function call sites.
- Generic `T` resolved to the concrete type, including through
  property fetches (`$item = $box->item` where `$box: Box<Tag>`
  shows `Tag`, not `T`).

### Signature Help

LSP method: `textDocument/signatureHelp`.

Inline parameter list with the active argument highlighted. Type-arg
substitution is baked into the rendered signature: a call to
`new Box<Tag>(...)` shows `Tag` rather than `T` in the parameter
hint. Works at static, instance, and free-function call sites.

### Inlay Hints

LSP method: `textDocument/inlayHint`.

Inline substituted variable types after assignments. For example,
`$user = $users->first()` where `$users` is `Collection<User>`
renders the inferred type `?App\Models\User` inline so the type
isn't hidden behind a hover.

### Folding Range

LSP method: `textDocument/foldingRange`.

Collapsible regions for class / method / closure bodies plus xphp
`<...>` generic clauses (so the visual noise of a long type-arg
list can be folded away in deeply-nested generic call sites).

### Semantic Tokens

LSP method: `textDocument/semanticTokens/full`.

AST-driven syntax highlighting using the standard LSP token-type
legend. Type-parameter `T` references render with the
`typeParameter` color in generic-syntax positions, distinguishing
them visually from regular class references.

---

## Validate

Diagnostics surface in both push (`textDocument/publishDiagnostics`)
and pull (`textDocument/diagnostic`, LSP 3.17) modes. Five
diagnostic codes are emitted today:

### Parse errors

Syntax errors detected by nikic/php-parser after the xphp `<...>`
clauses are stripped, with positional spans that map back to the
original source via the byte-offset map. Tolerant-parse recovery
means a single typo doesn't suppress every later diagnostic in the
file.

### Generic bound violations

Compile-time validation of `T: Bound` against each concrete
type-arg. The hierarchy spans the whole project on disk (not just
open buffers), so `new Box<Tag>(...)` resolves correctly even when
`Tag.xphp` isn't currently open in the editor. Error messages
reference the source-level instantiation (e.g. `Box<int>`) rather
than the hashed specialization name.

### Duplicate template declarations

Fires when two files declare the same generic class / interface /
trait template at the same FQN. Pins to the second declaration's
file for actionability, since the first one is already in scope by
the time the duplicate is parsed.

### Undefined bareword warnings

Catches references to identifiers (functions, constants) that
aren't declared anywhere reachable. Paired with the "Did you mean
`null` / `true` / `false`?" code action so the obvious typo cases
are fixable in one keystroke.

### Constructor argument-type mismatch (`xphp.ctor-arg-mismatch`)

Post-monomorphization check on `new C(...)` and `new C<T>(...)`
call sites. Catches the case where the supplied argument's
statically-known type can't satisfy the constructor parameter's
declared type -- a runtime `TypeError` waiting to happen, surfaced
at compile time. Inference is intentionally narrow (literals,
`new ClassName(...)`, `true` / `false` / `null` const fetches) to
avoid false positives on arguments whose type would require flow
analysis to know.

---

## Find

### Completion

LSP method: `textDocument/completion`.

Context-aware completion in every meaningful position:

- **Type-arg position** (`new Box<|>(...)`) -- bound-aware
  filtering hides candidates that don't satisfy the slot's declared
  upper bound; scalars are dropped when the bound is class-like.
- **Member access** (`$obj->`) and **static access** (`Cls::`) --
  methods, properties, and constants from the receiver.
- **Static property access** (`Cls::$`) -- a distinct context kind
  so the `$` sigil round-trips correctly through accept.
- **Local variables** -- scope-aware: function / method / closure /
  arrow-function bodies don't leak names from sibling scopes.
- **Visibility filtering** inside same-class and subclass contexts
  (private members only inside the declaring class; protected
  members visible across subclass receivers, etc.).
- **Union / intersection receiver fan-out** -- union shows the
  permissive union of members; intersection shows the conservative
  intersection.
- **String / comment / docblock suppression** -- the popup
  doesn't fire inside literal text, so typing inside a string
  doesn't trigger a member-access menu.

The `insertText` for class-name candidates is scope-aware: bare
short name when the FQN is already imported or same-namespace,
aliased short name for `use Foo as Bar;`, leading-backslash
`\FQN` otherwise. Never inserts the qualified-but-not-FQ form
that would namespace-prepend at PHP name-resolution time.

### Lazy completion-item resolve

LSP method: `completionItem/resolve`.

Docblock fetch deferred until the user navigates to a specific
item. Keeps the popup responsive on cold start; full documentation
fills in as items receive focus, not for every candidate up front.

---

## Performance

Implementation properties that determine how the server behaves
under load, on cold start, and across editor sessions. Not LSP
methods in their own right, but visible to users through editor
responsiveness and reliability.

### AST cache (warmed on Initialize)

On the LSP `initialize` handshake, a background warmer parses every
filesystem-indexed `.xphp` / `.php` file under the project root
into a version-keyed cache. Cold "Show references" on a 200-file
workspace drops from roughly seven and a half seconds to under 200
milliseconds -- subsequent walks skip the per-file parse entirely.
The same cache feeds the bound-check hierarchy and the
template-definition registry, so cross-file generic diagnostics
work without any dependency files being open.

### Stub cache (durable, per-user)

worse-reflection's stub map (used to resolve PHP and
phpstorm-stubs native symbols) is serialised once per machine to a
cache directory resolved from `$XPHP_LSP_CACHE_DIR` -> XDG ->
`~/.cache/xphp-lsp` (Linux) -> `~/Library/Caches/xphp-lsp` (macOS)
-> `%LOCALAPPDATA%/xphp-lsp` (Windows) -> `<sys_temp>/xphp-lsp`
fallback. Survives reboots and `/tmp` reaping, so cold-start cost
is paid once per machine, not once per session.

### Tolerant-parse fallback

In-memory locators recover from trailing parse errors so mid-edit
source (`$x->|`, `new Foo<|`) still returns useful completion /
hover / GTD results. Without this fallback, every incomplete
keystroke would temporarily break the editor's intelligence and
force the developer to wait for the source to be syntactically
valid again.

### UTF-16 column counting

LSP positions are spec'd in UTF-16 code units, but PHP's native
string operations work in bytes. The server's `PositionMap`
translates between the two so positions stay accurate past
supplementary-plane codepoints (emoji and similar), avoiding the
off-by-N drift that would otherwise shift every position right of
the codepoint.

### Short-name tie-break

When the same short name (e.g. `User`) exists at multiple FQNs
across the project -- typically `src/Models/User.xphp` and
`tests/Fixtures/User.xphp` -- the resolver prefers the canonical
`src/` path. Test fixture and vendor paths score a penalty so
navigation lands on the production declaration by default.

### Headless `--lint` mode

CI-friendly entry point that doesn't require an LSP client:

```bash
tools/lsp/bin/xphp-lsp --lint path/to/file.xphp [more.xphp ...]
```

Output format is `<file>:<line>:<col>: <severity>: [<code>] <message>`
-- the same shape PHPStan and php-cli emit, so editors and CI
greps consume it without ceremony. Exits non-zero if any file has
diagnostics, zero otherwise. Useful in PRs today as a fast
syntax-and-bound-check pass independent of the LSP transport.
