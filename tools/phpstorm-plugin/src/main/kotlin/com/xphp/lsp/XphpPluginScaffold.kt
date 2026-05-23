package com.xphp.lsp

/**
 * Marker object for the chunk-2 scaffold.
 *
 * Exists so `./gradlew build` has a Kotlin source to compile and downstream
 * test infrastructure (`testKotlin` / `verifyPlugin`) wires up against a real
 * compile output rather than a phantom main source set.  Replaced by real
 * extension classes in chunk 3 (file type) and chunk 4 (LSP wiring); the
 * package marker itself stays as the canonical home for all plugin sources.
 *
 * **DO NOT** add behaviour here.  Use the dedicated chunk classes:
 *   * `XphpFileType` / `XphpLanguage`  (chunk 3)
 *   * `XphpLspServerProvider` / `XphpLspServerDescriptor`  (chunk 4)
 *   * `PharExtractor`  (chunk 5)
 */
internal object XphpPluginScaffold {
    const val PLUGIN_ID: String = "com.xphp.lsp"
}
