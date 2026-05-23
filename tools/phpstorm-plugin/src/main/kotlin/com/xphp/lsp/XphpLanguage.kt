package com.xphp.lsp

import com.intellij.lang.Language

/**
 * Language singleton for xphp.
 *
 * Backed by the LSP in chunk 4, not by a native IntelliJ parser -- we don't
 * own a PSI implementation here.  The Language object exists so that file type
 * registration, LSP server registration, and any later structure-view /
 * inspection plumbing have a stable identifier to bind against.  PhpStorm's
 * own PHP `Language` (id "PHP") provides the parent dialect ancestor PSI
 * tools may walk into when they don't find xphp-specific behaviour.
 */
object XphpLanguage : Language("xphp", "application/x-xphp") {

    private fun readResolve(): Any = XphpLanguage
}
