package com.xphp.lsp.textmate

import com.intellij.openapi.application.PathManager
import com.intellij.openapi.diagnostic.Logger
import com.intellij.openapi.project.Project
import com.intellij.openapi.startup.ProjectActivity
import org.jetbrains.plugins.textmate.TextMateService
import org.jetbrains.plugins.textmate.configuration.TextMateUserBundlesSettings
import java.io.IOException
import java.io.InputStream
import java.nio.file.Files
import java.nio.file.Path
import java.nio.file.StandardCopyOption
import java.security.MessageDigest

/**
 * Bootstraps the xphp TextMate grammar into PhpStorm's TextMate plugin
 * on plugin startup so `.xphp` files get syntax highlighting.
 *
 * # Why this exists (and why not `TextMateBundleProvider`)
 *
 * PhpStorm 2026.1.2's TextMate plugin declares an extension point
 * `com.intellij.textmate.bundleProvider` whose interface
 * `org.jetbrains.plugins.textmate.api.TextMateBundleProvider` is the
 * documented API for "plugin ships a TextMate grammar".  But scanning
 * every jar in the bundled IDE distribution shows **zero classes**
 * actually consume that EP.  `TextMateServiceImpl.registerBundles`
 * gathers bundle paths from only two sources:
 *
 *   * `TextMateBuiltinBundlesSettings` -- filesystem-discovered IDE
 *     built-ins (we can't write there from a plugin).
 *   * `TextMateUserBundlesSettings` -- entries the user adds through
 *     `Settings -> Editor -> TextMate Bundles`.
 *
 * The BundleProvider EP exists in the API surface but is presently
 * unwired in 2026.1.2.  An earlier iteration of this plugin registered
 * against that EP and the bundle silently never loaded -- the platform
 * never asked us for it.
 *
 * To actually make highlighting work, we go through the user-bundles
 * registry.  This [ProjectActivity] runs after project open, extracts
 * our bundled grammar to a stable on-disk location, and calls
 * `TextMateUserBundlesSettings.addBundle(path, "xphp")` if it isn't
 * already registered.  Subsequent runs are no-ops via
 * `hasEnabledBundle`.
 *
 * # Visible side effect
 *
 * After first run, an "xphp" entry appears in
 * `Settings -> Editor -> TextMate Bundles`, pointing at
 * `<systemDir>/xphp/textmate-bundle/xphp/`.  The user can disable
 * or remove it from there.  Uninstalling the plugin leaves the entry
 * orphaned (points at a path that still exists); fixing that requires
 * a `Disposable` hook, which is a fine follow-up but not on the
 * critical path here.
 */
class XphpBundleRegistrar : ProjectActivity {

    private val log = Logger.getInstance(XphpBundleRegistrar::class.java)
    private val extractor = Extractor()

    override suspend fun execute(project: Project) {
        val bundleDir = extractor.extract() ?: return
        val path = bundleDir.toAbsolutePath().toString()
        val settings = TextMateUserBundlesSettings.getInstance() ?: run {
            // The settings service is `Service.Level.APP`; getInstance()
            // returns nullable per its Kotlin signature, presumably to
            // accommodate edge cases like running headless or during a
            // partial classloading sequence.  In a real IDE session it
            // should always resolve.  Bail gracefully if it doesn't.
            log.warn("TextMateUserBundlesSettings unavailable; skipping bundle registration")
            return
        }

        if (settings.hasEnabledBundle(path)) {
            log.debug("xphp TextMate bundle already registered at $path")
            return
        }

        settings.addBundle(path, "xphp")
        log.info("Registered xphp TextMate bundle at $path")

        // Reload bundles only when we actually changed the user-bundles
        // list.  An earlier iteration of this code called reload
        // unconditionally to heal legacy installs whose on-disk bundles
        // were missing info.plist -- but reloadEnabledBundles() fires
        // `fileTypesChanged`, which cascades into PhpStorm's LSP framework
        // bouncing every registered LSP server (idea.log:
        // `Stopping LSP server normally` followed by exit 137 a moment
        // after init succeeded).  The bounce killed our LSP server on
        // every IDE start, leaving the user with a "stopped" LSP
        // indicator and no completion / GTD.
        //
        // First-install path (this branch): reload once so the platform
        // picks up the newly-registered bundle.  After that, the entry
        // is persisted; subsequent IDE starts hit the early-return above
        // and don't touch the file-types graph.
        TextMateService.getInstance().reloadEnabledBundles()
    }

