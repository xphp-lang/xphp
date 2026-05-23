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
 * Currently exposes a single knob: the absolute path to a custom
 * `xphp-lsp.phar` (or `xphp-lsp` script) that overrides the bundled
 * server.  Chunk 4 makes this knob the *only* way to point the plugin at
 * a server; chunk 5 adds the bundled-PHAR fallback so unset means "use
 * the one extracted from the plugin jar".
 *
 * State persists to `<config>/options/xphp.xml` in PhpStorm's config
 * directory.  Per-project overrides aren't supported -- a developer
 * working across multiple xphp projects on a single PhpStorm install
 * almost certainly wants the same LSP binary for all of them.
 */
@Service(Service.Level.APP)
@State(
    name = "xphpSettings",
    storages = [Storage("xphp.xml")],
)
class XphpSettings : PersistentStateComponent<XphpSettings.State> {

    /**
     * State container -- a plain data class so JetBrains' XmlSerializer can
     * round-trip it without bespoke serializer registration.
     */
    data class State(
        /**
         * Absolute path to a user-supplied `xphp-lsp` server.  Empty string
         * means "no override"; chunk 5 will interpret that as "use the
         * bundled PHAR".  Until chunk 5 lands, an unset path is a runtime
         * error surfaced through the settings UI.
         */
        var lspPath: String = "",
    )

    private var state = State()

    override fun getState(): State = state

    override fun loadState(loaded: State) {
        XmlSerializerUtil.copyBean(loaded, state)
    }

    /**
     * Convenience accessor mirroring the trimmed runtime view of [State.lspPath].
     * Returns null when the user hasn't configured a path -- callers use
     * `null` to mean "fall through to the bundled binary" once chunk 5
     * lands.
     */
    var lspPath: String?
        get() = state.lspPath.trim().takeIf { it.isNotEmpty() }
        set(value) {
            state.lspPath = value?.trim().orEmpty()
        }

    companion object {
        fun getInstance(): XphpSettings =
            ApplicationManager.getApplication().getService(XphpSettings::class.java)
    }
}
