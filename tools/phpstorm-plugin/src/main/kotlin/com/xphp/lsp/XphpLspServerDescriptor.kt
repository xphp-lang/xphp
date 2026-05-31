package com.xphp.lsp

import com.intellij.execution.configurations.GeneralCommandLine
import com.intellij.notification.NotificationAction
import com.intellij.notification.NotificationGroupManager
import com.intellij.notification.NotificationType
import com.intellij.openapi.diagnostic.Logger
import com.intellij.openapi.options.ShowSettingsUtil
import com.intellij.openapi.project.Project
import com.intellij.openapi.vfs.VirtualFile
import com.intellij.platform.lsp.api.ProjectWideLspServerDescriptor
import com.intellij.platform.lsp.api.customization.LspCustomization
import com.xphp.lsp.settings.XphpSettings
import com.xphp.lsp.settings.XphpSettingsConfigurable
import java.io.File

/**
 * LSP server descriptor for xphp.
 *
 * A `ProjectWideLspServerDescriptor` rather than a per-document one because
 * the xphp LSP analyzes across files (cross-file go-to-definition, workspace
 * symbol queries) -- a per-document server would re-parse the workspace on
 * every open, defeating the in-memory `Registry` cache the server already
 * maintains.
 *
 * Binary resolution order:
 *   1. [XphpSettings.lspPath] if the user set it explicitly (overrides
 *      everything else -- useful when iterating on the LSP locally).
 *   2. The bundled PHAR extracted by [PharExtractor] from the plugin jar
 *      into PhpStorm's system directory.  This is the zero-config path
 *      a typical user gets on plugin install.
 *   3. If neither is available, fire a balloon notification with an
 *      "Open Settings..." action that takes the user straight to the
 *      Tools -> xPHP pane, and abort the start.  The notification is
 *      the user-facing channel; the thrown exception is just the LSP
 *      framework's signal to mark start as failed.
 *
 * Transport: stdio.  Matches `tools/lsp/bin/xphp-lsp` (no `--lint` arg).
 */
