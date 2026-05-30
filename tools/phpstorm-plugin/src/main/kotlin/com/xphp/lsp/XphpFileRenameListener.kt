package com.xphp.lsp

import com.intellij.notification.NotificationGroupManager
import com.intellij.notification.NotificationType
import com.intellij.openapi.application.ApplicationManager
import com.intellij.openapi.application.runReadAction
import com.intellij.openapi.command.WriteCommandAction
import com.intellij.openapi.diagnostic.Logger
import com.intellij.openapi.editor.Document
import com.intellij.openapi.fileEditor.FileDocumentManager
import com.intellij.openapi.project.Project
import com.intellij.openapi.project.ProjectManager
import com.intellij.openapi.vfs.AsyncFileListener
import com.intellij.openapi.vfs.VirtualFile
import com.intellij.openapi.vfs.newvfs.events.VFileEvent
import com.intellij.openapi.vfs.newvfs.events.VFileMoveEvent
import com.intellij.openapi.vfs.newvfs.events.VFilePropertyChangeEvent
import com.intellij.platform.lsp.api.LspServer
import com.intellij.platform.lsp.api.LspServerManager
import org.eclipse.lsp4j.FileRename
import org.eclipse.lsp4j.Position
import org.eclipse.lsp4j.RenameFilesParams
import org.eclipse.lsp4j.ResourceOperation
import org.eclipse.lsp4j.TextDocumentEdit
import org.eclipse.lsp4j.TextEdit
import org.eclipse.lsp4j.WorkspaceEdit
import org.eclipse.lsp4j.jsonrpc.messages.Either
import java.util.concurrent.CompletableFuture

/**
 * Cycle L Half B: file rename -> class rename sync.
 *
 * IntelliJ fires VFS rename events through the
 * `AsyncFileListener` extension point.  For each rename of an
 * `.xphp` / `.php` file, this listener sends a
 * `workspace/willRenameFiles` request to the xphp LSP server,
 * which returns a `WorkspaceEdit` renaming the in-source class
 * declaration to match the new basename (plus every workspace
 * reference to it).  The plugin applies those text edits via a
 * write action.
 *
 * Safety: the server's `XphpWillRenameFilesHandler` returns a
 * null WorkspaceEdit (no edits) when the file isn't a single-
 * declaration PSR-4 candidate (multi-class file, basename
 * mismatch, etc.).  This listener silently no-ops in those cases
 * -- the file stays renamed, source stays untouched.  An
 * informational notification surfaces so the user knows the
 * class rename didn't fire.
 *
 * Why `AsyncFileListener` (and not `BulkFileListener`):
 * - Runs off the EDT in the prepare phase, then commits via the
 *   `ChangeApplier.afterVfsChange()` hook on the EDT -- the
 *   right contract for LSP request + write-action coordination.
 * - Sees synthetic `VFilePropertyChangeEvent`s with
 *   `propertyName = PROP_NAME`, the canonical signal for a file
 *   rename (covers project-tree rename, F2, refactor-rename).
 *
 * Half A (class rename -> file rename) is NOT covered here.  The
 * xphp server emits `RenameFile` ops in `textDocument/rename`
 * responses when `initializationOptions.xphpAcceptsRenameFile`
 * is set (see XphpLspServerDescriptor); whether LSP4IJ's
 * internal `LspWorkspaceEditApplier` actually applies them is
 * a prod-test question.  If not, that's the follow-up cycle.
 */
class XphpFileRenameListener : AsyncFileListener {

