package com.xphp.lsp

import org.junit.jupiter.api.Assertions.assertArrayEquals
import org.junit.jupiter.api.Assertions.assertEquals
import org.junit.jupiter.api.Assertions.assertFalse
import org.junit.jupiter.api.Assertions.assertNotNull
import org.junit.jupiter.api.Assertions.assertNull
import org.junit.jupiter.api.Assertions.assertTrue
import org.junit.jupiter.api.Test
import org.junit.jupiter.api.io.TempDir
import java.io.InputStream
import java.nio.file.Files
import java.nio.file.Path
import java.security.MessageDigest

/**
 * Tests for [PharExtractor.Extractor]'s IO state machine.
 *
 * Mirrors [com.xphp.lsp.textmate.XphpTextMateBundleProviderTest]'s shape:
 * we instantiate the production `Extractor` directly with caller-controlled
 * `streamLoader` and a `@TempDir`-rooted `targetPath`.  The same code paths
 * run that fire when the IDE boots; nothing is re-implemented under test.
 * A regression in `Extractor.extract()` (e.g. someone removes the sidecar
 * write, or breaks the sha256 comparison) is caught here.
 */
class PharExtractorTest {

    private fun newExtractor(bytes: ByteArray?, baseDir: Path) =
        PharExtractor.Extractor(
            targetPath = baseDir.resolve("xphp-lsp.phar"),
            streamLoader = { bytes?.inputStream() as InputStream? },
        )

    @Test
    fun `first run extracts bytes and writes checksum`(@TempDir tmp: Path) {
        val bytes = "hello-xphp".toByteArray()
        val extractor = newExtractor(bytes, tmp)

        val out = extractor.extract()

        assertNotNull(out)
        assertEquals(tmp.resolve("xphp-lsp.phar"), out)
        assertArrayEquals(bytes, Files.readAllBytes(out!!))

        val sidecar = tmp.resolve("xphp-lsp.phar.sha256")
        assertTrue(Files.isRegularFile(sidecar))
        assertEquals(sha256Hex(bytes), Files.readString(sidecar).trim())
    }

    @Test
    fun `second run with unchanged bytes is a no-op (mtime preserved)`(@TempDir tmp: Path) {
        val bytes = "hello-xphp".toByteArray()

        val first = newExtractor(bytes, tmp).extract()!!
        val firstMtime = Files.getLastModifiedTime(first)

        // Ensure the filesystem clock has had a chance to tick before the
        // second call so a re-write would actually change mtime on a
        // coarse-grained FS.
        Thread.sleep(50)

        val second = newExtractor(bytes, tmp).extract()!!
        assertEquals(first, second)
        assertEquals(firstMtime, Files.getLastModifiedTime(second))
    }

    @Test
    fun `changed bundled bytes re-extracts and updates checksum`(@TempDir tmp: Path) {
        val v1 = "hello-xphp-v1".toByteArray()
        newExtractor(v1, tmp).extract()

        val v2 = "hello-xphp-v2-rebuilt".toByteArray()
        val updated = newExtractor(v2, tmp).extract()

        assertNotNull(updated)
        assertArrayEquals(v2, Files.readAllBytes(updated!!))
        assertEquals(
            sha256Hex(v2),
            Files.readString(tmp.resolve("xphp-lsp.phar.sha256")).trim(),
        )
    }

    @Test
    fun `no bundled bytes returns null and leaves the directory empty`(@TempDir tmp: Path) {
        val extractor = newExtractor(bytes = null, baseDir = tmp)

        val out = extractor.extract()

        assertNull(out)
        assertFalse(Files.exists(tmp.resolve("xphp-lsp.phar")))
    }

    private fun sha256Hex(bytes: ByteArray): String {
        val digest = MessageDigest.getInstance("SHA-256").digest(bytes)
        val sb = StringBuilder(digest.size * 2)
        for (b in digest) {
            val v = b.toInt() and 0xFF
            sb.append(HEX[v ushr 4]).append(HEX[v and 0x0F])
        }
        return sb.toString()
    }

    companion object {
        private val HEX = "0123456789abcdef".toCharArray()
    }
}
