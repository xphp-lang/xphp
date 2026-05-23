package com.xphp.lsp

import com.intellij.execution.configurations.GeneralCommandLine
import com.intellij.notification.NotificationAction
import com.intellij.notification.NotificationGroupManager
import com.intellij.notification.NotificationType
import com.intellij.openapi.options.ShowSettingsUtil
import com.intellij.openapi.project.Project
import com.intellij.openapi.vfs.VirtualFile
import com.intellij.platform.lsp.api.ProjectWideLspServerDescriptor
import com.xphp.lsp.settings.XphpSettings
import com.xphp.lsp.settings.XphpSettingsConfigurable
import java.io.File

/**
 * LSP server descriptor for xphp.
 *
 * A `ProjectWideLspServerDescriptor` rather than a per-document one because
 * the xphp LSP analyzes across files (cross-file go-to-definition, workspace
 * symbol queries) -- a per-document server would re-parse the workspace on
 * every open, defeating the in-memory `Registry` cache the server already
 * maintains.
 *
 * Binary resolution order:
 *   1. [XphpSettings.lspPath] if the user set it explicitly (overrides
 *      everything else -- useful when iterating on the LSP locally).
 *   2. The bundled PHAR extracted by [PharExtractor] from the plugin jar
 *      into PhpStorm's system directory.  This is the zero-config path
 *      a typical user gets on plugin install.
 *   3. If neither is available, fire a balloon notification with an
 *      "Open Settings..." action that takes the user straight to the
 *      Tools -> xPHP pane, and abort the start.  The notification is
 *      the user-facing channel; the thrown exception is just the LSP
 *      framework's signal to mark start as failed.
 *
 * Transport: stdio.  Matches `tools/lsp/bin/xphp-lsp` (no `--lint` arg).
 */
class XphpLspServerDescriptor(project: Project) :
    ProjectWideLspServerDescriptor(project, "xphp") {

    override fun isSupportedFile(file: VirtualFile): Boolean =
        file.extension == "xphp"

    override fun createCommandLine(): GeneralCommandLine {
        val binary = resolveBinary() ?: run {
            notifyMissingBinary()
            // The LSP framework catches whatever createCommandLine throws and
            // logs it.  The detailed message lives in the balloon the user
            // actually sees; the exception just needs to abort the start
            // without shouting in idea.log.
            throw RuntimeException("xphp LSP binary not available (see notification balloon)")
        }

        val cmd = GeneralCommandLine()
        cmd.workDirectory = project.basePath?.let(::File)

        // Distinguish "binary is a PHAR" from "binary is a shell script".  The
        // PHAR needs `php` as the launcher; the script (tools/lsp/bin/xphp-lsp)
        // has its own shebang and runs directly.  We pick by extension rather
        // than file inspection -- the user explicitly typed this path in
        // settings, no need to second-guess.  For PHAR launches, honour
        // `settings.phpPath` if set (parity with the VS Code extension's
        // `xphp.phpPath`); otherwise fall back to bare `php` and let the OS
        // resolve it against PATH.
        if (binary.extension.equals("phar", ignoreCase = true)) {
            cmd.exePath = XphpSettings.getInstance().phpPath ?: "php"
            cmd.addParameter(binary.absolutePath)
        } else {
            cmd.exePath = binary.absolutePath
        }

        return cmd
    }

    /**
     * Returns the LSP binary path or null if neither the explicit setting
     * nor the bundled-PHAR fallback resolves to a real file.  Null is the
     * trigger for [notifyMissingBinary].
     */
    private fun resolveBinary(): File? {
        val configured = XphpSettings.getInstance().lspPath
        if (configured != null) {
            val asFile = File(configured)
            return if (asFile.isFile) asFile else null
        }
        return PharExtractor.getInstance().extract()?.toFile()
    }

    private fun notifyMissingBinary() {
        val configured = XphpSettings.getInstance().lspPath
        val (title, content) = if (configured != null) {
            "xphp LSP binary not found" to (
                "The path configured under Settings -> Tools -> xPHP doesn't " +
                    "point at a real file: <code>$configured</code>.  Update the " +
                    "setting or rebuild the plugin with a bundled PHAR."
                )
        } else {
            "xphp LSP is not configured" to (
                "Set the path to <code>xphp-lsp.phar</code> in Settings -> Tools -> " +
                    "xPHP, or rebuild the plugin (`make -C tools/lsp build/phar` " +
                    "before `make -C tools/phpstorm-plugin dist`) so a bundled " +
                    "server ships inside the plugin jar."
                )
        }
        NotificationGroupManager.getInstance()
            .getNotificationGroup("xphp")
            .createNotification(title, content, NotificationType.WARNING)
            .addAction(
                NotificationAction.createSimpleExpiring("Open Settings...") {
                    ShowSettingsUtil.getInstance().showSettingsDialog(
                        project,
                        XphpSettingsConfigurable::class.java,
                    )
                }
            )
            .notify(project)
    }
}
