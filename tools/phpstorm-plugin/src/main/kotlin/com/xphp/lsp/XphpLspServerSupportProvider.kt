package com.xphp.lsp

import com.intellij.openapi.project.Project
import com.intellij.openapi.vfs.VirtualFile
import com.intellij.platform.lsp.api.LspServerSupportProvider

/**
 * IntelliJ Platform LSP entry point.
 *
 * The platform calls [fileOpened] on every file open across every registered
 * provider; the convention is "if this file is mine, ensure the server is
 * running."  [com.intellij.platform.lsp.api.LspServerSupportProvider.Companion]
 * exposes `ensureServerStarted` via the `starter` parameter, which de-dupes
 * across calls so opening 20 .xphp files spawns exactly one server.
 *
 * Registered through plugin.xml's `platform.lsp.serverSupportProvider`
 * extension point.  The IntelliJ Platform LSP API went free across all
 * editions in 2025.2 and rounded out its features in 2026.1 -- the plugin's
 * `since-build = 261` baseline is the floor for this entry point.
 */
class XphpLspServerSupportProvider : LspServerSupportProvider {

    override fun fileOpened(
        project: Project,
        file: VirtualFile,
        serverStarter: LspServerSupportProvider.LspServerStarter,
    ) {
        if (file.fileType !is XphpFileType) return
        serverStarter.ensureServerStarted(XphpLspServerDescriptor(project))
    }
}