    /**
     * Bundle extractor.  Public for tests; production callers go through
     * [execute].  Pattern intentionally mirrors
     * [com.xphp.lsp.PharExtractor.Extractor] (sha-keyed cache, atomic
     * write, configurable target + stream loader) so tests construct it
     * directly without touching IntelliJ's `Application`.
     */
    internal class Extractor(
        private val resource: String = "/textmate/xphp.tmLanguage.json",
        private val grammarFileName: String = "xphp.tmLanguage.json",
        private val bundleRoot: Path = PathManager.getSystemDir().resolve("xphp/textmate-bundle/xphp"),
        private val streamLoader: () -> InputStream? = {
            XphpBundleRegistrar::class.java.getResourceAsStream(resource)
        },
    ) {
        private val log = Logger.getInstance(XphpBundleRegistrar::class.java)
        private val grammarPath: Path = bundleRoot.resolve("Syntaxes").resolve(grammarFileName)
        private val checksumPath: Path = bundleRoot.resolve("xphp.sha256")
        private val infoPlistPath: Path = bundleRoot.resolve("info.plist")

        /**
         * Extract the grammar to disk if needed.  Returns the bundle
         * root (NOT the grammar file -- TextMate wants the directory
         * that contains `Syntaxes/`).  Returns null when the plugin
         * jar carries no grammar resource.
         */
        fun extract(): Path? {
            val stream = streamLoader() ?: run {
                log.info(
                    "No bundled xphp.tmLanguage.json inside the plugin jar; " +
                        "skipping TextMate bundle registration.  .xphp files " +
                        "will fall back to PhpLanguage-inherited highlighting."
                )
                return null
            }

            Files.createDirectories(grammarPath.parent)

            // info.plist tells IntelliJ's bundle reader this is a
            // classic-TextMate-format bundle.  Without it the reader
            // logs "bundle has an unknown format" and refuses to load
            // grammars.  Written unconditionally (and idempotently) so
            // users who installed an earlier plugin version that
            // shipped the bundle without info.plist get the fix on
            // the very next IDE start.
            ensureInfoPlist()

            val bundledBytes = stream.use(InputStream::readAllBytes)
            val bundledSha = sha256Hex(bundledBytes)

            val onDiskSha = readChecksumOrNull()
            if (onDiskSha == bundledSha && Files.isRegularFile(grammarPath)) {
                log.debug("Bundled xphp grammar already extracted to $grammarPath ($bundledSha)")
                return bundleRoot
            }

            // Per-process unique temp -- two PhpStorm instances starting
            // simultaneously won't race on a shared sibling temp file.
            val tmp = Files.createTempFile(grammarPath.parent, grammarFileName, ".tmp")
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

        private fun ensureInfoPlist() {
            if (Files.isRegularFile(infoPlistPath)) return
            try {
                Files.writeString(infoPlistPath, INFO_PLIST)
            } catch (e: IOException) {
                log.warn("Failed to write $infoPlistPath", e)
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

        /**
         * Minimal TextMate bundle metadata.  IntelliJ's bundle reader
         * uses the presence of `info.plist` (or `package.json`, for VS
         * Code-style bundles) at the bundle root to detect the bundle
         * format.  The only field it requires is `name`; we don't ship
         * a UUID because TextMate-spec UUIDs are bundle-discovery keys
         * that the platform doesn't dedupe against (our bundle path
         * is the dedup key).
         */
        private val INFO_PLIST: String = """
            <?xml version="1.0" encoding="UTF-8"?>
            <!DOCTYPE plist PUBLIC "-//Apple//DTD PLIST 1.0//EN" "http://www.apple.com/DTDs/PropertyList-1.0.dtd">
            <plist version="1.0">
            <dict>
                <key>name</key>
                <string>xphp</string>
            </dict>
            </plist>
        """.trimIndent()
    }
}
