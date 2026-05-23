package com.xphp.lsp

import org.junit.jupiter.api.Assertions.assertEquals
import org.junit.jupiter.api.Assertions.assertSame
import org.junit.jupiter.api.Test

/**
 * Smoke tests for the chunk-3 file-type plumbing.
 *
 * We deliberately stay below `BasePlatformTestCase` here: those heavyweight
 * tests boot an in-process IntelliJ application + project, which is overkill
 * for the four pure-data invariants we want to lock in (singleton identity,
 * language id, extension, language back-reference).  Reaching into the
 * platform fixture also means dragging the IDE module classpath into the
 * test source set, which the plugin verifier complains about.
 *
 * The integration check that the extension actually associates with our
 * file type runs through `verifyPluginStructure` at build time and through
 * the manual sandbox run in `make run-ide`.
 */
class XphpFileTypeTest {

    @Test
    fun `language id matches the string downstream config keys against`() {
        assertEquals("xphp", XphpLanguage.id)
    }

    @Test
    fun `language singleton is reachable via readResolve`() {
        // De-serialisation paths inside the IntelliJ Platform call
        // `readResolve` to collapse multiple instances back to the singleton.
        // If somebody accidentally turned this into a `class`, the test below
        // would still pass on identity but `readResolve` would return a fresh
        // instance.  Calling it directly guards that.
        assertSame(XphpLanguage, XphpLanguage.javaClass.getDeclaredMethod("readResolve").also {
            it.isAccessible = true
        }.invoke(XphpLanguage))
    }

    @Test
    fun `file type binds to xphp extension`() {
        assertEquals("xphp", XphpFileType.defaultExtension)
        assertEquals("xphp", XphpFileType.name)
        assertEquals("xphp source file", XphpFileType.description)
    }

    @Test
    fun `file type points back at the language singleton`() {
        assertSame(XphpLanguage, XphpFileType.language)
    }
}
