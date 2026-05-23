package com.xphp.lsp

import com.intellij.openapi.fileTypes.LanguageFileType
import javax.swing.Icon

/**
 * File type for `.xphp` source files.
 *
 * Registered as a LanguageFileType (binding to [XphpLanguage]) rather than a
 * plain FileType so PhpStorm's language-aware machinery (LSP service routing,
 * fileTypePatterns lookups, the future structure view) can dispatch on the
 * Language singleton.  Defaults to no icon for the MVP; a real one ships with
 * the polish pass.
 */
object XphpFileType : LanguageFileType(XphpLanguage) {

    const val DEFAULT_EXTENSION: String = "xphp"

    override fun getName(): String = "xphp"

    override fun getDescription(): String = "xphp source file"

    override fun getDefaultExtension(): String = DEFAULT_EXTENSION

    override fun getIcon(): Icon? = null
}
