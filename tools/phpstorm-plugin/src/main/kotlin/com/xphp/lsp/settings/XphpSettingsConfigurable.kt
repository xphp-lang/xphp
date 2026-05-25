package com.xphp.lsp.settings

import com.intellij.openapi.fileChooser.FileChooserDescriptor
import com.intellij.openapi.options.BoundConfigurable
import com.intellij.openapi.ui.DialogPanel
import com.intellij.ui.dsl.builder.AlignX
import com.intellij.ui.dsl.builder.bindText
import com.intellij.ui.dsl.builder.panel

/**
 * Settings UI under Preferences -> Tools -> xPHP.
 *
 * Extends [BoundConfigurable] (not the bare [com.intellij.openapi.options.Configurable]):
 * the base class captures the [DialogPanel] returned by [createPanel] and wires
 * `apply()` / `reset()` / `isModified()` / `disposeUIResources()` to the panel's
 * matching methods, so the Kotlin UI DSL bindings declared inside the panel
 * actually run during the platform's save / cancel / dirty-check lifecycle.
 *
 * The earlier hand-rolled `Configurable` overrode those methods directly and
 * never delegated to the panel, which silently broke persistence: the
 * textfield's typed value never reached the bound property, so [apply]
 * stored a stale empty string back into `xphp.xml` every time the user
 * hit OK.
 *
 * Bind the textfield directly to `XphpSettings.state::lspPath` -- the mutable
 * property reference on the State data class.  No intermediate field, no
 * manual sync.  `PersistentStateComponent` flushes mutations to
 * `<config>/options/xphp.xml` on the platform's normal cadence.
 */
class XphpSettingsConfigurable : BoundConfigurable("xPHP") {

    private val settings = XphpSettings.getInstance()

    override fun createPanel(): DialogPanel = panel {
        // The current non-deprecated `textFieldWithBrowseButton` overload
        // takes a `FileChooserDescriptor` (with the title baked in via
        // `.withTitle(...)`); the older `browseDialogTitle = ...` named
        // argument is deprecated -- and on this build the Kotlin compiler
        // promotes that deprecation to an error.
        //
        // `FileChooserDescriptorFactory.createSingleFileDescriptor()` was
        // also deprecated in 2024.x -- the Plugin Verifier flags it.
        // Instantiating `FileChooserDescriptor` directly with the explicit
        // flag tuple (files=true, folders=false, jars=false, jarsAsFiles=
        // false, jarContents=false, chooseMultiple=false) is the stable
        // replacement that ships in every supported IDE build.
        row("xphp LSP binary:") {
            textFieldWithBrowseButton(
                FileChooserDescriptor(true, false, false, false, false, false)
                    .withTitle("Select xphp LSP binary"),
            )
                .bindText(settings.state::lspPath)
                .align(AlignX.FILL)
                .comment(
                    "Absolute path to <code>xphp-lsp.phar</code> built via " +
                        "<code>make -C tools/lsp build/phar</code>, or to the " +
                        "live <code>tools/lsp/bin/xphp-lsp</code> script.  Leave " +
                        "empty to use the plugin's bundled server (auto-extracted " +
                        "to PhpStorm's system dir on first plugin load)."
                )
        }

        // PHP interpreter override -- mirrors the VS Code extension's
        // `xphp.phpPath` setting so multi-PHP-version dev machines and
        // non-PATH installs work the same across editors.
        row("PHP interpreter:") {
            textFieldWithBrowseButton(
                FileChooserDescriptor(true, false, false, false, false, false)
                    .withTitle("Select PHP interpreter"),
            )
                .bindText(settings.state::phpPath)
                .align(AlignX.FILL)
                .comment(
                    "Absolute path to the <code>php</code> binary used to launch " +
                        "the xphp LSP PHAR.  Leave empty to use whatever <code>php</code> " +
                        "is on PATH.  Only affects PHAR launches; if the LSP " +
                        "binary above is a shell script it runs directly through " +
                        "its own shebang."
                )
        }
    }
}
