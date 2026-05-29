# tools/lsp roadmap

LSP server (PHP, on `phpactor/language-server`). Drives both editor
clients via the same protocol surface.

For the compiler / language roadmap see [`../../core/roadmap.md`](../../core/roadmap.md).

```mermaid
timeline
    section Shipped
        LSP server
                : LSP server at tools/lsp/ (PHP on phpactor/language-server)
                : LSP -- live diagnostics (parse errors / bound violations / duplicate templates / undefined-bareword / xphp.ctor-arg-mismatch)
                : LSP -- hover with generic-T -> concrete substitution through property fetches
                : LSP -- go-to-definition (incl. union/intersection receiver picker)
                : LSP -- typeDefinition (jumps to the type-arg class through xphp generics)
                : LSP -- find references with interface-implementation walks both ways
                : LSP -- rename symbol (alias-aware, file rename when client supports it)
                : LSP -- documentHighlight (in-file occurrence highlighting)
                : LSP -- documentSymbol outline (Cmd+O / Structure panel)
                : LSP -- foldingRange (class / method / closure bodies + xphp <...> clauses)
                : LSP -- completion (incl. completionItem/resolve, union/intersection fan-out)
                : LSP -- signatureHelp (active-parameter highlight, type-arg substitution)
                : LSP -- inlayHint (inline substituted return / parameter types)
                : LSP -- codeAction + resolve (Import class, Simplify FQN, Optimize Imports, typo fixes)
                : LSP -- codeLens (Show references above every declaration)
                : LSP -- prepareCallHierarchy + incoming/outgoing
                : LSP -- semantic tokens (typeParameter color for xphp T references)
                : LSP -- workspace/symbol + didChangeWatchedFiles
                : LSP -- workspace/executeCommand xphp.showReferences (codeLens click target)
                : LSP -- durable per-user stub cache (XDG / Library/Caches / LOCALAPPDATA / tmp fallback)
                : LSP -- tolerant-parse fallback (in-memory locator survives mid-edit syntax errors)
                : LSP -- UTF-16 column counting (correct positions past supplementary-plane chars)
                : LSP -- short-name tie-break (canonical src/ wins over tests / fixtures / vendor)
    section Long-term
        LSP capabilities -- low effort
                : Implementation (list implementors of an interface / abstract method)
                : prepareRename (pre-fill the Shift+F6 dialog with current symbol)
                : selectionRange (Ctrl+W expand selection walks AST scopes)
                : documentLink (clickable URLs in comments / docblocks)
                : Pull-mode diagnostics (LSP 3.17 modernization)
        LSP capabilities -- medium effort
                : codeLens/resolve with reference counts (N references / 0 references)
                : prepareTypeHierarchy + super/subtypes
                : Method / static / function-call argument-type checker V2
        LSP capabilities -- xphp-unique
                : Show generated PHP at any specialization site (lowering preview)
                : Specialization explorer (every concrete Box<X> for a generic class)
                : Inlay hint of the specialized FQN at instantiation sites
                : Reverse-map mangled FQN back to the source template
                : Bound-error fix-its (implement missing interface, swap type-arg)
```
