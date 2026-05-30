<?php

declare(strict_types=1);

namespace XPHP\Lsp\Handler;

use Amp\CancellationToken;
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
use Phpactor\WorseReflection\Core\ClassName;
use Phpactor\WorseReflection\Reflector;
use Throwable;
use XPHP\Lsp\PositionMap;
use XPHP\Lsp\Reflection\FqnIndex;
use XPHP\Lsp\Resolver\ClassNameImportContext;
use XPHP\Lsp\Resolver\PhpCompletionResolver;
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
 *   - Class-name insertText is scope-aware: the file's namespace +
 *     use map decide whether to emit the bare short name, the aliased
 *     short name, or a leading-backslash FQ. Never emits the
 *     qualified-but-not-FQ form, which would namespace-prepend and
 *     resolve to a wrong (or non-existent) class.
 */
final class XphpCompletionHandler implements Handler, CanRegisterCapabilities
{
    public function __construct(
        private readonly PhpactorWorkspace $workspace,
        private readonly WorkspaceSymbols $symbols,
        private readonly ?PhpCompletionResolver $phpResolver = null,
        // Phase 3 bound-aware completion: optional so tests that don't
        // care about bound filtering can keep their old constructor
        // calls.  When unset, candidates aren't bound-filtered (matches
        // pre-Phase-3 behaviour).
        private readonly ?FqnIndex $fqnIndex = null,
        private readonly ?Reflector $reflector = null,
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
        // Trigger characters cover both completion paths:
        //   - `<`, `,`  -- type-arg position (`Box<|`, `Pair<Foo,|`)
        //   - `>`       -- the second char of `->` (member access)
        //   - `:`       -- the second char of `::` (static access)
        // Note that LSP fires once per trigger char insertion, so typing
        // `->` results in two completion requests: one after `-` (no
        // context detected, empty list) and one after `>` (member-access
        // context detected).  Including `-` would just produce noise.
        $capabilities->completionProvider = new CompletionOptions(
            triggerCharacters: ['<', ',', '>', ':'],
            // `resolveProvider: true` opts the server into the lazy
            // `completionItem/resolve` round-trip: items emitted here
            // can carry a `data` payload that XphpCompletionResolveHandler
            // uses to look up the documentation on-demand.  Cheap
            // per-item up-front (no docblock fetch), one extra request
            // when the user actually navigates to an item.
            resolveProvider: true,
        );
    }

    /**
     * @return Promise<CompletionList>
     */
    public function complete(CompletionParams $params, ?CancellationToken $cancel = null): Promise
    {
        $emptyList = new CompletionList(isIncomplete: false, items: []);
        if ($cancel !== null && $cancel->isRequested()) {
            return new Success($emptyList);
        }
        if (!$this->workspace->has($params->textDocument->uri)) {
            return new Success($emptyList);
        }
        $item = $this->workspace->get($params->textDocument->uri);
        $offset = (new PositionMap($item->text))->positionToOffset(
            $params->position->line,
            $params->position->character,
        );

        $hit = TypeArgPositionDetector::detect($item->text, $offset);
        if ($hit !== null) {
            $bound = $this->boundFor($hit['containerName'], $hit['slot']);
            $importContext = ClassNameImportContext::extractFromSource($item->text);
            $candidates = $this->buildCandidates($hit['prefix'], $bound, $importContext);
            return new Success(new CompletionList(isIncomplete: false, items: $candidates));
        }

        // Fall through to PHP-semantic completion (member / static access).
        // Returns an empty list when the cursor isn't in a recognised
        // context, which matches the old "empty list, no fallback" behaviour
        // for non-type-arg cursors.
        if ($this->phpResolver !== null) {
            $phpItems = $this->phpResolver->complete(
                $params->textDocument->uri,
                $params->position->line,
                $params->position->character,
            );
            return new Success(new CompletionList(isIncomplete: false, items: $phpItems));
        }

        return new Success($emptyList);
    }

    /**
     * @return list<CompletionItem>
     */
    private function buildCandidates(string $prefix, ?string $bound, ClassNameImportContext $importContext): array
    {
        $items = [];

        foreach ($this->candidateClassFqns() as $fqn) {
            $shortName = self::lastSegment($fqn);
            if (!self::matchesPrefix($shortName, $fqn, $prefix)) {
                continue;
            }
            // Phase 3: bound-aware filtering.  When the type-arg slot
            // declares an upper bound (`Box<T: Stringable>`), suppress
            // candidates that aren't subtypes of it.  If reflection
            // fails for a candidate (closed-source / parse error), keep
            // it -- under-filter beats hiding a viable choice.
            if ($bound !== null && !$this->satisfiesBound($fqn, $bound)) {
                continue;
            }
            $items[] = new CompletionItem(
                label: $shortName,
                kind: CompletionItemKind::CLASS_,
                detail: $fqn,
                // Scope-aware insertText: bare short name when the FQN
                // is already imported or same-namespace, leading-backslash
                // FQ otherwise.  Prevents the qualified-but-not-FQ form
                // (e.g. inserting `App\Models\Tag` inside `namespace App\Demos`)
                // from namespace-prepending to a non-existent class.
                insertText: $importContext->chooseInsertText($fqn),
                // `completionItem/resolve` payload: when the user
                // navigates to this item, the client sends the
                // item back and XphpCompletionResolveHandler reads
                // `data.fqn` to fetch the docblock from
                // worse-reflection.  Cheap up-front, lazy on
                // demand.
                data: ['kind' => 'class', 'fqn' => $fqn],
            );
        }

        // Scalars only surface when the slot has no upper bound -- a
        // scalar can never satisfy a class/interface bound.
        if ($bound === null) {
            foreach (XphpSourceParser::SCALAR_TYPES as $scalar) {
                if ($prefix !== '' && !self::matchesPrefix($scalar, $scalar, $prefix)) {
                    continue;
                }
                $items[] = new CompletionItem(
                    label: $scalar,
                    kind: CompletionItemKind::KEYWORD,
                );
            }
        }

        return $items;
    }

