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
 * Binary resolution order:
 *   1. [XphpSettings.lspPath] if the user set it explicitly (overrides
 *      everything else -- useful when iterating on the LSP locally).
 *   2. The bundled PHAR extracted by [PharExtractor] from the plugin jar
 *      into PhpStorm's system directory.  This is the zero-config path
 *      a typical user gets on plugin install.
 *   3. If neither is available, fail loudly with a message that points at
 *      the settings pane.
 *
 * Transport: stdio.  Matches `tools/lsp/bin/xphp-lsp` (no `--lint` arg).
 */
class XphpLspServerDescriptor(project: Project) :
    ProjectWideLspServerDescriptor(project, "xphp") {

    override fun isSupportedFile(file: VirtualFile): Boolean =
        file.fileType is XphpFileType

    override fun createCommandLine(): GeneralCommandLine {
        val configured = XphpSettings.getInstance().lspPath
        val binary = when {
            configured != null -> File(configured).also {
                if (!it.isFile) error("Configured xphp LSP binary does not exist: $configured")
            }
            else -> PharExtractor.getInstance().extract()?.toFile()
                ?: error(
                    "xphp LSP binary path is not configured and no bundled " +
                        "PHAR is available inside this plugin build.  Open " +
                        "Preferences -> Tools -> xPHP and set the absolute " +
                        "path to a built `xphp-lsp.phar` or the live " +
                        "`tools/lsp/bin/xphp-lsp` script."
                )
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
