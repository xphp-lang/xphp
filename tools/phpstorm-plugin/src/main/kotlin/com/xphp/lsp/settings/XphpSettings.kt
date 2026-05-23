package com.xphp.lsp.settings

import com.intellij.openapi.application.ApplicationManager
import com.intellij.openapi.components.PersistentStateComponent
import com.intellij.openapi.components.Service
import com.intellij.openapi.components.State
import com.intellij.openapi.components.Storage
import com.intellij.util.xmlb.XmlSerializerUtil

/**
 * Persistent application-level settings for the xphp plugin.
 *
 * Two knobs today:
 *
 *   * `lspPath`: absolute path to a custom `xphp-lsp.phar` (or
 *     `xphp-lsp` script).  Empty means "use the bundled PHAR that
 *     [com.xphp.lsp.PharExtractor] extracts on first plugin load".
 *
 *   * `phpPath`: absolute path to the PHP interpreter that should
 *     launch the PHAR.  Empty means "use whatever `php` is on the
 *     IDE's PATH".  Matches the `xphp.phpPath` setting the VS Code
 *     extension already exposes, so multi-PHP-version dev machines
 *     and non-PATH installs work the same way across editors.
 *
 * State persists to `<config>/options/xphp.xml` in PhpStorm's config
 * directory.  Per-project overrides aren't supported -- a developer
 * working across multiple xphp projects on a single PhpStorm install
 * almost certainly wants the same LSP binary + PHP interpreter for
 * all of them.
 */
@Service(Service.Level.APP)
@State(
    name = "xphpSettings",
    storages = [Storage("xphp.xml")],
)
class XphpSettings : PersistentStateComponent<XphpSettings.State> {

    /**
     * State container.  Must be `public` because `PersistentStateComponent`
     * exposes it through the public `getState()` / `loadState()` methods --
     * Kotlin won't let a public function return / accept an `internal`
     * type.
     *
     * The only sanctioned mutator is the Kotlin UI DSL binding in
     * [XphpSettingsConfigurable], which writes raw textfield content
     * directly to `state.lspPath` / `state.phpPath`.  External callers
     * MUST read through the trimmed accessors ([lspPath], [phpPath]) so
     * a stray space in the user's input doesn't propagate to the
     * descriptor's `cmd.exePath`.
     */
    data class State(
        /**
         * Absolute path to a user-supplied `xphp-lsp` server.  Empty
         * means "no override"; [com.xphp.lsp.XphpLspServerDescriptor]
         * falls through to the bundled PHAR.  Stored raw (not trimmed)
         * because the Kotlin UI DSL binding writes verbatim from the
         * textfield -- normalization happens at read time below.
         */
        var lspPath: String = "",

        /**
         * Absolute path to the PHP interpreter used to launch a PHAR
         * LSP.  Empty means "use whatever `php` is on PATH".  Same
         * normalization rule as `lspPath`.
         */
        var phpPath: String = "",
    )

    private var state = State()

    override fun getState(): State = state

    override fun loadState(loaded: State) {
        XmlSerializerUtil.copyBean(loaded, state)
    }

    /**
     * Trimmed view of [State.lspPath].  Null when no override is
     * configured -- callers use null as the signal to fall through to
     * the bundled PHAR.
     */
    val lspPath: String?
        get() = state.lspPath.trim().takeIf { it.isNotEmpty() }

    /**
     * Trimmed view of [State.phpPath].  Null when no override is
     * configured -- callers default to "php" and let the OS resolve it
     * against PATH.
     */
    val phpPath: String?
        get() = state.phpPath.trim().takeIf { it.isNotEmpty() }

    companion object {
        fun getInstance(): XphpSettings =
            ApplicationManager.getApplication().getService(XphpSettings::class.java)
    }
}