    override fun prepareChange(events: List<VFileEvent>): AsyncFileListener.ChangeApplier? {
        // Filter to .xphp/.php rename + move events; collect (oldUri,
        // newUri) pairs.  Off-EDT phase: safe to read VFS attributes,
        // not safe to apply writes (write actions must run on the EDT
        // in the committer below).
        //
        // Two event shapes feed into the same FileRename pair:
        //   - VFilePropertyChangeEvent (PROP_NAME) -- in-place rename
        //     (same parent dir, different basename).  Drives Half B's
        //     class-name update.
        //   - VFileMoveEvent -- cross-directory move (different parent
        //     dir, same OR different basename).  Drives Cycle L.1's
        //     namespace update.  Reuses the same willRenameFiles
        //     dispatch; the server routes pure moves through
        //     NamespaceMoveProvider and combined cases through the
        //     existing rename pipeline.
        val renames = mutableListOf<FileRename>()
        for (ev in events) {
            if (ev is VFilePropertyChangeEvent
                && ev.propertyName == VirtualFile.PROP_NAME
                && ev.file.isXphpLike()
            ) {
                val oldName = ev.oldValue as? String ?: continue
                val newName = ev.newValue as? String ?: continue
                if (oldName == newName) continue
                val parentUrl = ev.file.parent?.url ?: continue
                renames.add(FileRename("$parentUrl/$oldName", "$parentUrl/$newName"))
                continue
            }
            if (ev is VFileMoveEvent && ev.file.isXphpLike()) {
                // VFileMoveEvent.file is the moved file referenced at
                // its NEW location; oldParent / newParent give the
                // before/after directory.  Construct oldUri from
                // oldParent + basename, newUri from file.url directly.
                val basename = ev.file.name
                val oldParentUrl = ev.oldParent.url
                val newUri = ev.file.url
                renames.add(FileRename("$oldParentUrl/$basename", newUri))
            }
        }

        if (renames.isEmpty()) return null

        return object : AsyncFileListener.ChangeApplier {
            override fun afterVfsChange() {
                // VFS rename is now applied; ask each active xphp LSP
                // for the corresponding class-rename WorkspaceEdit and
                // commit it under a single undoable WriteCommandAction.
                applyRenames(renames)
            }
        }
    }

    private fun applyRenames(renames: List<FileRename>) {
        // Find the xphp LSP server for each project that has one
        // running.  ProjectManager.openProjects scans every open
        // project window -- typically one, occasionally more.
        for (project in ProjectManager.getInstance().openProjects) {
            val server = findXphpServer(project) ?: continue
            val edit = requestWillRenameFiles(server, renames) ?: continue
            applyWorkspaceEdit(project, edit, renames)
        }
    }

    private fun findXphpServer(project: Project): LspServer? {
        return LspServerManager.getInstance(project)
            .getServersForProvider(XphpLspServerSupportProvider::class.java)
            .firstOrNull()
    }

    private fun requestWillRenameFiles(server: LspServer, renames: List<FileRename>): WorkspaceEdit? {
        val params = RenameFilesParams(renames)
        return try {
            server.sendRequestSync<WorkspaceEdit?>(LspServer.DEFAULT_REQUEST_TIMEOUT_MS) { ls ->
                @Suppress("UNCHECKED_CAST")
                ls.workspaceService.willRenameFiles(params)
                    as CompletableFuture<WorkspaceEdit?>
            }
        } catch (e: Exception) {
            LOG.warn("workspace/willRenameFiles request failed", e)
            null
        }
    }

    /**
     * Apply text edits from the server's WorkspaceEdit response under
     * one undoable WriteCommandAction.  Each TextDocumentEdit targets
     * a URI we resolve back to a VirtualFile + Document; edits within
     * a document are applied bottom-up (end offset descending) so
     * earlier ranges don't shift while later ones are still pending.
     *
     * Skips RenameFile / CreateFile / DeleteFile resource operations
     * -- the server doesn't emit those for `workspace/willRenameFiles`
     * (the client is performing the file move) but defensively
     * ignoring them keeps the code robust to future protocol drift.
     *
     * Threading: `AsyncFileListener.afterVfsChange` runs off-EDT with
     * a read lock held.  Calling `WriteCommandAction.runWriteCommandAction`
     * directly from there deadlocks (read lock blocks write lock
     * acquisition) and PhpStorm logs "Cannot execute background
     * write action in 10 seconds" after the timeout, dropping the
     * edits silently.  Schedule the apply onto the EDT via
     * `invokeLater` so the write action runs after VFS-change
     * processing has released its read lock.
     */
    private fun applyWorkspaceEdit(project: Project, edit: WorkspaceEdit, renames: List<FileRename>) {
        val docChanges = edit.documentChanges ?: run {
            notifyClassUnchanged(project, renames)
            return
        }
        val textEdits = docChanges.mapNotNull { it.takeIf(Either<TextDocumentEdit, ResourceOperation>::isLeft)?.left }
        if (textEdits.isEmpty()) {
            notifyClassUnchanged(project, renames)
            return
        }

        ApplicationManager.getApplication().invokeLater {
            WriteCommandAction.runWriteCommandAction(project, "Rename xphp Class to Match File", null, {
                for (docEdit in textEdits) {
                    applyTextDocumentEdit(docEdit)
                }
            })
        }
    }

