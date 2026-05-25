package com.xphp.lsp.textmate

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

/**
 * Tests for [XphpBundleRegistrar.Extractor]'s file-IO contract.
 *
 * Mirrors [com.xphp.lsp.PharExtractorTest]'s shape: we instantiate the
 * production [XphpBundleRegistrar.Extractor] with caller-controlled
 * bundled bytes (via the `streamLoader` constructor parameter) and a
 * `@TempDir` standing in for `PathManager.getSystemDir()`.  Same code paths
 * run, just without IntelliJ's `Application` in scope.
 */
class XphpBundleRegistrarTest {

    private fun newExtractor(bytes: ByteArray?, baseDir: Path) =
        XphpBundleRegistrar.Extractor(
            bundleRoot = baseDir.resolve("xphp"),
            streamLoader = { bytes?.inputStream() as InputStream? },
        )

    @Test
    fun `first run extracts grammar to Syntaxes subdir and writes checksum`(@TempDir tmp: Path) {
        val bytes = """{"scopeName":"source.xphp"}""".toByteArray()

        val bundleRoot = newExtractor(bytes, tmp).extract()

        assertNotNull(bundleRoot)
        assertEquals(tmp.resolve("xphp"), bundleRoot)

        val grammar = bundleRoot!!.resolve("Syntaxes/xphp.tmLanguage.json")
        assertTrue(Files.isRegularFile(grammar))
        assertArrayEquals(bytes, Files.readAllBytes(grammar))

        val sidecar = bundleRoot.resolve("xphp.sha256")
        assertTrue(Files.isRegularFile(sidecar))
        assertEquals(64, Files.readString(sidecar).trim().length) // sha256 hex
    }

    @Test
    fun `first run writes info_plist with bundle name`(@TempDir tmp: Path) {
        val bytes = """{"scopeName":"source.xphp"}""".toByteArray()

        val bundleRoot = newExtractor(bytes, tmp).extract()!!
        val infoPlist = bundleRoot.resolve("info.plist")

        assertTrue(Files.isRegularFile(infoPlist), "info.plist must exist for the platform's bundle reader to recognize the format")
        val contents = Files.readString(infoPlist)
        // Smoke checks; full plist correctness is the platform's concern.
        assertTrue(contents.contains("<?xml"), "info.plist looks like XML")
        assertTrue(contents.contains("<key>name</key>"), "info.plist has the `name` key")
        assertTrue(contents.contains("<string>xphp</string>"), "info.plist names the bundle 'xphp'")
    }

    @Test
    fun `second extract restores info_plist when missing (heals legacy installs)`(@TempDir tmp: Path) {
        val bytes = """{"scopeName":"source.xphp"}""".toByteArray()
        val extractor = newExtractor(bytes, tmp)
        extractor.extract()!!

        // Simulate the broken-legacy state: someone deletes info.plist
        // out from under us (or an earlier plugin version never wrote one).
        val infoPlist = tmp.resolve("xphp/info.plist")
        Files.delete(infoPlist)
        assertFalse(Files.exists(infoPlist))

        // Re-running extract() must restore info.plist even though the
        // grammar's sha256 hasn't changed -- otherwise users on the
        // legacy plugin can't be healed on upgrade.
        extractor.extract()
        assertTrue(Files.isRegularFile(infoPlist))
    }

    @Test
    fun `second run with unchanged bytes is a no-op (mtime preserved)`(@TempDir tmp: Path) {
        val bytes = """{"scopeName":"source.xphp"}""".toByteArray()

        val first = newExtractor(bytes, tmp).extract()!!
        val grammarFirst = first.resolve("Syntaxes/xphp.tmLanguage.json")
        val firstMtime = Files.getLastModifiedTime(grammarFirst)

        // Ensure the filesystem clock has had a chance to tick before the
        // second call so a re-write would actually change mtime on a
        // coarse-grained FS.
        Thread.sleep(50)

        val second = newExtractor(bytes, tmp).extract()!!
        assertEquals(first, second)
        assertEquals(firstMtime, Files.getLastModifiedTime(grammarFirst))
    }

    @Test
    fun `changed bundled bytes re-extracts and updates checksum`(@TempDir tmp: Path) {
        val v1 = """{"scopeName":"source.xphp","version":"v1"}""".toByteArray()
        newExtractor(v1, tmp).extract()

        val v2 = """{"scopeName":"source.xphp","version":"v2"}""".toByteArray()
        val updated = newExtractor(v2, tmp).extract()

        assertNotNull(updated)
        val grammar = updated!!.resolve("Syntaxes/xphp.tmLanguage.json")
        assertArrayEquals(v2, Files.readAllBytes(grammar))

        // Sidecar reflects the new content.
        val sha = Files.readString(updated.resolve("xphp.sha256")).trim()
        assertEquals(sha256Hex(v2), sha)
    }

    @Test
    fun `no bundled grammar returns null and leaves the directory empty`(@TempDir tmp: Path) {
        val extractor = newExtractor(bytes = null, baseDir = tmp)

        val bundleRoot = extractor.extract()

        assertNull(bundleRoot)
        // Nothing should have been created when there's no grammar to ship.
        assertFalse(Files.exists(tmp.resolve("xphp")))
    }

    private fun sha256Hex(bytes: ByteArray): String {
        val digest = java.security.MessageDigest.getInstance("SHA-256").digest(bytes)
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
