package com.xphp.lsp.settings

import com.intellij.openapi.fileChooser.FileChooserDescriptorFactory
import com.intellij.openapi.options.Configurable
import com.intellij.openapi.ui.TextFieldWithBrowseButton
import com.intellij.ui.dsl.builder.bindText
import com.intellij.ui.dsl.builder.panel
import javax.swing.JComponent

/**
 * Settings UI entry under Preferences -> Tools -> xPHP.
 *
 * One field today: an absolute path to the xphp LSP server binary.  Empty
 * means "use the default" -- which in chunk 4 still throws a startup error
 * (the bundled-PHAR fallback ships in chunk 5).  Wired through plugin.xml's
 * `applicationConfigurable` extension point so it lives at the IDE level,
 * not per-project.
 */
class XphpSettingsConfigurable : Configurable {

    private val settings = XphpSettings.getInstance()
    private var lspPathField: TextFieldWithBrowseButton? = null
    private var workingPath: String = settings.state.lspPath

    override fun getDisplayName(): String = "xPHP"

    override fun getHelpTopic(): String? = null

    override fun createComponent(): JComponent = panel {
        row("xphp LSP binary:") {
            cell(
                TextFieldWithBrowseButton().apply {
                    addBrowseFolderListener(
                        // Title + description shown in the JetBrains file chooser.
                        "Select xphp LSP binary",
                        "Absolute path to xphp-lsp.phar (or the xphp-lsp shell script).",
                        null,
                        FileChooserDescriptorFactory.createSingleFileDescriptor(),
                    )
                }
            )
                .bindText(::workingPath)
                .comment(
                    "Absolute path to <code>xphp-lsp.phar</code> built via " +
                        "<code>make -C tools/lsp build/phar</code>, or to the live " +
                        "<code>tools/lsp/bin/xphp-lsp</code> script.  Leave empty " +
                        "to use the plugin's bundled server (chunk 5 onwards)."
                )
                .also { lspPathField = it.component as TextFieldWithBrowseButton }
        }
    }

    override fun isModified(): Boolean = workingPath != settings.state.lspPath

    override fun apply() {
        settings.lspPath = workingPath
    }

    override fun reset() {
        workingPath = settings.state.lspPath
        // Re-bind the field so the UI reflects the rolled-back value.
        lspPathField?.text = workingPath
    }
}
