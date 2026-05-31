# roadmap

Forward-looking inventory for the PhpStorm-side of xphp tooling.

The plugin is a thin client over the [xphp Language Server](../../lsp/);
most editor capabilities come from the server. This roadmap covers
PhpStorm-specific work — distribution, UX polish that hits the
IntelliJ Platform's own surfaces, and integrations that wouldn't make
sense as LSP methods.

Three lanes:

- **Shipped** — already in production. Full descriptions in
  [`README.md`](README.md#features).
- **Planned** — design is understood, no open questions. Effort
  sized as **S** (~1 day), **M** (1-3 days), **L** (~1 week).
- **Exploratory** — value is real but the shape isn't. Each item
  carries a checklist of open questions, prior art, and a proposed
  first-step spike.

For LSP-level work see [`../../lsp/docs/roadmap.md`](../../lsp/docs/roadmap.md).

---

## Shipped

| Surface                                                   | Notes                                                                                                                                                                                                                                                                      |
|-----------------------------------------------------------|----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------|
| **`.xphp` file type recognition**                         | Bundled TextMate grammar wired through PhpStorm's `FileType` infrastructure.                                                                                                                                                                                               |
| **Zero-config server install**                            | LSP PHAR bundled inside the plugin jar; `PharExtractor` copies it to PhpStorm's system directory on first plugin load.                                                                                                                                                     |
| **All LSP-driven editor features**                        | Diagnostics, hover, GTD, find usages, completion, rename, code actions, code lens, call / type hierarchy, semantic tokens, signature help, inlay hints, folding ranges, document highlight, document / workspace symbols — see [`README.md#features`](README.md#features). |
| **PSR-4 class ↔ filename rename sync (both directions)**  | `XphpFileRenameListener` dispatches LSP 3.17 `willRenameFiles` on VFS moves; `XphpClassRenameListener` triggers the matching file rename when a class is renamed in source. Cross-directory file moves also update the namespace and every consuming `use` import.         |
| **Code lens click → native usage popup at lens position** | `XphpShowReferencesCommandsSupport` intercepts `editor.action.showReferences` from the server and anchors PhpStorm's usage chooser to the lens line, not the caret.                                                                                                        |
| **LSP binary override setting**                           | Preferences → Tools → xPHP → "xphp LSP binary" — for plugin developers iterating against a working-tree `bin/xphp-lsp`.                                                                                                                                                    |

---

## Planned

### Marketplace publication (S, blocked on infra)

`signPlugin` task is wired in `build.gradle.kts` but never invoked
locally. Listed as Planned because the steps are mechanical:
generate signing keys, store them as CI secrets, add a release
workflow that runs `signPlugin` + `publishPlugin` on tag push,
write the listing copy. The blocker is the JetBrains-account
signing-key setup, not engineering.

### `prepareRename` integration polish (S)

Once the LSP ships `prepareRename` (Planned at
[`../../lsp/docs/roadmap.md`](../../lsp/docs/roadmap.md#preparerename--pre-fill-the-rename-dialog-s)),
verify PhpStorm's rename dialog picks it up automatically. No
plugin code expected — IntelliJ's LSP API should consume it
natively. Tagged Planned because a smoke test is the entire
deliverable.

### Native "Generated PHP" peek action (M, depends on LSP lowering preview)

When the LSP exploratory item "Lowering preview" lands, add a
PhpStorm-native action ("View → Generated PHP") that pulls the
generated source from the LSP and opens it in a read-only editor
tab side-by-side with the source. PhpStorm pattern: extend
`AnAction` and use `FileEditorManager.openFile` with a virtual
`LightVirtualFile`. Sized after the LSP-side design lands.

### Plugin-side LSP capability gating (S)

Today the plugin advertises every IntelliJ-supported LSP capability
unconditionally. When we add new server-side capabilities (or
deprecate any), the plugin's `LspServerSupportProvider`
implementation should reflect that gated by version
negotiation. Mostly hygiene; user-visible only if a future server
version drops a capability that this plugin still advertises.

---

## Exploratory

### Headless CI integration tests for the plugin

**What it'd do.** Run a sandbox PhpStorm in headless mode against
the plugin zip in CI so regressions in rename / code lens / native
popup wiring are caught before manual prod testing.

**Open questions.**

- JetBrains' IDE distribution isn't licensed for headless CI use
  in the open-source case. Is there a `runIde --headless` mode
  that's licence-compatible, or do we need a Community-Edition
  distribution as the test runner?
- Plugin Verifier covers binary API compatibility but not runtime
  wiring. What's the right intermediate — JUnit-driven instances
  of `LightPlatformCodeInsightFixtureTestCase` that exercise the
  plugin's listeners against an in-memory `.xphp` project?
- Cost: CI minutes for the IDE boot per PR.

**Prior art to study.** JetBrains' own `intellij-platform-plugin-template`
test suite; how Scala plugin / Kotlin plugin teams run CI; the
`IntelliJPlatformTestRunner` setup used by JB internal teams.

**First-step spike.** Stand up a single
`LightPlatformCodeInsightFixtureTestCase`
that opens a virtual `.xphp` file and asserts the file-type
provider classifies it correctly. Measure CI minutes before
expanding.

### Compile-on-save integration

**What it'd do.** A run configuration that triggers
`bin/xphp compile` automatically when any `.xphp` file in the
project is saved, with the generated PHP written to the project's
`var/dist/` (or configurable).

**Open questions.**

- Where does "compile" live in the PhpStorm UI? A file watcher
  (PhpStorm's built-in FileWatcher API), a run configuration, a
  toolbar action, or a project setting?
- Per-file or whole-project? Per-file is fast but may produce
  inconsistent specialization sets; whole-project is correct but
  slow on every save.
- How do we surface compile errors? As LSP diagnostics on the
  source file (likely), or as a separate tool window?
- Does this conflict with existing PhpStorm File Watcher users
  who've configured their own `bin/xphp compile` watcher?

**Prior art to study.** PhpStorm's built-in Sass / TypeScript /
Less compile-on-save File Watchers; the Rust plugin's "Auto-import
crates" behaviour as a precedent for background invocation of a
toolchain command.

**First-step spike.** Wire a single FileWatcher template (XML
descriptor) for users to import manually. Validate the UX before
committing to a native action.

### Native Kotlin lexer / parser for `.xphp`

**What it'd do.** Replace the TextMate grammar with a full
IntelliJ PSI-aware lexer + parser written in Kotlin, unlocking:
IntelliJ-grade refactoring (extract method / inline / change
signature), structure view that reflects xphp generics natively,
custom intentions and inspections that don't go through LSP.

**Open questions.**

- The maintenance cost is significant — every parser-grammar change
  in the upstream `xphp` parser needs to be mirrored. Is there an
  intermediate where we generate the Kotlin grammar from the PHP
  parser's AST shape?
- LSP already delivers most of the editor experience. What's the
  marginal value of native PSI, and is it big enough to justify
  the second AST?
- IntelliJ's "Grammar-Kit" plugin generates lexer + parser from a
  BNF; would that close the gap with reasonable upkeep, or is a
  hand-written parser the only realistic path for PHP-with-generics?

**Prior art to study.** PhpStorm's own PHP plugin source; the
Kotlin plugin's path from JFlex / Grammar-Kit to its current
state; Rust plugin's similar tradeoff and how they chose.

**First-step spike.** Spike a lexer-only via Grammar-Kit that
tokenises `<…>` clauses without parsing them. Measure cost +
upkeep before committing to a full parser.

### Stack trace demangling

**What it'd do.** When the user pastes a stack trace into
PhpStorm's "Analyze Stacktrace" dialog (or the trace appears in a
run console), recognize mangled FQNs like
`\XPHP\Generated\App\Containers\Box\T_d59a1...` and turn them
into clickable links that jump to the source template (`class Box<T>`).

**Open questions.**

- Depends on the LSP exploratory item "Reverse-map mangled FQN".
  This plugin-side work is just the integration once the LSP
  method exists.
- PhpStorm's "Analyze Stacktrace" pipeline is extensible via
  `ExceptionFilter` / `Filter` — is the right hook there, or in a
  console output filter?
- Visual: do we replace the mangled segment with the human-
  readable form, or add a clickable hyperlink while keeping the
  original text?

**Prior art to study.** Kotlin's stack-trace inline-class
demangling in PhpStorm's IntelliJ counterpart; Rust plugin's
`rustc-demangle` integration.

**First-step spike.** Wait for the LSP method. Then start with the
console filter (lower-impact than rewriting the Analyze
Stacktrace dialog).

### Specialization explorer tool window

**What it'd do.** Bring up a docked tool window listing every
concrete instantiation of a generic template (`Box<Tag>`,
`Box<User>`, `Box<int>` …) grouped by call site, with click-to-
navigate.

**Open questions.**

- Same as the LSP-side exploratory item — depends on the server
  exposing the data. The plugin-side question is the IntelliJ
  ToolWindow shape.
- Is there a one-tool-window-per-template view, or one global
  "Specializations" window with a filter?
- Where does the entry point live — gutter icon next to
  `class Box<T>`, intention action, dedicated menu?

**Prior art to study.** PhpStorm's existing "Type Hierarchy"
toolwindow; the IntelliJ ToolWindow API documentation.

**First-step spike.** Wait for the LSP server endpoint. Prototype
a global "xphp Specializations" tool window populated from a
hard-coded list before wiring real data.

---

## Out of scope (not on the roadmap)

- **PhpStorm < 2026.1 support.** Locked out by `since-build = 261`.
  Backporting via LSP4IJ adds significant code for an MVP. Revisit
  if a meaningfully large userbase reports they're stuck on an
  older PhpStorm.
- **Rider / IntelliJ IDEA / WebStorm targets.** The plugin
  registers under PhpStorm-specific extension points. Multi-IDE
  packaging is non-trivial and only worth it if there's demand.
- **A separate IDE plugin without the LSP path.** The LSP is the
  canonical delivery channel; the plugin's value is the
  IntelliJ-native niceties layered on top, not a parallel
  implementation.
