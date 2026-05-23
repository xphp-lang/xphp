package com.xphp.lsp

import com.intellij.lang.Language

/**
 * Language singleton for xphp -- top-level [Language], NOT a dialect of PHP.
 *
 * An earlier iteration made this `Language(PhpLanguage.INSTANCE, ...)`
 * because the PHP-as-parent trick gives us free PHP syntax highlighting
 * through `SyntaxHighlighter.PROVIDER.forLanguage()`'s
 * `getBaseLanguage()` walk -- nice and short.
 *
 * That broke file opens in production.  `LanguageExtension.forLanguage()`
 * uses the same parent-walk to resolve EVERY language-keyed extension
 * point, including `LanguageParserDefinitions`.  With PhpLanguage as our
 * base, IntelliJ found PHP's `ParserDefinition` for xphp and tried to
 * parse `.xphp` content as PHP at editor-construction time.  PHP's
 * parser threw on the `Box<T>` generic-clause syntax, the editor
 * couldn't construct, and the file failed to open at all (the user-
 * visible symptom was "Cannot focus editor ... preferredFocusedComponent
 * is null" in idea.log immediately followed by a `fileClosed` event).
 * The walk also activated the PHP plugin's
 * `BeforeFileOpenLoggerListener` and friends -- inspection +
 * reference-contributor surfaces that have no business firing on .xphp
 * files.
 *
 * Without a parent, none of that machinery dispatches for xphp.  The
 * editor opens the file as a plain Document with no PSI tree, which is
 * exactly what we want: LSP delivers diagnostics / hover / completion;
 * the TextMate `<lang.syntaxHighlighterFactory>` registration in
 * plugin.xml delivers colouring (the grammar `include`s `source.php`
 * for PHP base + adds `xphp-extras` for generic clauses).
 *
 * # DO NOT register a `parserDefinition` for this language
 *
 * Same trap, different surface.  Registering one wires xphp into every
 * IntelliJ extension point that resolves by dialect (PhpInspection,
 * PhpReferenceContributor, the parser itself).  Structure-view / refactor
 * support is delivered through the LSP, not through PSI.
 */
object XphpLanguage : Language("xphp", "application/x-xphp") {

    private fun readResolve(): Any = XphpLanguage
}