    private fun applyTextDocumentEdit(docEdit: TextDocumentEdit) {
        val uri = docEdit.textDocument.uri
        val vfile = resolveVirtualFile(uri) ?: run {
            LOG.warn("workspace/willRenameFiles: edit targeted unresolvable URI $uri")
            return
        }
        val document = FileDocumentManager.getInstance().getDocument(vfile) ?: run {
            LOG.warn("workspace/willRenameFiles: no Document for $uri")
            return
        }
        // Apply edits bottom-up so character offsets stay stable as
        // we mutate the document.
        val sorted = docEdit.edits.sortedByDescending { lspRangeToOffset(document, it.range.end) }
        for (edit in sorted) {
            applyTextEdit(document, edit)
        }
    }

    private fun applyTextEdit(document: Document, edit: TextEdit) {
        val start = lspRangeToOffset(document, edit.range.start)
        val end = lspRangeToOffset(document, edit.range.end)
        if (start < 0 || end > document.textLength || end < start) {
            LOG.warn("workspace/willRenameFiles: invalid range [$start, $end) for document length ${document.textLength}")
            return
        }
        document.replaceString(start, end, edit.newText)
    }

    /**
     * LSP `Position{line, character}` (0-based, UTF-16 columns) ->
     * document byte offset.  IntelliJ's `Document.getLineStartOffset`
     * is 0-based; `character` is taken as-is (which is wrong in the
     * presence of surrogate pairs, but matches what the LSP server
     * emits since it uses the same UTF-16 column convention).
     */
    private fun lspRangeToOffset(document: Document, position: Position): Int {
        val line = position.line.coerceAtLeast(0)
        if (line >= document.lineCount) return document.textLength
        return document.getLineStartOffset(line) + position.character
    }

    private fun resolveVirtualFile(uri: String): VirtualFile? {
        return runReadAction {
            com.intellij.openapi.vfs.VirtualFileManager.getInstance().findFileByUrl(uri)
        }
    }

    private fun notifyClassUnchanged(project: Project, renames: List<FileRename>) {
        // Show one info notification summarising the skipped rename.
        // Reasons (multi-class file, basename mismatch, missing
        // declaration) are diagnosable from the file content; we
        // don't break them out individually to keep the surface
        // minimal.
        val sample = renames.firstOrNull() ?: return
        val basename = sample.newUri.substringAfterLast('/')
        ApplicationManager.getApplication().invokeLater {
            NotificationGroupManager.getInstance()
                .getNotificationGroup("xphp")
                .createNotification(
                    "xphp: file renamed, class not updated",
                    "Couldn't automatically rename the class in <code>$basename</code> -- file isn't a single PSR-4 declaration.  Rename the class manually with Shift+F6 if needed.",
                    NotificationType.INFORMATION,
                )
                .notify(project)
        }
    }

    private fun VirtualFile.isXphpLike(): Boolean {
        val ext = extension?.lowercase() ?: return false
        return ext == "xphp" || ext == "php"
    }

    private companion object {
        private val LOG = Logger.getInstance(XphpFileRenameListener::class.java)
    }
}
