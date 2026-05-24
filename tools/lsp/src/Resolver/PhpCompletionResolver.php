<?php

declare(strict_types=1);

namespace XPHP\Lsp\Resolver;

use PhpParser\ErrorHandler\Collecting as CollectingErrorHandler;
use PhpParser\Node;
use PhpParser\Node\ClosureUse;
use PhpParser\Node\Expr\Assign;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Param;
use PhpParser\Node\Stmt\Foreach_;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;
use PhpParser\Parser;
use PhpParser\ParserFactory;
use Phpactor\LanguageServer\Core\Workspace\Workspace as PhpactorWorkspace;
use Phpactor\LanguageServerProtocol\CompletionItem;
use Phpactor\LanguageServerProtocol\CompletionItemKind;
use Phpactor\TextDocument\ByteOffset;
use Phpactor\TextDocument\TextDocumentBuilder;
use Phpactor\WorseReflection\Core\Inference\Symbol;
use Phpactor\WorseReflection\Core\Reflection\ReflectionMember;
use Phpactor\WorseReflection\Core\Visibility;
use Phpactor\WorseReflection\Reflector;
use Throwable;
use XPHP\Lsp\Analyzer\ParsedDocumentCache;
use XPHP\Lsp\PositionMap;
use XPHP\Transpiler\Monomorphize\XphpSourceParser;

/**
 * Hand-rolled member / static-member completion backed by worse-reflection.
 *
 * Triggers off `PhpCompletionContext::detect()` which classifies the cursor
 * into `member` (`$obj->|`) or `static` (`Cls::|`).  For each shape:
 *
 *  - Member: reflect the byte offset just BEFORE the `->` to get the
 *    receiver's inferred type via `NodeContext::type()`, then reflect
 *    that class and enumerate methods + properties.
 *  - Static: same approach -- reflectOffset at the byte before `::` so
 *    worse-reflection resolves `self`, `parent`, `static`, and bare
 *    class names against the surrounding use-import scope.
 *
 * Filter:
 *  - Public members always included (until we have access-control aware
 *    callsite tracking, which is hard with non-existent receiver info
 *    on the line under construction).
 *  - Magic `__*` methods excluded -- editors are noisy enough about them.
 *
 * Returns an empty list (not null) for contexts we don't handle so the
 * caller can compose this with other completion sources without
 * special-casing nulls.
 *
 * Why not use phpactor/completion-worse?  The package is abandoned on
 * packagist (entire phpactor/completion-* tree); pulling in an abandoned
 * dep was rejected for long-term maintenance reasons.  See plan risk #4.
 */
final class PhpCompletionResolver
{
    public function __construct(
        private readonly PhpactorWorkspace $workspace,
        private readonly XphpSourceParser $parser,
        private readonly Reflector $reflector,
        private readonly CompletionIndex $completionIndex,
        private readonly ParsedDocumentCache $cache,
        private readonly GenericParamRegistry $genericParams,
        private readonly GenericResolver $genericResolver,
    ) {
    }

    /**
     * @return list<CompletionItem>
     */
    public function complete(string $uri, int $line, int $character): array
    {
        // Top-level safety net for parity with PhpDefinitionResolver and
        // PhpHoverResolver -- any unexpected `Error` from worse-reflection
        // becomes "no completion" instead of an uncaught fatal that
        // poisons the LSP transport via stdout.
        try {
            return $this->completeInner($uri, $line, $character);
        } catch (Throwable $t) {
            self::trace(sprintf(
                'top-level catch %s: %s',
                $t::class,
                self::oneLine($t->getMessage()),
            ));
            return [];
        }
    }

    /**
     * @return list<CompletionItem>
     */
    private function completeInner(string $uri, int $line, int $character): array
    {
        self::trace(sprintf('request uri=%s line=%d char=%d', $uri, $line, $character));

        if (!$this->workspace->has($uri)) {
            self::trace('workspace miss; no document');
            return [];
        }
        $document = $this->workspace->get($uri);
        $cursorOffset = (new PositionMap($document->text))->positionToOffset($line, $character);

        $hit = PhpCompletionContext::detect($document->text, $cursorOffset);
        if ($hit === null) {
            self::trace(sprintf('context detect=null offset=%d', $cursorOffset));
            return [];
        }
        self::trace(sprintf(
            'context kind=%s prefix=%s%s offset=%d',
            $hit['kind'],
            json_encode($hit['prefix']),
            isset($hit['receiverEnd']) ? sprintf(' receiverEnd=%d', $hit['receiverEnd']) : '',
            $cursorOffset,
        ));

        $items = match ($hit['kind']) {
            'member', 'static' => $this->completeMembers($uri, $document->text, $hit),
            'variable'         => $this->completeVariables($uri, $hit['prefix']),
            'new'              => $this->completeClassesByPrefix($hit['prefix']),
            'expression'       => array_merge(
                $this->completeClassesByPrefix($hit['prefix']),
                $this->completeFunctionsByPrefix($hit['prefix']),
            ),
        };
        self::trace(sprintf('returned items=%d', count($items)));
        return $items;
    }

