package com.xphp.lsp

import com.google.gson.Gson
import com.google.gson.reflect.TypeToken
import com.intellij.openapi.diagnostic.Logger
import com.intellij.openapi.fileEditor.OpenFileDescriptor
import com.intellij.openapi.vfs.VirtualFile
import com.intellij.openapi.vfs.VirtualFileManager
import com.intellij.platform.lsp.api.LspServer
import com.intellij.platform.lsp.api.customization.LspCommandsSupport
import org.eclipse.lsp4j.Command
import org.eclipse.lsp4j.Location

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
 * the round-trip and navigates the editor straight to the
 * pre-baked location(s).
 *
 * Arguments shape (de-facto VS Code convention every mainline
 * LSP client also recognizes):
 *   `[uri: string, position: Position, locations: Location[]]`
 *
 * For MVP we navigate to the first location.  Multi-location
 * popup chooser is a follow-up -- when there's only one usage
 * (the common case for fresh codebases), single-shot navigation
 * is the right UX anyway.  For multi-usage, the user can still
 * fall back to Alt+F7 to get the proper Find Usages panel via
 * the standard `textDocument/references` flow.
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
        val first = locations[0]
        val vfile = VirtualFileManager.getInstance().findFileByUrl(first.uri)
        if (vfile == null) {
            LOG.warn("editor.action.showReferences: could not resolve URI ${first.uri}")
            return
        }
        OpenFileDescriptor(
            server.project,
            vfile,
            first.range.start.line,
            first.range.start.character,
        ).navigate(true)
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

    private companion object {
        const val COMMAND_NAME = "editor.action.showReferences"
        private val LOG = Logger.getInstance(XphpShowReferencesCommandsSupport::class.java)
    }
}
