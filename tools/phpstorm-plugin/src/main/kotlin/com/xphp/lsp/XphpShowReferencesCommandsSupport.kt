package com.xphp.lsp

import com.google.gson.Gson
import com.google.gson.reflect.TypeToken
import com.intellij.openapi.diagnostic.Logger
import com.intellij.openapi.editor.Editor
import com.intellij.openapi.editor.LogicalPosition
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
import com.intellij.ui.awt.RelativePoint
import org.eclipse.lsp4j.Command
import org.eclipse.lsp4j.Location
import org.eclipse.lsp4j.Position
import org.eclipse.lsp4j.ReferenceContext
import org.eclipse.lsp4j.ReferenceParams
import org.eclipse.lsp4j.TextDocumentIdentifier
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
        if (args == null || args.isEmpty()) {
            LOG.debug("editor.action.showReferences: missing arguments")
            return
        }
        // Pull the lens-side position out of the command arguments so
        // the multi-location popup can anchor THERE, not at the
        // editor caret (which may be off in a method body while the
        // user clicks the class-declaration lens above).  arguments[0]
        // is the URI; arguments[1] is the position the lens
        // dispatches.  Both shapes (3-arg VS Code, 2-arg LSP4IJ)
        // carry this same prefix.
        val anchorUri = if (args.size >= 1) parseString(args[0]) else null
        val anchorPosition = if (args.size >= 2) parsePosition(args[1]) else null
        // Two emission shapes we accept:
        //  - VS Code path (spec-compliant viewport-aware resolve):
        //    `[uri, position, locations]` -- locations baked in by
        //    the server's codeLens/resolve handler before render.
        //  - PhpStorm/LSP4IJ path (no resolve, fires the raw command):
        //    `[uri, position]` -- locations slot absent.  We fetch
        //    them on demand via `textDocument/references` against the
        //    same server connection that just dispatched us.
        val locations: List<Location> = when {
            args.size >= 3 -> parseLocations(args[2]) ?: fetchLocations(server, args)
            else -> fetchLocations(server, args)
        }
        if (locations.isEmpty()) {
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
        showChooserPopup(server.project, items, anchorUri, anchorPosition)
    }

    /**
     * Lazy fetch path: send `textDocument/references` over the live
     * LSP connection.  Triggered when the codeLens click carries
     * `[uri, position]` only (PhpStorm/LSP4IJ -- no
     * codeLens/resolve).  Runs synchronously on the EDT because
     * `LspCommandsSupport.executeCommand` is `@RequiresEdt`;
     * `LspServer.sendRequestSync` uses the LSP server's
     * default-timeout cap so a hung server can't block the UI
     * indefinitely.  Typical latency is the same as Alt+F7 since
     * the server-side path is identical.
     */
    private fun fetchLocations(server: LspServer, args: List<Any?>): List<Location> {
        if (args.size < 2) return emptyList()
        val uri = parseString(args[0]) ?: run {
            LOG.warn(
                "editor.action.showReferences: arguments[0] is not a String uri " +
                    "(was ${args[0]?.javaClass?.simpleName})"
            )
            return emptyList()
        }
        val position = parsePosition(args[1]) ?: run {
            LOG.warn("editor.action.showReferences: arguments[1] is not a Position")
            return emptyList()
        }
        val params = ReferenceParams(
            TextDocumentIdentifier(uri),
            position,
            ReferenceContext(false),  // includeDeclaration: false -- match codeLens count semantics
        )
        return try {
            val raw = server.sendRequestSync<List<Location>>(LspServer.DEFAULT_REQUEST_TIMEOUT_MS) { ls ->
                @Suppress("UNCHECKED_CAST")
                ls.textDocumentService.references(params) as java.util.concurrent.CompletableFuture<List<Location>>
            }
            raw ?: emptyList()
        } catch (e: Exception) {
            LOG.warn("editor.action.showReferences: textDocument/references fetch failed", e)
            emptyList()
        }
    }

    private fun parsePosition(raw: Any?): Position? {
        if (raw == null) return null
        return try {
            Gson().fromJson(Gson().toJsonTree(raw), Position::class.java)
        } catch (e: Exception) {
            null
        }
    }

    /**
     * Extract a String from a `Command.arguments[i]` slot.  lsp4j
     * deserialises argument entries as `JsonElement` (more precisely
     * `JsonPrimitive` for strings/numbers/bools), not raw Kotlin types
     * -- a direct `as? String` cast returns null and the call fails
     * silently.  Round-trip via Gson so any input shape that
     * represents a JSON string normalises to a Kotlin `String`.
     */
    private fun parseString(raw: Any?): String? {
        if (raw == null) return null
        if (raw is String) return raw
        return try {
            val element = Gson().toJsonTree(raw)
            if (element.isJsonPrimitive && element.asJsonPrimitive.isString) {
                element.asString
            } else {
                null
            }
        } catch (e: Exception) {
            null
        }
    }

    /**
     * Show the multi-location chooser anchored at the lens position
     * when we can resolve it -- not at the editor caret.
     *
     * Prior behaviour used `popup.showInBestPositionFor(editor)`,
     * which anchors at the CURRENT caret.  In practice the caret is
     * almost never on the same line as the clicked lens (lenses
     * stack on top of class / method declarations; the caret is
     * usually in a method body) so the popup appeared far from
     * where the user clicked.
     *
     * Resolution order:
     *   1. If we have BOTH the lens URI (`anchorUri`) and an open
     *      editor for that URI, AND a Position to anchor on, convert
     *      `(line, character)` -> editor pixel coordinates and show
     *      the popup at that point, offset by one line height so it
     *      lands just below the lens line rather than overlapping
     *      the identifier itself.
     *   2. Fall back to `showInBestPositionFor` against the selected
     *      editor (legacy behaviour) if anything in (1) is missing.
     *   3. Last resort: centred in the project window.
     */
    private fun showChooserPopup(
        project: Project,
        items: List<UsageItem>,
        anchorUri: String?,
        anchorPosition: Position?,
    ) {
        val popup = JBPopupFactory.getInstance()
            .createPopupChooserBuilder(items)
            .setTitle("Usages")
            .setItemChosenCallback { it.navigate(project) }
            .setRenderer(UsageItemRenderer())
            // Type-to-filter: matches IntelliJ's standard chooser-popup UX.
            .setNamerForFiltering { "${it.vfile.name}:${it.line + 1} ${it.preview}" }
            .setRequestFocus(true)
            .createPopup()
        val anchorEditor = anchorUri?.let { editorForUri(project, it) }
        val anchorPoint = if (anchorEditor != null && anchorPosition != null) {
            computeAnchorPoint(anchorEditor, anchorPosition)
        } else {
            null
        }
        when {
            anchorPoint != null -> popup.show(anchorPoint)
            else -> {
                val fallback = anchorEditor ?: FileEditorManager.getInstance(project).selectedTextEditor
                if (fallback != null) {
                    popup.showInBestPositionFor(fallback)
                } else {
                    popup.showCenteredInCurrentWindow(project)
                }
            }
        }
    }

    /**
     * Look up the open editor showing `uri`, or null if the file
     * isn't open.  Lens clicks always come from a file the user has
     * open (you can't see a lens in a closed file), so this
     * resolves except in corner cases like the editor being closed
     * mid-resolve.
     */
    private fun editorForUri(project: Project, uri: String): Editor? {
        val vfile = VirtualFileManager.getInstance().findFileByUrl(uri) ?: return null
        val editors = FileEditorManager.getInstance(project).getEditors(vfile)
        for (e in editors) {
            val text = (e as? com.intellij.openapi.fileEditor.TextEditor)?.editor
            if (text != null) return text
        }
        return null
    }

    /**
     * LSP `Position` (0-based line + 0-based UTF-16 char column) ->
     * editor pixel-space `RelativePoint`.  Translate down by one
     * line height so the popup lands BELOW the line containing the
     * identifier rather than overlapping it (which would obscure
     * the source the user just clicked next to).  Returns null on
     * any conversion failure (e.g. position past EOF after a fast
     * edit between lens render and click).
     */
    private fun computeAnchorPoint(editor: Editor, position: Position): RelativePoint? {
        return try {
            val logical = LogicalPosition(position.line, position.character)
            val xy = editor.logicalPositionToXY(logical)
            xy.translate(0, editor.lineHeight)
            RelativePoint(editor.contentComponent, xy)
        } catch (e: Exception) {
            LOG.debug("editor.action.showReferences: anchor-point conversion failed", e)
            null
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
