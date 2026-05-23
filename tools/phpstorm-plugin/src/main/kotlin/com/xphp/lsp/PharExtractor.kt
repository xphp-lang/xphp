package com.xphp.lsp

import com.intellij.openapi.application.ApplicationManager
import com.intellij.openapi.application.PathManager
import com.intellij.openapi.components.Service
import com.intellij.openapi.diagnostic.Logger
import java.io.IOException
import java.io.InputStream
import java.nio.file.Files
import java.nio.file.Path
import java.nio.file.StandardCopyOption
import java.security.MessageDigest

/**
 * Extracts the bundled `xphp-lsp.phar` from the plugin jar into PhpStorm's
 * system directory the first time it's needed, and refreshes the cache when
 * the bundled bytes change between plugin versions.
 *
 * Why not run the PHAR straight out of the jar URL: PHARs read by `php` need
 * a real filesystem path (the PHAR runtime parses its own offsets relative
 * to the file location and can't open `jar:file:!/bin/...` URIs).  We copy
 * once, keep a sha256 sidecar, and skip the copy on every subsequent run.
 *
 * The path under `getSystemPath()/xphp/xphp-lsp.phar` survives PhpStorm
 * restarts but is wiped by "Invalidate Caches" -- which is the right
 * trade-off: a cache invalidation should re-extract a known-good binary,
 * and the cost is one file write.
 *
 * Structurally the `@Service` is a thin facade over an inner [Extractor]
 * that owns the IO state machine.  The split mirrors
 * [com.xphp.lsp.textmate.XphpTextMateBundleProvider.Extractor] and exists
 * for testability: unit tests construct the [Extractor] directly with
 * caller-controlled `streamLoader` and `targetPath`, so the real production
 * state machine is exercised rather than re-implemented under test.
 */
@Service(Service.Level.APP)
class PharExtractor {

    private val extractor = Extractor()

    /** See [Extractor.extract]. */
    fun extract(): Path? = extractor.extract()

    /** See [Extractor.targetPath]; production callers use this for logging. */
    val targetPath: Path get() = extractor.targetPath

    /**
     * IO state machine.  `internal` so tests in the same module can
     * construct it directly; production callers go through [extract] on
     * the facade.
     */
    internal class Extractor(
        val targetPath: Path = PathManager.getSystemDir().resolve("xphp/xphp-lsp.phar"),
        private val streamLoader: () -> InputStream? = {
            PharExtractor::class.java.getResourceAsStream("/bin/xphp-lsp.phar")
        },
    ) {
        private val log = Logger.getInstance(PharExtractor::class.java)
        private val checksumPath: Path = targetPath.resolveSibling("xphp-lsp.phar.sha256")

        /**
         * Ensure the bundled PHAR is extracted and up to date.
         *
         * Returns the absolute path to the extracted PHAR, or `null` if no
         * bundled bytes were available (e.g. a dev build where the LSP
         * package hasn't been compiled yet -- the user-configurable LSP
         * path is the escape hatch for that case).
         */
        fun extract(): Path? {
            val stream = streamLoader() ?: run {
                log.info(
                    "No bundled xphp-lsp.phar inside the plugin jar.  Falling back " +
                        "to the user-configured LSP path (Tools -> xPHP)."
                )
                return null
            }

            val bundledBytes = stream.use(InputStream::readAllBytes)
            val bundledSha = sha256Hex(bundledBytes)

            val onDiskSha = readChecksumOrNull()
            if (onDiskSha == bundledSha && Files.isRegularFile(targetPath)) {
                log.debug("Bundled xphp-lsp.phar already extracted to $targetPath ($bundledSha)")
                return targetPath
            }

            Files.createDirectories(targetPath.parent)

            // Per-process unique temp file so two PhpStorm instances starting
            // simultaneously can't race on the same sibling "xphp-lsp.phar.tmp"
            // path and leak a partial PHAR if either crashes mid-write.  The
            // atomic move at the end means whichever instance finishes last
            // wins with byte-identical content; uniqueness is purely about
            // protecting the in-progress temp file.
            val tmp = Files.createTempFile(targetPath.parent, "xphp-lsp", ".phar.tmp")
            try {
                Files.write(tmp, bundledBytes)
                Files.move(
                    tmp,
                    targetPath,
                    StandardCopyOption.REPLACE_EXISTING,
                    StandardCopyOption.ATOMIC_MOVE,
                )
                Files.writeString(checksumPath, bundledSha)
                log.info("Extracted bundled xphp-lsp.phar to $targetPath ($bundledSha)")
                return targetPath
            } catch (e: IOException) {
                log.warn("Failed to extract bundled xphp-lsp.phar to $targetPath", e)
                Files.deleteIfExists(tmp)
                return null
            }
        }

        private fun readChecksumOrNull(): String? =
            try {
                if (Files.isRegularFile(checksumPath)) Files.readString(checksumPath).trim()
                else null
            } catch (_: IOException) {
                null
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
    }

    companion object {
        private val HEX = "0123456789abcdef".toCharArray()

        fun getInstance(): PharExtractor =
            ApplicationManager.getApplication().getService(PharExtractor::class.java)
    }
}
