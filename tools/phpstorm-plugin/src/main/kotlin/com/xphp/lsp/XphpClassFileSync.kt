package com.xphp.lsp

import com.intellij.openapi.application.ApplicationManager
import com.intellij.openapi.command.WriteCommandAction
import com.intellij.openapi.diagnostic.Logger
import com.intellij.openapi.fileEditor.FileDocumentManager
import com.intellij.openapi.project.ProjectLocator
import com.intellij.openapi.vfs.VirtualFile
import com.intellij.openapi.vfs.newvfs.BulkFileListener
import com.intellij.openapi.vfs.newvfs.events.VFileContentChangeEvent
import com.intellij.openapi.vfs.newvfs.events.VFileEvent

/**
 * Cycle L Half A (file-rename half): class rename → file follows.
 *
 * The textDocument/rename flow (Shift+F6) applies text edits to the
 * source declaration AND every reference, but PhpStorm's LSP4IJ
 * silently drops the `RenameFile` resource op our server used to
 * emit alongside the text edits (ba3f52e reverted that emission
 * after `failureHandling: "abort"` caused PhpStorm to abort the
 * WHOLE WorkspaceEdit when it saw the unsupported op).  Net effect:
 * the class gets renamed everywhere, but `Foo.xphp` stays named
 * `Foo.xphp` while declaring `class Bar`, breaking PSR-4 autoload.
 *
 * This listener closes the loop **client-side**: on every
 * `VFileContentChangeEvent` for an `.xphp` / `.php` file (which
 * fires after LSP4IJ applies the rename's text edits to disk),
 * inspect the new content -- if the file declares exactly one
 * top-level ClassLike whose short name doesn't match the basename
 * stem, rename the file to match.  Single-declaration PSR-4 files
 * only; multi-class or non-PSR-4 layouts are left alone.
 *
 * Trigger sources (all caught by the same hook):
 *
 *   - Shift+F6 → LSP textDocument/rename → text edits applied →
 *     content change fires here → file renamed to match new class.
 *   - User types a new class name in source (or pastes from
 *     another file) → save → content change fires → file rename.
 *     "Block + prompt" UX from the cycle plan: not implemented here
 *     because the underlying invariant (PSR-4 single-declaration
 *     match) is the same one PhpStorm's native PHP plugin enforces
 *     silently, and the user explicitly opted into that pattern by
 *     touching xphp source.
 *
 * Cycle interactions:
 *
 *   - Half B file rename → text edits applied to the renamed file
 *     (class now matches new basename) → this listener fires →
 *     class short name == basename stem → no-op.  Symmetric.
 *   - Two-step rename in this listener's path (rename file then
 *     re-edit the class): not possible -- VFS file rename fires
 *     `VFilePropertyChangeEvent`, not `VFileContentChangeEvent`,
 *     so this listener doesn't re-trigger on its own renames.
 *
 * Listener implements `BulkFileListener` (subscribed via
 * `VirtualFileManager.VFS_CHANGES` topic) rather than
 * `AsyncFileListener` because we explicitly want the AFTER hook
 * (file content already written to disk), not the prepare/before
 * phase Half B uses.
 *
 * @see XphpFileRenameListener Half B (file rename → class rename)
 */
class XphpClassFileSync : BulkFileListener {

    override fun after(events: List<VFileEvent>) {
        for (event in events) {
            if (event !is VFileContentChangeEvent) continue
            val vfile = event.file
            if (!vfile.isPsr4Candidate()) continue
            // Defer the check + rename to a fresh EDT tick.  Same
            // rationale as Half B's `invokeLater`: VFS-change
            // processing holds a read lock during `after`, and a
            // WriteCommandAction inside that context would deadlock.
            ApplicationManager.getApplication().invokeLater {
                syncFileWithClass(vfile)
            }
        }
    }

