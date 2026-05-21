<?php

declare(strict_types=1);

namespace XPHP\Lsp\Handler;

use Amp\Promise;
use Amp\Success;
use Phpactor\LanguageServer\Core\Handler\CanRegisterCapabilities;
use Phpactor\LanguageServer\Core\Handler\Handler;
use Phpactor\LanguageServer\Core\Workspace\Workspace as PhpactorWorkspace;
use Phpactor\LanguageServerProtocol\CompletionItem;
use Phpactor\LanguageServerProtocol\CompletionItemKind;
use Phpactor\LanguageServerProtocol\CompletionList;
use Phpactor\LanguageServerProtocol\CompletionOptions;
use Phpactor\LanguageServerProtocol\CompletionParams;
use Phpactor\LanguageServerProtocol\ServerCapabilities;
use XPHP\Lsp\PositionMap;
use XPHP\Transpiler\Monomorphize\XphpSourceParser;

/**
 * `textDocument/completion` handler.
 *
 * Only fires inside a type-arg position: `Box<|`, `Pair<Foo, |`, etc.
 * Outside that context, returns an empty list so the editor falls back to its
 * default (PHP) completion source.
 *
 * Candidates are:
 *   - Every ClassLike FQN in the open workspace (via WorkspaceSymbols).
 *   - The scalar/built-in type set from XphpSourceParser::SCALAR_TYPES.
 *
 * The `prefix` returned by the position detector is used to filter candidates
 * case-insensitively so `Box<plas` suggests `Plastic`. We rely on the editor's
 * fuzzy-matcher for ranking — no sortText overrides.
 *
 * Limitations called out:
 *   - No bound-aware filtering. If `Box<T: \Stringable>`, we still suggest
 *     non-Stringable classes; the diagnostic surface will flag the violation
 *     after the user picks. Bound-aware completion is a follow-up that
 *     requires resolving the enclosing Name's template definition first.
 *   - No use-alias short-form yet. We always insert the full FQN, which is
 *     always correct; a future refinement could substitute the short form
 *     when a matching `use` statement is in scope.
 */
final class XphpCompletionHandler implements Handler, CanRegisterCapabilities
{
    public function __construct(
        private readonly PhpactorWorkspace $workspace,
        private readonly WorkspaceSymbols $symbols,
    ) {
    }

    public function methods(): array
    {
        return [
            'textDocument/completion' => 'complete',
        ];
    }

    // `registerCapabiltiies` is misspelled in phpactor's Handler interface (sic).
    // We match the typo deliberately — overriding requires the same name.
    public function registerCapabiltiies(ServerCapabilities $capabilities): void
    {
        // `<` is the canonical trigger; `,` lets the next-arg case fire without
        // the user typing an extra letter first.
        $capabilities->completionProvider = new CompletionOptions(
            triggerCharacters: ['<', ','],
        );
    }

    /**
     * @return Promise<CompletionList>
     */
    public function complete(CompletionParams $params): Promise
    {
        $emptyList = new CompletionList(isIncomplete: false, items: []);
        if (!$this->workspace->has($params->textDocument->uri)) {
            return new Success($emptyList);
        }
        $item = $this->workspace->get($params->textDocument->uri);
        $offset = (new PositionMap($item->text))->positionToOffset(
            $params->position->line,
            $params->position->character,
        );

        $hit = TypeArgPositionDetector::detect($item->text, $offset);
        if ($hit === null) {
            return new Success($emptyList);
        }

        $candidates = $this->buildCandidates($hit['prefix']);
        return new Success(new CompletionList(isIncomplete: false, items: $candidates));
    }

    /**
     * @return list<CompletionItem>
     */
    private function buildCandidates(string $prefix): array
    {
        $items = [];

        foreach ($this->symbols->allClassFqns() as $fqn) {
            $shortName = self::lastSegment($fqn);
            if (!self::matchesPrefix($shortName, $fqn, $prefix)) {
                continue;
            }
            $items[] = new CompletionItem(
                label: $shortName,
                kind: CompletionItemKind::CLASS_,
                detail: $fqn,
                insertText: $fqn,
            );
        }

        foreach (XphpSourceParser::SCALAR_TYPES as $scalar) {
            if ($prefix !== '' && !self::matchesPrefix($scalar, $scalar, $prefix)) {
                continue;
            }
            $items[] = new CompletionItem(
                label: $scalar,
                kind: CompletionItemKind::KEYWORD,
            );
        }

        return $items;
    }

    private static function matchesPrefix(string $shortName, string $fqn, string $prefix): bool
    {
        if ($prefix === '') {
            return true;
        }
        $needle = ltrim($prefix, '\\');
        if ($needle === '') {
            return true;
        }
        return stripos($shortName, $needle) === 0 || stripos($fqn, $needle) !== false;
    }

    private static function lastSegment(string $fqn): string
    {
        $pos = strrpos($fqn, '\\');
        return $pos === false ? $fqn : substr($fqn, $pos + 1);
    }
}