    /**
     * Member / static access completion via worse-reflection.
     *
     * @param array{kind: string, receiverEnd: int, prefix: string} $hit
     * @return list<CompletionItem>
     */
    private function completeMembers(string $uri, string $documentText, array $hit): array
    {
        $stripped = $this->parser->strip($documentText);
        $source = TextDocumentBuilder::create($stripped)->uri($uri)->language('php')->build();

        // Worse-reflection wants an offset INSIDE the receiver expression
        // (one byte before the operator) so the resolver classifies the
        // expression rather than the trailing `->` / `::`.
        $receiverProbe = max(0, $hit['receiverEnd'] - 1);
        try {
            $offsetReflection = $this->reflector->reflectOffset($source, ByteOffset::fromInt($receiverProbe));
        } catch (Throwable $t) {
            self::trace(sprintf(
                'reflectOffset threw %s: %s',
                $t::class,
                self::oneLine($t->getMessage()),
            ));
            return [];
        }

        $context = $offsetReflection->nodeContext();
        // `(string) $type` is safe for every Type subclass (MissingType,
        // PrimitiveType, ClassType, ...); calling `name()` directly blows
        // up on MissingType.  MissingType stringifies to `<missing>`.
        $typeName = (string) $context->type();
        self::trace(sprintf(
            'reflectOffset type=%s symbolKind=%s name=%s',
            $typeName,
            $context->symbol()->symbolType(),
            $context->symbol()->name(),
        ));
        if ($typeName === '' || $typeName === '<missing>') {
            return [];
        }

        // Nullable wrapper: worse-reflection surfaces `function f(): ?User`
        // return-type as the receiver type `?App\Models\User` for chained
        // `f()?->|` access.  reflectClassLike treats the `?` as part of the
        // FQN and throws SourceNotFound -- so strip it before lookup.  The
        // members of `?User` are the same as the members of `User`.
        $lookupName = ltrim($typeName, '?');

        // Monomorphization rescue: when the receiver is a variable bound
        // by a `new Generic<...>(...)` upstream, worse-reflection sees
        // only the post-strip placeholder (`?App\Containers\T`) and the
        // lookup above would fail.  GenericResolver has tracked the
        // type-arg binding and can hand back the substituted concrete
        // class -- use that instead so the user gets `User`'s methods
        // rather than an empty list.
        if ($context->symbol()->symbolType() === 'variable') {
            $varName = $context->symbol()->name();
            $resolved = $this->genericResolver->resolveVariableTypeRef($uri, $varName);
            if ($resolved !== null && $resolved->ref->name !== '' && $resolved->ref->name !== $lookupName) {
                self::trace(sprintf(
                    'receiver swap via GenericResolver: $%s %s -> %s',
                    $varName,
                    $lookupName,
                    $resolved->ref->name,
                ));
                $lookupName = $resolved->ref->name;
            }
        }

        try {
            $class = $this->reflector->reflectClassLike($lookupName);
        } catch (Throwable $t) {
            self::trace(sprintf(
                'reflectClassLike(%s) threw %s: %s',
                $lookupName,
                $t::class,
                self::oneLine($t->getMessage()),
            ));
            return [];
        }

        $methodsAll = count($class->methods());
        $propsAll = count($class->properties());
        $constsAll = count($class->constants());
        self::trace(sprintf(
            'reflectClassLike(%s) ok methods=%d props=%d consts=%d',
            $lookupName,
            $methodsAll,
            $propsAll,
            $constsAll,
        ));

        $items = [];
        $isStatic = $hit['kind'] === 'static';
        $droppedMagic = 0;
        $droppedStatic = 0;
        $droppedVis = 0;
        $droppedPrefix = 0;

        foreach ($class->methods() as $method) {
            if (str_starts_with($method->name(), '__')) {
                $droppedMagic++;
                continue;
            }
            if ($isStatic xor $method->isStatic()) {
                $droppedStatic++;
                continue;
            }
            if (!self::isVisibleFromCaller($method->visibility())) {
                $droppedVis++;
                continue;
            }
            if (!self::matchesPrefix($method->name(), $hit['prefix'])) {
                $droppedPrefix++;
                continue;
            }
            $items[] = self::methodItem($method);
        }

        // Properties only show on instance-member access.  Static
        // properties via `Cls::$prop` use a `$` prefix that
        // `PhpCompletionContext` doesn't currently recognise as a
        // separate shape, so we skip statics here -- punt to a
        // follow-up.
        if (!$isStatic) {
            foreach ($class->properties() as $property) {
                if (!self::isVisibleFromCaller($property->visibility())) {
                    continue;
                }
                if ($property->isStatic()) {
                    continue;
                }
                if (!self::matchesPrefix($property->name(), $hit['prefix'])) {
                    continue;
                }
                $items[] = self::propertyItem($property);
            }
        } else {
            // Static constants surface on `Cls::|`.
            foreach ($class->constants() as $constant) {
                if (!self::matchesPrefix((string) $constant->name(), $hit['prefix'])) {
                    continue;
                }
                $items[] = new CompletionItem(
                    label: (string) $constant->name(),
                    kind: CompletionItemKind::CONSTANT,
                    detail: (string) $class->name(),
                );
            }
        }

        self::trace(sprintf(
            'member filter kept=%d dropped magic=%d static=%d vis=%d prefix=%d',
            count($items),
            $droppedMagic,
            $droppedStatic,
            $droppedVis,
            $droppedPrefix,
        ));

        /** @var list<CompletionItem> $items */
        return $items;
    }

