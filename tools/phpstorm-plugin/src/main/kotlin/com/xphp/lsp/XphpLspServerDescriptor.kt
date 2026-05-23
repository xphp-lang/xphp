package com.xphp.lsp

import com.intellij.execution.configurations.GeneralCommandLine
import com.intellij.openapi.project.Project
import com.intellij.openapi.vfs.VirtualFile
import com.intellij.platform.lsp.api.ProjectWideLspServerDescriptor
import com.xphp.lsp.settings.XphpSettings
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
 * Binary resolution:
 *   * Chunk 4 (this commit): use [XphpSettings.lspPath].  If unset, the
 *     LSP can't start and the user sees an error pointing at the settings
 *     pane.  This is deliberate -- chunk 5 adds the bundled-PHAR fallback,
 *     and we want chunk 4 to surface plumbing problems crisply rather than
 *     silently falling through to an unrelated default.
 *
 * Transport: stdio.  Matches `tools/lsp/bin/xphp-lsp` (no `--lint` arg).
 */
class XphpLspServerDescriptor(project: Project) :
    ProjectWideLspServerDescriptor(project, "xphp") {

    override fun isSupportedFile(file: VirtualFile): Boolean =
        file.fileType is XphpFileType

    override fun createCommandLine(): GeneralCommandLine {
        val binaryPath = XphpSettings.getInstance().lspPath
            ?: error(
                "xphp LSP binary path is not configured.  Open " +
                    "Preferences -> Tools -> xPHP and set the absolute path to " +
                    "your xphp-lsp.phar (or the live `tools/lsp/bin/xphp-lsp` " +
                    "script).  Chunk 5 will add a bundled-PHAR fallback so this " +
                    "becomes optional."
            )

        val binary = File(binaryPath)
        if (!binary.isFile) {
            error("Configured xphp LSP binary does not exist: $binaryPath")
        }

        val cmd = GeneralCommandLine()
        cmd.workDirectory = project.basePath?.let(::File)

        // Distinguish "binary is a PHAR" from "binary is a shell script".  The
        // PHAR needs `php` as the launcher; the script (tools/lsp/bin/xphp-lsp)
        // has its own shebang and runs directly.  We pick by extension rather
        // than file inspection -- the user explicitly typed this path in
        // settings, no need to second-guess.
        if (binary.extension.equals("phar", ignoreCase = true)) {
            cmd.exePath = "php"
            cmd.addParameter(binary.absolutePath)
        } else {
            cmd.exePath = binary.absolutePath
        }

        return cmd
    }
}
