package com.xphp.lsp

import com.google.gson.Gson
import com.google.gson.reflect.TypeToken
import com.intellij.openapi.diagnostic.Logger
import com.intellij.openapi.fileEditor.FileEditorManager
import com.intellij.openapi.fileEditor.OpenFileDescriptor
import com.intellij.openapi.project.Project
import com.intellij.openapi.ui.popup.JBPopupFactory
import com.intellij.openapi.vfs.VirtualFile
import com.intellij.openapi.vfs.VirtualFileManager
import com.intellij.platform.lsp.api.LspServer
import com.intellij.platform.lsp.api.customization.LspCommandsSupport
import com.intellij.ui.ColoredListCellRenderer
import com.intellij.ui.SimpleTextAttributes
import org.eclipse.lsp4j.Command
import org.eclipse.lsp4j.Location
import javax.swing.JList

/**
 * Client-side handler for `editor.action.showReferences` -- the
 * de-facto LSP convention for "open the references panel with
 * pre-baked locations" emitted by code lenses (and code actions).
 *
 * PhpStorm's LSP4IJ-rooted LSP adapter doesn't recognize this
 * command name out of the box and falls back to a server-side
 * `workspace/executeCommand` round-trip.  The server we ship
 * registers a no-op for the command, so before this customizer
 * the click would silently do nothing -- the user's
 * `2026-05-30 11:0*` prod log proved exactly that.
 *
 * Override here intercepts the command on the client side before
 * the round-trip.  Dispatch:
 *
 *   - one location  -> navigate the editor straight to it
 *     (no popup -- matches IntelliJ's built-in "Go to
 *     Implementation" UX for single-target results).
 *   - two or more   -> pop a JBPopupFactory chooser anchored at
 *     the editor caret with one row per usage, rendered as
 *     `[file-icon] filename:line  <source-line preview>`.
 *     Type-to-filter is enabled via setNamerForFiltering.
 *
 * Arguments shape (the de-facto VS Code convention every mainline
 * LSP client also recognizes):
 *   `[uri: string, position: Position, locations: Location[]]`
 *
 * For the full Find Usages tool window the user still has Alt+F7,
 * which goes through the standard `textDocument/references` flow.
 * This popup is a faster shortcut, not a replacement.
 */
class XphpShowReferencesCommandsSupport : LspCommandsSupport() {

    override fun executeCommand(server: LspServer, contextFile: VirtualFile, command: Command) {
        if (command.command == COMMAND_NAME) {
            handleShowReferences(server, command)
            return
        }
        super.executeCommand(server, contextFile, command)
    }

    private fun handleShowReferences(server: LspServer, command: Command) {
        val args = command.arguments
        if (args == null || args.size < 3) {
            LOG.debug("editor.action.showReferences: missing arguments[2] (locations)")
            return
        }
        val locations = parseLocations(args[2])
        if (locations.isNullOrEmpty()) {
            LOG.debug("editor.action.showReferences: zero locations to navigate to")
            return
        }
        val items = locations.toUsageItems()
        if (items.isEmpty()) {
            LOG.warn("editor.action.showReferences: every location had an unresolvable URI")
            return
        }
        if (items.size == 1) {
            items[0].navigate(server.project)
            return
        }
        showChooserPopup(server.project, items)
    }

    private fun showChooserPopup(project: Project, items: List<UsageItem>) {
        val popup = JBPopupFactory.getInstance()
            .createPopupChooserBuilder(items)
            .setTitle("Usages")
            .setItemChosenCallback { it.navigate(project) }
            .setRenderer(UsageItemRenderer())
            // Type-to-filter: matches IntelliJ's standard chooser-popup UX.
            .setNamerForFiltering { "${it.vfile.name}:${it.line + 1} ${it.preview}" }
            .setRequestFocus(true)
            .createPopup()
        val editor = FileEditorManager.getInstance(project).selectedTextEditor
        if (editor != null) {
            popup.showInBestPositionFor(editor)
        } else {
            popup.showCenteredInCurrentWindow(project)
        }
    }

    /**
     * Convert each `Location` to a `UsageItem`, dropping any URI we
     * can't resolve to a `VirtualFile` (e.g. stale lens after the
     * file was deleted).  Preview text is computed eagerly via one
     * VFS read per item -- negligible at codeLens scale.
     */
    private fun List<Location>.toUsageItems(): List<UsageItem> = mapNotNull { loc ->
        val vfile = VirtualFileManager.getInstance().findFileByUrl(loc.uri) ?: return@mapNotNull null
        UsageItem(
            vfile = vfile,
            line = loc.range.start.line,
            character = loc.range.start.character,
            preview = readPreview(vfile, loc.range.start.line),
        )
    }

    /**
     * `Command.arguments` is `List<Object>` after lsp4j's untyped
     * Gson deserialisation -- entries are usually `JsonElement` or
     * `LinkedTreeMap`.  Round-trip via Gson with a typed `TypeToken`
     * to coerce to `List<Location>` without depending on the precise
     * runtime shape.
     */
    private fun parseLocations(raw: Any?): List<Location>? {
        if (raw == null) return null
        val gson = Gson()
        val json = gson.toJsonTree(raw)
        return try {
            val type = object : TypeToken<List<Location>>() {}.type
            gson.fromJson<List<Location>>(json, type)
        } catch (e: Exception) {
            LOG.warn("editor.action.showReferences: failed to parse locations", e)
            null
        }
    }

    /**
     * One row in the chooser popup.  Carries everything the renderer
     * needs plus a `navigate` helper so the item-chosen callback
     * stays a one-liner.
     */
    private data class UsageItem(
        val vfile: VirtualFile,
        val line: Int,
        val character: Int,
        val preview: String,
    ) {
        fun navigate(project: Project) {
            OpenFileDescriptor(project, vfile, line, character).navigate(true)
        }
    }

    private class UsageItemRenderer : ColoredListCellRenderer<UsageItem>() {
        override fun customizeCellRenderer(
            list: JList<out UsageItem>,
            value: UsageItem,
            index: Int,
            selected: Boolean,
            hasFocus: Boolean,
        ) {
            icon = value.vfile.fileType.icon
            // 1-based line for display -- LSP carries 0-based but IDE
            // conventions surface 1-based everywhere users see it.
            append("${value.vfile.name}:${value.line + 1}", SimpleTextAttributes.REGULAR_ATTRIBUTES)
            if (value.preview.isNotEmpty()) {
                append("  " + value.preview, SimpleTextAttributes.GRAYED_ATTRIBUTES)
            }
        }
    }

    private companion object {
        const val COMMAND_NAME = "editor.action.showReferences"
        private val LOG = Logger.getInstance(XphpShowReferencesCommandsSupport::class.java)

        /**
         * Read the trimmed source line at the given 0-based line index
         * from a VirtualFile.  Returns "" if the file is unreadable or
         * the line index is past EOF.  Reads the whole file once
         * because VirtualFile has no random-line API; the files we
         * read here are LSP-tracked source files (kB-range), so the
         * full read is cheap.
         */
        private fun readPreview(vfile: VirtualFile, line: Int): String {
            if (line < 0) return ""
            return try {
                val text = String(vfile.contentsToByteArray(), vfile.charset)
                val lines = text.split('\n')
                if (line >= lines.size) "" else lines[line].trim()
            } catch (e: Exception) {
                LOG.debug("editor.action.showReferences: could not read preview for ${vfile.url}:$line", e)
                ""
            }
        }
    }
}