    /**
     * Variable completion: every name introduced by a Param / Assign-target /
     * Foreach var / ClosureUse anywhere in the current document, filtered by
     * prefix.  Cursor-scope-unaware (matches the same compromise made in
     * `PhpDefinitionResolver::locateVariable`); shadowed variables surface
     * once with their first appearance.
     *
     * @return list<CompletionItem>
     */
    private function completeVariables(string $uri, string $prefix): array
    {
        if (!$this->workspace->has($uri)) {
            return [];
        }
        $item = $this->workspace->get($uri);
        $result = $this->cache->getOrParse($uri, $item->version, $item->text);

        $ast = $result->ast;
        if ($ast === null) {
            // The cache's strict parse failed -- typical when the user is
            // mid-edit and the trailing characters aren't valid PHP yet
            // (e.g. cursor on `$us` with no statement terminator).  Retry
            // with an error-collecting handler so we get a best-effort AST
            // of everything BEFORE the broken region -- enough to surface
            // variables the user already declared above.
            $ast = $this->tolerantParse($item->text);
            self::trace(sprintf(
                'variable completion: cache miss; tolerant-parse %s',
                $ast === null ? 'returned null too' : sprintf('recovered %d top-level stmts', count($ast)),
            ));
            if ($ast === null) {
                return [];
            }
        }

        $collector = new class extends NodeVisitorAbstract {
            /** @var array<string, true> */
            public array $names = [];

            public function enterNode(Node $node): null
            {
                if ($node instanceof Param && $node->var instanceof Variable && is_string($node->var->name)) {
                    $this->names[$node->var->name] = true;
                    return null;
                }
                if ($node instanceof Assign && $node->var instanceof Variable && is_string($node->var->name)) {
                    $this->names[$node->var->name] = true;
                    return null;
                }
                if ($node instanceof Foreach_) {
                    if ($node->keyVar instanceof Variable && is_string($node->keyVar->name)) {
                        $this->names[$node->keyVar->name] = true;
                    }
                    if ($node->valueVar instanceof Variable && is_string($node->valueVar->name)) {
                        $this->names[$node->valueVar->name] = true;
                    }
                    return null;
                }
                if ($node instanceof ClosureUse && $node->var instanceof Variable && is_string($node->var->name)) {
                    $this->names[$node->var->name] = true;
                }
                return null;
            }
        };

        $traverser = new NodeTraverser();
        $traverser->addVisitor($collector);
        $traverser->traverse($ast);

        $items = [];
        foreach (array_keys($collector->names) as $name) {
            if (!self::variableMatchesPrefix($name, $prefix)) {
                continue;
            }
            $items[] = new CompletionItem(
                label: '$' . $name,
                kind: CompletionItemKind::VARIABLE,
                insertText: $name,
            );
        }
        return $items;
    }

    /**
     * @return list<CompletionItem>
     */
    private function completeClassesByPrefix(string $prefix): array
    {
        // Empty prefix in `new ` or bare-expression position would dump the
        // entire workspace + ~1000 stub classes into the popup.  Require
        // the user to type at least one letter; the cost is one extra
        // keystroke and the win is a usable suggestion list.
        if ($prefix === '') {
            return [];
        }

        $items = [];
        foreach ($this->completionIndex->classFqns() as $fqn) {
            $short = self::lastSegment($fqn);
            if (!self::fqnMatchesPrefix($short, $fqn, $prefix)) {
                continue;
            }
            $items[] = new CompletionItem(
                label: $short,
                kind: CompletionItemKind::CLASS_,
                detail: $fqn,
                insertText: $fqn,
            );
        }
        return $items;
    }

