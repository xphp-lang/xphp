package com.xphp.lsp

import com.intellij.lang.Language
import com.jetbrains.php.lang.PhpLanguage

/**
 * Language singleton for xphp, declared as a dialect of PHP.
 *
 * The PhpLanguage parent serves two purposes:
 *   1. Future PSI / structure-view / inspection plumbing can fall back to
 *      PHP behaviour for anything xphp-specific code hasn't overridden.
 *   2. Safety-net for syntax highlighting: IntelliJ's
 *      `SyntaxHighlighter.PROVIDER.forLanguage()` walks
 *      `Language.getBaseLanguage()` ancestors when a dialect has no
 *      factory of its own.  If the TextMate registration somehow fails
 *      at runtime (missing grammar, scope lookup miss), PHP highlighting
 *      still paints rather than dropping to plain text.
 *
 * The PRIMARY highlighter path is the explicit
 * `<lang.syntaxHighlighterFactory language="xphp" implementationClass=
 *  "org.jetbrains.plugins.textmate.language.syntax.highlighting.TextMateSyntaxHighlighterFactory"/>`
 * registration in plugin.xml -- TextMate's factory loads the grammar
 * shipped by `com.xphp.lsp.textmate.XphpTextMateBundleProvider`, which
 * `include`s `source.php` for PHP base highlighting AND adds an
 * `xphp-extras` block for generic-clause colouring.  That's the source
 * of truth; PhpLanguage inheritance is just the safety net underneath.
 *
 * Note: language inheritance does NOT pull in PHP's parser.  Parsing is
 * driven by `ParserDefinition` extension-point registrations, which we
 * deliberately don't have for "xphp".  Without a parser the IDE produces
 * no PSI tree for .xphp files -- which is exactly what we want, since
 * PHP's parser would choke on `Box<T>` generic clauses.  Highlighting is
 * a lexer-only concern and works without a parser.
 */
// DO NOT register a `parserDefinition` extension for this language.
// Doing so wires xphp into every IntelliJ extension point that
// dispatches by dialect (PhpInspection, PhpReferenceContributor, ...),
// and they'll start firing on .xphp files even though our generic-
// clause syntax isn't valid PHP.  See the KDoc above for the full
// rationale; structure-view / refactor support is delivered through
// the LSP instead.
object XphpLanguage : Language(PhpLanguage.INSTANCE, "xphp", "application/x-xphp") {

    private fun readResolve(): Any = XphpLanguage
}
