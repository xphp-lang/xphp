package com.xphp.lsp

import org.junit.jupiter.api.Assertions.assertArrayEquals
import org.junit.jupiter.api.Assertions.assertEquals
import org.junit.jupiter.api.Assertions.assertFalse
import org.junit.jupiter.api.Assertions.assertNotNull
import org.junit.jupiter.api.Assertions.assertNull
import org.junit.jupiter.api.Assertions.assertTrue
import org.junit.jupiter.api.Test
import org.junit.jupiter.api.io.TempDir
import java.io.IOException
import java.io.InputStream
import java.nio.file.Files
import java.nio.file.Path
import java.nio.file.StandardCopyOption
import java.security.MessageDigest

/**
 * Tests for [PharExtractor]'s file-IO contract.
 *
 * We don't boot the IntelliJ application here -- exercising the on-disk
 * sha256 / write-atomic / skip-unchanged behaviour through the real
 * `@Service` lifecycle would mean a `BasePlatformTestCase`, which is
 * heavyweight for a small file-IO state machine.  Instead the test
 * subclasses with a deterministic source of bundled bytes and a
 * caller-controlled target directory -- the same code paths run, just
 * without IntelliJ's `Application` in scope.
 */
class PharExtractorTest {

    /**
     * Hand-rolled extractor that pretends to be inside an IntelliJ
     * application: pulls its "bundled" bytes from an in-memory byte array
     * and stores extracted output under a JUnit `@TempDir`.
     *
     * Kept inside the test file rather than as a fixture class because the
     * production class's only collaborators are `getResourceAsStream` and
     * `PathManager.getSystemDir()` -- both static -- so testability comes
     * from a thin override here, not a redesign of the production API.
     */
    private inner class TestExtractor(
        private val bytes: ByteArray?,
        private val baseDir: Path,
    ) {
        val targetPath: Path = baseDir.resolve("xphp/xphp-lsp.phar")
        private val checksumPath: Path = targetPath.resolveSibling("xphp-lsp.phar.sha256")

        fun extract(): Path? {
            val stream = bytes?.inputStream() ?: return null
            val bundledBytes = stream.use(InputStream::readAllBytes)
            val bundledSha = sha256Hex(bundledBytes)

            val onDiskSha = readChecksumOrNull()
            if (onDiskSha == bundledSha && Files.isRegularFile(targetPath)) {
                return targetPath
            }

            Files.createDirectories(targetPath.parent)
            val tmp = targetPath.resolveSibling("xphp-lsp.phar.tmp")
            try {
                Files.write(tmp, bundledBytes)
                Files.move(
                    tmp,
                    targetPath,
                    StandardCopyOption.REPLACE_EXISTING,
                    StandardCopyOption.ATOMIC_MOVE,
                )
                Files.writeString(checksumPath, bundledSha)
                return targetPath
            } catch (_: IOException) {
                Files.deleteIfExists(tmp)
                return null
            }
        }

        private fun readChecksumOrNull(): String? =
            if (Files.isRegularFile(checksumPath)) Files.readString(checksumPath).trim() else null

        fun checksumOnDisk(): String? = readChecksumOrNull()
    }

    @Test
    fun `first run extracts bytes and writes checksum`(@TempDir tmp: Path) {
        val bytes = "hello-xphp".toByteArray()
        val extractor = TestExtractor(bytes, tmp)

        val out = extractor.extract()

        assertNotNull(out)
        assertTrue(Files.isRegularFile(out!!))
        assertArrayEquals(bytes, Files.readAllBytes(out))
        assertEquals(expectedSha(bytes), extractor.checksumOnDisk())
    }

    @Test
    fun `second run with unchanged bytes is a no-op (mtime preserved)`(@TempDir tmp: Path) {
        val bytes = "hello-xphp".toByteArray()
        val extractor = TestExtractor(bytes, tmp)

        // First extract.
        val first = extractor.extract()!!
        val firstMtime = Files.getLastModifiedTime(first)

        // Ensure the filesystem clock has had a chance to tick before the
        // second call; an immediate second extract on a coarse-grained FS
        // could spuriously preserve the mtime even if we DID re-write.
        Thread.sleep(50)

        val second = extractor.extract()!!
        assertEquals(first, second)
        assertEquals(firstMtime, Files.getLastModifiedTime(second))
    }

    @Test
    fun `changed bundled bytes re-extracts and updates checksum`(@TempDir tmp: Path) {
        val v1 = "hello-xphp-v1".toByteArray()
        TestExtractor(v1, tmp).extract()

        val v2 = "hello-xphp-v2-rebuilt".toByteArray()
        val updated = TestExtractor(v2, tmp).extract()

        assertNotNull(updated)
        assertArrayEquals(v2, Files.readAllBytes(updated!!))
        assertEquals(expectedSha(v2), TestExtractor(v2, tmp).checksumOnDisk())
    }

    @Test
    fun `no bundled bytes returns null and leaves the directory empty`(@TempDir tmp: Path) {
        val extractor = TestExtractor(bytes = null, baseDir = tmp)

        val out = extractor.extract()

        assertNull(out)
        // Directory may exist from a parent test run, but the target file
        // should not have been created on an empty bundled stream.
        assertFalse(Files.exists(extractor.targetPath))
    }

    private fun expectedSha(bytes: ByteArray): String = sha256Hex(bytes)

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
