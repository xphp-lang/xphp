<?php

declare(strict_types=1);

namespace XPHP\Lsp\Resolver;

use Phpactor\LanguageServerProtocol\CodeAction;
use Phpactor\LanguageServerProtocol\CodeActionKind;
use Phpactor\LanguageServerProtocol\Diagnostic;
use Phpactor\LanguageServerProtocol\OptionalVersionedTextDocumentIdentifier;
use Phpactor\LanguageServerProtocol\Range;
use Phpactor\LanguageServerProtocol\TextDocumentEdit;
use Phpactor\LanguageServerProtocol\TextEdit;
use Phpactor\LanguageServerProtocol\WorkspaceEdit;
use XPHP\Lsp\Analyzer\DiagnosticCode;

/**
 * Cycle E — codeAction Sprint B.
 *
 * Per-diagnostic quick fixes.  The client passes the active
 * `Diagnostic[]` for the cursor's range via `CodeActionContext`;
 * this provider walks them, dispatches on `code`, and emits a
 * `CodeAction` (kind: `quickfix`) per fixable diagnostic with
 * a WorkspaceEdit that resolves it.
 *
 * V1 dispatch:
 *
 *   - `UndefinedName`: bareword pseudo-constant typo.  Compare the
 *     misspelled identifier against the PHP keywords `null` / `true`
 *     / `false` via `levenshtein`; emit one fix per candidate within
 *     distance 2 (`nul` -> `null`, `flase` -> `false`, etc.) with a
 *     `Diagnostic.isPreferred` flag on the closest match so the
 *     editor offers it on Alt+Enter without a picker.
 *
 * The diagnostic's `range` is the substitution target (the typo'd
 * identifier's span), so the WorkspaceEdit is a single TextEdit that
 * replaces the range with the suggested keyword.
 */
final class DiagnosticCodeActionProvider
{
    /** Candidate keywords the undefined-constant fix offers. */
    private const PSEUDO_CONSTANT_CANDIDATES = ['null', 'true', 'false'];

    /** Max Levenshtein distance for a candidate to be offered. */
    private const TYPO_DISTANCE_LIMIT = 2;

    /**
     * @param list<Diagnostic> $diagnostics
     * @return list<CodeAction>
     */
    public function actionsFor(string $uri, int $version, string $source, array $diagnostics): array
    {
        $actions = [];
        foreach ($diagnostics as $diagnostic) {
            $code = self::diagnosticCode($diagnostic);
            if ($code === DiagnosticCode::UndefinedName->value) {
                $actions = array_merge(
                    $actions,
                    $this->pseudoConstantFixes($uri, $version, $source, $diagnostic),
                );
            }
        }
        return $actions;
    }

    /**
     * Compare the diagnostic's covered text against `null` / `true` /
     * `false`; emit fixes for whichever are within
     * {@see self::TYPO_DISTANCE_LIMIT} edits away.
     *
     * @return list<CodeAction>
     */
    private function pseudoConstantFixes(
        string $uri,
        int $version,
        string $source,
        Diagnostic $diagnostic,
    ): array {
        $typo = $this->textInRange($source, $diagnostic->range);
        if ($typo === null) {
            return [];
        }
        $lowered = strtolower($typo);
        $ranked = [];
        foreach (self::PSEUDO_CONSTANT_CANDIDATES as $keyword) {
            if ($lowered === $keyword) {
                // Diagnostic shouldn't have fired on an exact match; if
                // it did, no useful fix exists.
                continue;
            }
            $distance = levenshtein($lowered, $keyword);
            if ($distance > self::TYPO_DISTANCE_LIMIT) {
                continue;
            }
            $ranked[] = ['distance' => $distance, 'keyword' => $keyword];
        }
        if ($ranked === []) {
            return [];
        }
        usort($ranked, static fn (array $a, array $b): int => $a['distance'] <=> $b['distance']);

        $actions = [];
        $best = $ranked[0]['distance'];
        foreach ($ranked as $rank) {
            $actions[] = new CodeAction(
                title: sprintf('Change to "%s"', $rank['keyword']),
                kind: CodeActionKind::QUICK_FIX,
                diagnostics: [$diagnostic],
                isPreferred: $rank['distance'] === $best,
                edit: $this->buildReplaceEdit($uri, $version, $diagnostic->range, $rank['keyword']),
            );
        }
        return $actions;
    }

    private function buildReplaceEdit(string $uri, int $version, Range $range, string $newText): WorkspaceEdit
    {
        return new WorkspaceEdit(
            null,
            [new TextDocumentEdit(
                new OptionalVersionedTextDocumentIdentifier($uri, $version),
                [new TextEdit($range, $newText)],
            )],
        );
    }

    /**
     * Extract the UTF-8 substring covered by an LSP Range.  Returns null
     * when the range spans multiple lines (no fixable pseudo-constant
     * does), is empty, or steps outside the source bounds.
     */
    private function textInRange(string $source, Range $range): ?string
    {
        if ($range->start->line !== $range->end->line) {
            return null;
        }
        $lineOffsets = [0];
        $sourceLen = strlen($source);
        for ($i = 0; $i < $sourceLen; $i++) {
            if ($source[$i] === "\n") {
                $lineOffsets[] = $i + 1;
            }
        }
        if (!isset($lineOffsets[$range->start->line])) {
            return null;
        }
        $lineStart = $lineOffsets[$range->start->line];
        $startByte = $lineStart + $range->start->character;
        $endByte = $lineStart + $range->end->character;
        if ($startByte < 0 || $endByte <= $startByte || $endByte > $sourceLen) {
            return null;
        }
        return substr($source, $startByte, $endByte - $startByte);
    }

    /**
     * LSP carries diagnostic codes as either string OR int.  Our
     * analyzer always emits the string form (the enum's `->value`),
     * but a defensive cast keeps the helper robust to future shapes.
     */
    private static function diagnosticCode(Diagnostic $diagnostic): string
    {
        return is_string($diagnostic->code) || is_int($diagnostic->code)
            ? (string) $diagnostic->code
            : '';
    }
}
