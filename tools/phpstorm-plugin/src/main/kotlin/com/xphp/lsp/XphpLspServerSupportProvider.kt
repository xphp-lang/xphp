package com.xphp.lsp

import com.intellij.openapi.project.Project
import com.intellij.openapi.vfs.VirtualFile
import com.intellij.platform.lsp.api.LspServerSupportProvider

/**
 * IntelliJ Platform LSP entry point.
 *
 * The platform calls [fileOpened] on every file open across every registered
 * provider; the convention is "if this file is mine, ensure the server is
 * running."  `serverStarter.ensureServerStarted(...)` de-dupes across calls
 * so opening 20 .xphp files spawns exactly one server.
 *
 * Filter is **by file extension**, not by `FileType`.  An earlier iteration
 * registered a `XphpFileType : LanguageFileType(XphpLanguage)` and filtered
 * with `file.fileType is XphpFileType`, but `LanguageFileType` makes the
 * platform assume the bound `Language` has a `ParserDefinition` registered
 * (it needs one to construct PSI for the editor).  We don't have one --
 * parsing is delegated to the LSP -- so the editor failed to construct and
 * `.xphp` files refused to open.  Dropping the file type fixed file opens;
 * the extension filter here is the docs-recommended pattern
 * (https://plugins.jetbrains.com/docs/intellij/language-server-protocol.html
 * #basic-implementation) and works regardless of how PhpStorm decides to
 * classify `.xphp` files internally (TextMate-handled when our bundle is
 * loaded; plain text otherwise).
 */
class XphpLspServerSupportProvider : LspServerSupportProvider {

    override fun fileOpened(
        project: Project,
        file: VirtualFile,
        serverStarter: LspServerSupportProvider.LspServerStarter,
    ) {
        if (file.extension != "xphp") return
        serverStarter.ensureServerStarted(XphpLspServerDescriptor(project))
    }
}