    /**
     * Resolve `$containerName` -- the generic class identifier preceding
     * the `<` -- to an FQN, then look up its bound for slot `$slot`.
     * Returns null when:
     *  - the index isn't wired (legacy constructor),
     *  - the container can't be resolved to a known generic class,
     *  - the slot has no declared bound (unbounded type-param).
     */
    private function boundFor(string $containerName, int $slot): ?string
    {
        if ($this->fqnIndex === null) {
            return null;
        }
        $candidates = $this->resolveContainerFqns($containerName);
        foreach ($candidates as $fqn) {
            $bounds = $this->fqnIndex->boundsForGenericClass($fqn);
            if ($bounds === null) {
                continue;
            }
            return $bounds[$slot] ?? null;
        }
        return null;
    }

    /**
     * Best-effort container Name -> FQN resolution.  The cursor sees the
     * Name as it appears in source (`Box`, `App\Box`, or `\App\Box`); we
     * don't have the use-import map here, so:
     *   - qualified -> strip leading `\`, accept verbatim;
     *   - unqualified -> match any indexed FQN whose short name equals
     *     the identifier (Phase 3 polish "short-name tie-break" can pick
     *     the best one later).
     *
     * Uses `FqnIndex::allDeclarations()` (open docs + filesystem) so we
     * find the container even when its declaration file is closed in
     * the editor.  Falls back to `WorkspaceSymbols::allClassFqns()`
     * (open-only) when no `FqnIndex` is wired -- legacy constructor
     * path.
     *
     * @return list<string>
     */
    private function resolveContainerFqns(string $name): array
    {
        $needle = ltrim($name, '\\');
        if ($needle === '') {
            return [];
        }
        if (str_contains($needle, '\\')) {
            return [$needle];
        }
        $matches = [];
        if ($this->fqnIndex !== null) {
            foreach ($this->fqnIndex->allDeclarations() as $hit) {
                if ($hit['kind'] !== 'class') {
                    continue;
                }
                if (self::lastSegment($hit['fqn']) === $needle) {
                    $matches[] = $hit['fqn'];
                }
            }
            return $matches;
        }
        foreach ($this->symbols->allClassFqns() as $fqn) {
            if (self::lastSegment($fqn) === $needle) {
                $matches[] = $fqn;
            }
        }
        return $matches;
    }

    /**
     * "Is `$candidateFqn` a subtype of `$boundFqn`?"  Walks the candidate
     * class's parent + interface chain via worse-reflection.  Returns true
     * when the bound appears in the chain (or equals the candidate); false
     * otherwise; ALSO true when reflection fails -- we prefer surfacing a
     * possibly-incompatible candidate over silently hiding one the user
     * meant to pick.
     */
    private function satisfiesBound(string $candidateFqn, string $boundFqn): bool
    {
        if ($this->reflector === null) {
            return true;
        }
        $candidate = ltrim($candidateFqn, '\\');
        $bound = ltrim($boundFqn, '\\');
        if ($candidate === '' || $bound === '' || $candidate === $bound) {
            return true;
        }
        try {
            $class = $this->reflector->reflectClassLike($candidate);
        } catch (Throwable) {
            return true;
        }
        // ReflectionClassLike::isInstanceOf walks the parent + interface
        // chain itself, including transitive ancestors via interfaces.
        // Cleaner than rolling our own BFS and respects worse-reflection's
        // internal caching of the hierarchy.
        try {
            return $class->isInstanceOf(ClassName::fromString($bound));
        } catch (Throwable) {
            return true;
        }
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

    /**
     * Class FQNs eligible as type-arg candidates.  Prefers `FqnIndex`
     * (open docs + filesystem) when wired so closed-file workspace
     * classes also surface; falls back to `WorkspaceSymbols` (open
     * only) on the legacy constructor path used by older tests.
     *
     * @return iterable<string>
     */
    private function candidateClassFqns(): iterable
    {
        if ($this->fqnIndex !== null) {
            $seen = [];
            foreach ($this->fqnIndex->allDeclarations() as $hit) {
                if ($hit['kind'] !== 'class') {
                    continue;
                }
                $fqn = $hit['fqn'];
                if (isset($seen[$fqn])) {
                    continue;
                }
                $seen[$fqn] = true;
                yield $fqn;
            }
            return;
        }
        foreach ($this->symbols->allClassFqns() as $fqn) {
            yield $fqn;
        }
    }

    private static function lastSegment(string $fqn): string
    {
        $pos = strrpos($fqn, '\\');
        return $pos === false ? $fqn : substr($fqn, $pos + 1);
    }
}