    /**
     * @return list<CompletionItem>
     */
    private function completeFunctionsByPrefix(string $prefix): array
    {
        // Same rationale as `completeClassesByPrefix`: phpstorm-stubs ships
        // ~5000 functions; dumping all on Ctrl+Space with no prefix is
        // unusable.
        if ($prefix === '') {
            return [];
        }

        $items = [];
        foreach ($this->completionIndex->functionFqns() as $fqn) {
            $short = self::lastSegment($fqn);
            if (!self::fqnMatchesPrefix($short, $fqn, $prefix)) {
                continue;
            }
            $items[] = new CompletionItem(
                label: $short,
                kind: CompletionItemKind::FUNCTION,
                detail: $fqn,
                insertText: $fqn,
            );
        }
        return $items;
    }

    private static function lastSegment(string $fqn): string
    {
        $idx = strrpos($fqn, '\\');
        return $idx === false ? $fqn : substr($fqn, $idx + 1);
    }

    /**
     * Match either the short name (prefix start) or the FQN (substring).
     * `str` -> `strlen`, `str_replace`; `App\Mo` -> `App\Models\User`.
     */
    private static function fqnMatchesPrefix(string $shortName, string $fqn, string $prefix): bool
    {
        $needle = ltrim($prefix, '\\');
        if ($needle === '') {
            return true;
        }
        return stripos($shortName, $needle) === 0 || stripos($fqn, $needle) !== false;
    }

    private static function variableMatchesPrefix(string $varName, string $prefix): bool
    {
        if ($prefix === '') {
            return true;
        }
        return stripos($varName, $prefix) === 0;
    }

    private function methodItem($method): CompletionItem
    {
        $params = [];
        foreach ($method->parameters() as $p) {
            $type = $this->genericParams->prettify((string) $p->inferredType());
            $params[] = trim(($type !== '' && $type !== '<missing>' ? $type . ' ' : '') . '$' . $p->name());
        }
        $return = $this->genericParams->prettify((string) $method->returnType());
        $signature = sprintf(
            '(%s)%s',
            implode(', ', $params),
            $return !== '' && $return !== '<missing>' ? ': ' . $return : '',
        );
        return new CompletionItem(
            label: $method->name(),
            kind: CompletionItemKind::METHOD,
            detail: $signature,
            insertText: $method->name(),
        );
    }

    private function propertyItem($property): CompletionItem
    {
        $type = $this->genericParams->prettify((string) $property->inferredType());
        return new CompletionItem(
            label: $property->name(),
            kind: CompletionItemKind::PROPERTY,
            detail: $type !== '' && $type !== '<missing>' ? $type : null,
            insertText: $property->name(),
        );
    }

    private static function isVisibleFromCaller(Visibility $visibility): bool
    {
        // MVP: public only.  Private/protected need to know the caller's
        // class scope, which worse-reflection's offset reflection gives
        // us via `scope()` -- threading that through here is a follow-up.
        return $visibility->isPublic();
    }

    private static function matchesPrefix(string $candidate, string $prefix): bool
    {
        if ($prefix === '') {
            return true;
        }
        return stripos($candidate, $prefix) === 0;
    }

    /**
     * Best-effort parse of xphp-stripped source through nikic with error
     * recovery so variable completion can still succeed when the user is
     * mid-edit and the buffer isn't syntactically valid PHP yet.
     *
     * @return list<Node\Stmt>|null
     */
    private function tolerantParse(string $source): ?array
    {
        if (self::$tolerantParser === null) {
            self::$tolerantParser = (new ParserFactory())->createForHostVersion();
        }
        $stripped = $this->parser->strip($source);
        try {
            $ast = self::$tolerantParser->parse($stripped, new CollectingErrorHandler());
        } catch (Throwable) {
            return null;
        }
        return $ast;
    }

    private static ?Parser $tolerantParser = null;

    /**
     * Write a tagged diagnostic line to stderr.  PhpStorm captures the LSP
     * server's stderr into idea.log; the `[xphp-lsp completion]` prefix
     * lets users grep one round trip out of a noisy log.  Failures here
     * are themselves silenced (stderr could close in tests) so we never
     * mask a real error with a logging error.
     */
    private static function trace(string $message): void
    {
        @fwrite(STDERR, '[xphp-lsp completion] ' . $message . "\n");
    }

    private static function oneLine(string $message): string
    {
        return str_replace(["\r", "\n"], ' ', $message);
    }
}