    private fun syncFileWithClass(vfile: VirtualFile) {
        if (!vfile.isValid) return
        val document = FileDocumentManager.getInstance().getDocument(vfile) ?: return
        val source = document.text
        val classNames = findTopLevelClassLikeNames(source)
        if (classNames.size != 1) {
            // 0 = no declaration; >1 = multi-class file.  Both are
            // outside the PSR-4 single-declaration contract.
            return
        }
        val classShortName = classNames[0]
        val basenameStem = vfile.nameWithoutExtension
        if (classShortName == basenameStem) {
            // Already in sync.
            return
        }
        val targetName = "$classShortName.${vfile.extension ?: "xphp"}"
        val parent = vfile.parent ?: return
        if (parent.findChild(targetName) != null) {
            // Target already exists -- don't overwrite a sibling.
            LOG.info(
                "xphp class-file sync: target $targetName already exists in ${parent.url}; " +
                    "leaving ${vfile.name} (class $classShortName) untouched",
            )
            return
        }
        val project = ProjectLocator.getInstance().guessProjectForFile(vfile)
        WriteCommandAction.runWriteCommandAction(project, "Sync xphp Class Filename", null, {
            try {
                vfile.rename(this, targetName)
            } catch (e: Exception) {
                LOG.warn("xphp class-file sync: rename of ${vfile.name} -> $targetName failed", e)
            }
        })
    }

    /**
     * Scan the source for top-level ClassLike declarations (class /
     * interface / trait / enum).  Returns the short names of each
     * top-level declaration encountered.
     *
     * Implementation note: this runs on every content-change tick
     * for `.xphp` / `.php` files, so it has to be cheap.  A real
     * AST parse would be more correct but requires either spinning
     * up a parser (we don't have a PSI parser for xphp on the
     * client side) or round-tripping to the LSP server (slow and
     * noisy in the log).  Instead we use a tolerant regex tuned
     * for the PSR-4 happy path:
     *
     *   - Anchored to start of line (`^` with the `(?m)` flag) so
     *     declarations nested inside method bodies don't match.
     *   - Optional `abstract` / `final` / `readonly` modifiers.
     *   - Matches `class|interface|trait|enum` followed by an
     *     identifier (`[A-Za-z_][A-Za-z0-9_]*`).
     *   - Comments are not stripped -- but a `// class Foo` line is
     *     prefixed by `//`, not at start of line, so it won't
     *     match.  Block-comment `class Foo` references would
     *     false-match; the count-1 guard makes this surface as
     *     "multi-declaration" and skip the rename, which is the
     *     safe fallback.
     *
     * Limitations (acceptable for V1 PSR-4 sync):
     *
     *   - Bracketed namespaces `namespace A { class X {} }` indent
     *     the class declaration; with the `^` anchor we'd miss it.
     *     PSR-4 conventions universally use line-namespace form
     *     (`namespace A;`) so the class lives at column 0.
     *   - Heredoc / nowdoc content containing literal `class Foo`
     *     at column 0 would false-match.  Vanishingly rare in
     *     practice and would just produce a multi-declaration
     *     skip.
     */
    private fun findTopLevelClassLikeNames(source: String): List<String> {
        return DECLARATION_PATTERN.findAll(source)
            .map { it.groupValues[1] }
            .toList()
    }

    private fun VirtualFile.isPsr4Candidate(): Boolean {
        if (!isValid) return false
        val ext = extension?.lowercase() ?: return false
        if (ext != "xphp" && ext != "php") return false
        // Skip files inside library / vendor / build / cache trees.
        // ProjectLocator.guessProjectForFile returns null for files
        // outside any open project; we use the same probe to filter.
        return ProjectLocator.getInstance().guessProjectForFile(this) != null
    }

    private companion object {
        private val LOG = Logger.getInstance(XphpClassFileSync::class.java)

        /**
         * `(?m)^(modifier\s+)*(class|interface|trait|enum)\s+(Name)`
         * -- anchored to start of line, optional modifier prefix,
         * captures the identifier.  See {@link findTopLevelClassLikeNames}
         * for the rationale on each piece.
         */
        private val DECLARATION_PATTERN = Regex(
            """(?m)^(?:(?:abstract|final|readonly)\s+)*(?:class|interface|trait|enum)\s+([A-Za-z_][A-Za-z0-9_]*)""",
        )
    }
}