class XphpLspServerDescriptor(project: Project) :
    ProjectWideLspServerDescriptor(project, "xphp") {

    override fun isSupportedFile(file: VirtualFile): Boolean {
        if (file.extension == "xphp") return true
        // PHP stubs extracted by the LSP server are .php files outside
        // the workspace -- e.g. `/tmp/xphp-lsp-extracted-stubs/<sha>/
        // Reflection/ReflectionNamedType.php`.  When the LSP returns a
        // Location pointing at one of them (native-class GTD,
        // typeDefinition, etc.), PhpStorm asks every registered LSP
        // descriptor "is this file yours?"  Without this branch our
        // descriptor says no, the platform finds no claimant, and
        // reports "Cannot find declaration to go to" -- even though
        // the LSP returned the correct stub path.
        //
        // We claim only the well-known extraction cache root, not
        // every .php file -- those still belong to PhpStorm's native
        // PHP support.  The cache root is hard-coded to match
        // PHP's sys_get_temp_dir() default + the prefix used by
        // ReflectorFactory::extractStubsCache().
        return file.path.contains("/xphp-lsp-extracted-stubs/")
    }

    // Opt in to LSP-routed editor actions.  Server-side capability advertisement
    // (`definitionProvider: true`, `hoverProvider: true` in our `initialize`
    // response) tells the platform the server CAN do each thing; the
    // customization here tells the platform to actually ASK.  Both sides are
    // required -- with only server capabilities, PhpStorm never dispatches a
    // `textDocument/definition` on Ctrl+click, even though the server is
    // running and would happily answer.  Confirmed via captured idea.log
    // showing zero `textDocument/definition` traffic before this opt-in.
    //
    // The no-arg `LspCustomization()` constructor instantiates the
    // `Lsp*Support` (enabled) version of every customizer -- go-to-def,
    // hover, completion, semantic tokens, the lot.  This is fine: each
    // customizer also consults the server's advertised `ServerCapabilities`
    // before dispatching, so handlers our LSP doesn't implement (rename,
    // formatting, etc.) won't be routed regardless of the customizer state.
    // The deprecated per-feature boolean overrides (`lspGoToDefinitionSupport`,
    // `lspHoverSupport`, ...) feed an older opt-in path that wraps customizers
    // in `*Disabled` defaults; overriding this single property bypasses that
    // logic and gives the modern, lint-clean opt-in.
    //
    // `LspCustomization` itself is annotated `@ApiStatus.OverrideOnly` --
    // the IntelliJ Plugin Verifier flags `LspCustomization()` direct
    // instantiation in client code (OVERRIDE_ONLY_API_USAGES).  Using an
    // empty anonymous subclass satisfies the contract: we're EXTENDING
    // the class (the documented use case), not constructing it from
    // outside, and we inherit every default the no-arg path provides.
    override val lspCustomization: LspCustomization = object : LspCustomization() {
        // Client-side handler for the `editor.action.showReferences`
        // command that XphpCodeLensHandler emits with pre-baked
        // Location[].  Without this override PhpStorm's default
        // LspCommandsSupport round-trips every command to the server
        // via `workspace/executeCommand`; our server-side no-op
        // returns null, the click silently fails.  The override
        // intercepts the specific command client-side and navigates
        // directly to the first location.  See
        // XphpShowReferencesCommandsSupport for the rationale and
        // multi-location follow-up note.
        override val commandsCustomizer = XphpShowReferencesCommandsSupport()
    }


    // IntelliJ's LSP framework dedupes "is this server already running?"
    // by descriptor equality.  Our `XphpLspServerSupportProvider.fileOpened`
    // calls `ensureServerStarted(XphpLspServerDescriptor(project))` on every
    // open -- a brand-new instance each time.  Without these overrides,
    // every new instance != the previous one, and the framework treats
    // each call as "different server, restart needed."  In practice that
    // shut down the running server immediately after init -- visible in
    // idea.log as `(Running;0) -> ShutdownNormally;0` followed by exit 137
    // when SIGTERM didn't complete in time.
    //
    // Same project + same descriptor class = same logical LSP server.
    // `project` is inherited from `ProjectWideLspServerDescriptor`.
    override fun equals(other: Any?): Boolean =
        other is XphpLspServerDescriptor && other.project === project

    override fun hashCode(): Int = project.hashCode()

    override fun createCommandLine(): GeneralCommandLine {
        val binary = resolveBinary() ?: run {
            notifyMissingBinary()
            // The LSP framework catches whatever createCommandLine throws and
            // logs it.  The detailed message lives in the balloon the user
            // actually sees; the exception just needs to abort the start
            // without shouting in idea.log.
            throw RuntimeException("xphp LSP binary not available (see notification balloon)")
        }

        val cmd = GeneralCommandLine()
        cmd.workDirectory = project.basePath?.let(::File)

        // Distinguish "binary is a PHAR" from "binary is a shell script".  The
        // PHAR needs `php` as the launcher; the script (tools/lsp/bin/xphp-lsp)
        // has its own shebang and runs directly.  We pick by extension rather
        // than file inspection -- the user explicitly typed this path in
        // settings, no need to second-guess.  For PHAR launches, honour
        // `settings.phpPath` if set (parity with the VS Code extension's
        // `xphp.phpPath`); otherwise fall back to bare `php` and let the OS
        // resolve it against PATH.
        if (binary.extension.equals("phar", ignoreCase = true)) {
            cmd.exePath = XphpSettings.getInstance().phpPath ?: "php"
            cmd.addParameter(binary.absolutePath)
        } else {
            cmd.exePath = binary.absolutePath
        }

        return cmd
    }

    /**
     * Returns the LSP binary path or null if neither the explicit setting
     * nor the bundled-PHAR fallback resolves to a real file.  Null is the
     * trigger for [notifyMissingBinary].
     *
     * **Always** runs [PharExtractor.extract] first, regardless of
     * whether `lspPath` is set.  Reason: a user who set `lspPath` to the
     * exact path PharExtractor writes to (`<systemDir>/xphp/xphp-lsp.phar`)
     * was effectively pinning their LSP to whatever bytes happened to be
     * on disk at the time they configured the setting.  Plugin upgrades
     * couldn't refresh the bundled PHAR -- the configured-path branch
     * short-circuited before the extractor ran, so a stale on-disk PHAR
     * stayed in use forever.  PharExtractor's sha-check is cheap on the
     * no-change path (read bundled bytes, sha compare, return), so always
     * running it is the right default; the explicit `lspPath` is then a
     * pure override that points wherever (could be the bundle target,
     * could be an external binary).
     */
    private fun resolveBinary(): File? {
        val bundled = PharExtractor.getInstance().extract()?.toFile()
        val configured = XphpSettings.getInstance().lspPath
        if (configured != null) {
            val asFile = File(configured)
            if (asFile.isFile) return asFile
            LOG.warn(
                "Configured xphp LSP binary does not exist on disk: " +
                    "$configured.  Using the bundled PHAR instead.  Clear " +
                    "the path in Settings -> Tools -> xPHP, or update it to " +
                    "a real binary, to silence this warning."
            )
        }
        return bundled
    }

    private fun notifyMissingBinary() {
        val configured = XphpSettings.getInstance().lspPath
        val (title, content) = if (configured != null) {
            "xphp LSP binary not found" to (
                "The path configured under Settings -> Tools -> xPHP doesn't " +
                    "point at a real file: <code>$configured</code>.  Update the " +
                    "setting or rebuild the plugin with a bundled PHAR."
                )
        } else {
            "xphp LSP is not configured" to (
                "Set the path to <code>xphp-lsp.phar</code> in Settings -> Tools -> " +
                    "xPHP, or rebuild the plugin (`make -C tools/lsp build/phar` " +
                    "before `make -C tools/phpstorm-plugin dist`) so a bundled " +
                    "server ships inside the plugin jar."
                )
        }
        NotificationGroupManager.getInstance()
            .getNotificationGroup("xphp")
            .createNotification(title, content, NotificationType.WARNING)
            .addAction(
                NotificationAction.createSimpleExpiring("Open Settings...") {
                    ShowSettingsUtil.getInstance().showSettingsDialog(
                        project,
                        XphpSettingsConfigurable::class.java,
                    )
                }
            )
            .notify(project)
    }

    private companion object {
        private val LOG = Logger.getInstance(XphpLspServerDescriptor::class.java)
    }
}
