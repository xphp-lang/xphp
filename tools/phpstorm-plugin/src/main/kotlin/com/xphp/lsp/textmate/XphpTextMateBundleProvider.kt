package com.xphp.lsp.textmate

import com.intellij.openapi.application.PathManager
import com.intellij.openapi.diagnostic.Logger
import org.jetbrains.plugins.textmate.api.TextMateBundleProvider
import java.io.IOException
import java.io.InputStream
import java.nio.file.Files
import java.nio.file.Path
import java.nio.file.StandardCopyOption
import java.security.MessageDigest

/**
 * Registers the bundled `xphp.tmLanguage.json` grammar with the IntelliJ
 * TextMate plugin so `.xphp` files get syntax highlighting.
 *
 * Plumbing constraint: the TextMate plugin's [TextMateBundleProvider] returns
 * `PluginBundle(name, path: java.nio.file.Path)` and its implementation
 * (`TextMateNioResourceReader`) calls `Files.list()` / `Path.readBytes()` on
 * that path -- it has no classpath URL support.  We therefore extract the
 * grammar from `/textmate/xphp.tmLanguage.json` in the plugin jar onto the
 * real filesystem at startup, mirroring the existing
 * [com.xphp.lsp.PharExtractor] pattern (sha256-keyed cache, atomic write,
 * skip when unchanged).
 *
 * The TextMate bundle layout the platform expects:
 * ```
 * <bundle-root>/
 *   Syntaxes/
 *     xphp.tmLanguage.json
 * ```
 * `info.plist` is optional and we don't ship one -- the grammar's
 * `scopeName: "source.xphp"` is enough for the platform to wire scope
 * extraction.
 */
class XphpTextMateBundleProvider : TextMateBundleProvider {

    override fun getBundles(): List<TextMateBundleProvider.PluginBundle> {
        val bundleDir = extractor.extract() ?: return emptyList()
        return listOf(TextMateBundleProvider.PluginBundle("xphp", bundleDir))
    }

    /**
     * Public for tests; production callers go through [getBundles].
     */
    internal class Extractor(
        private val resource: String = "/textmate/xphp.tmLanguage.json",
        private val grammarFileName: String = "xphp.tmLanguage.json",
        private val bundleRoot: Path = PathManager.getSystemDir().resolve("xphp/textmate-bundle/xphp"),
        private val streamLoader: () -> InputStream? = {
            XphpTextMateBundleProvider::class.java.getResourceAsStream(resource)
        },
    ) {
        private val log = Logger.getInstance(XphpTextMateBundleProvider::class.java)
        private val grammarPath: Path = bundleRoot.resolve("Syntaxes").resolve(grammarFileName)
        private val checksumPath: Path = bundleRoot.resolve("xphp.sha256")

        /**
         * Extract the grammar to disk if needed and return the bundle root
         * (NOT the grammar file -- TextMate wants the directory that
         * contains `Syntaxes/`).
         *
         * Returns null when the plugin jar carries no grammar resource --
         * e.g. a dev build where `make -C tools/lsp build-extension` skipped
         * the copy.  Caller surfaces this as "no bundle to register" rather
         * than crashing the platform init.
         */
        fun extract(): Path? {
            val stream = streamLoader() ?: run {
                log.info(
                    "No bundled xphp.tmLanguage.json inside the plugin jar; " +
                        "skipping TextMate bundle registration.  .xphp files " +
                        "will open without syntax highlighting until the " +
                        "grammar is shipped (see processResources in " +
                        "tools/phpstorm-plugin/build.gradle.kts)."
                )
                return null
            }

            val bundledBytes = stream.use(InputStream::readAllBytes)
            val bundledSha = sha256Hex(bundledBytes)

            val onDiskSha = readChecksumOrNull()
            if (onDiskSha == bundledSha && Files.isRegularFile(grammarPath)) {
                log.debug("Bundled xphp grammar already extracted to $grammarPath ($bundledSha)")
                return bundleRoot
            }

            Files.createDirectories(grammarPath.parent)

            val tmp = grammarPath.resolveSibling("$grammarFileName.tmp")
            try {
                Files.write(tmp, bundledBytes)
                Files.move(
                    tmp,
                    grammarPath,
                    StandardCopyOption.REPLACE_EXISTING,
                    StandardCopyOption.ATOMIC_MOVE,
                )
                Files.writeString(checksumPath, bundledSha)
                log.info("Extracted xphp.tmLanguage.json to $grammarPath ($bundledSha)")
                return bundleRoot
            } catch (e: IOException) {
                log.warn("Failed to extract xphp.tmLanguage.json to $grammarPath", e)
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

    private val extractor = Extractor()

    companion object {
        private val HEX = "0123456789abcdef".toCharArray()
    }
}
