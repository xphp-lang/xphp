<?php

declare(strict_types=1);

namespace XPHP\Lsp\Resolver;

use PhpParser\ErrorHandler\Collecting as CollectingErrorHandler;
use PhpParser\Node;
use PhpParser\Node\ClosureUse;
use PhpParser\Node\Expr\ArrowFunction;
use PhpParser\Node\Expr\Assign;
use PhpParser\Node\Expr\Closure;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Param;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Foreach_;
use PhpParser\Node\Stmt\Function_;
use PhpParser\Node\Stmt\Namespace_;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;
use PhpParser\Parser;
use PhpParser\ParserFactory;
use Phpactor\LanguageServer\Core\Workspace\Workspace as PhpactorWorkspace;
use Phpactor\LanguageServerProtocol\CompletionItem;
use Phpactor\LanguageServerProtocol\CompletionItemKind;
use Phpactor\LanguageServerProtocol\Position;
use Phpactor\LanguageServerProtocol\Range;
use Phpactor\LanguageServerProtocol\TextEdit;
use Phpactor\TextDocument\ByteOffset;
use Phpactor\TextDocument\TextDocumentBuilder;
use Phpactor\WorseReflection\Core\Inference\Symbol;
use Phpactor\WorseReflection\Core\Reflection\ReflectionMember;
use Phpactor\WorseReflection\Core\Visibility;
use Phpactor\WorseReflection\Reflector;
use Throwable;
use XPHP\Lsp\Analyzer\ParsedDocumentCache;
use XPHP\Lsp\PositionMap;
use XPHP\Lsp\Stderr;
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

        // Class-name completion needs the file's namespace + use map to
        // pick the right insertText shape (bare short name when imported
        // or same-namespace, leading-backslash FQ otherwise). Computed
        // once per request and shared across both class-completion arms.
        $importContext = ClassNameImportContext::extractFromSource($document->text);

        $items = match ($hit['kind']) {
            'member', 'static', 'static-prop' => $this->completeMembers($uri, $document->text, $hit, $line, $character),
            'variable'         => $this->completeVariables($uri, $hit['prefix'], $cursorOffset, $line, $character),
            'new'              => $this->completeClassesByPrefix($hit['prefix'], $importContext),
            'expression'       => array_merge(
                $this->completeClassesByPrefix($hit['prefix'], $importContext),
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
    private function completeMembers(string $uri, string $documentText, array $hit, int $line = 0, int $character = 0): array
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

        // Monomorphization rescue: when the receiver is anything bound
        // to a generic class (variable holding a `new Generic<...>(...)`,
        // method call returning a generic-instantiated type, etc.),
        // worse-reflection sees only the post-strip placeholder
        // (`?App\Containers\T`) and the lookup above would fail.
        // GenericResolver walks the receiver expression and hands back
        // the substituted concrete class.  Covers BOTH the variable
        // case (`$user->|`) and the chained-call case (`$x->y()?->|`).
        $swapped = $this->genericResolver->resolveMemberAccessReceiverClassAt($uri, $receiverProbe);
        if ($swapped !== null && $swapped !== $lookupName) {
            self::trace(sprintf(
                'receiver swap via GenericResolver: %s -> %s (kind=%s name=%s)',
                $lookupName,
                $swapped,
                $context->symbol()->symbolType(),
                $context->symbol()->name(),
            ));
            $lookupName = $swapped;
        }

        // Cycle K.1: fan out per union arm.  Single-class types
        // short-circuit through the existing path; union /
        // intersection receivers split via TypeUnionSplitter and the
        // per-arm member sets get merged per the user-specified UX:
        //   - union arms (`A|B`)    -> UNION of members across arms
        //   - intersection within (`A&B`) -> INTERSECTION across
        //                                    components of that arm
        // Members are deduped by (kind, label).
        if (!ClassFqnPredicate::is($lookupName)) {
            return $this->fanOutMembers($lookupName, $hit, $uri, $receiverProbe, $line, $character);
        }
        $callerClassFqn = $this->enclosingClassFqnAt($uri, $receiverProbe);
        return $this->itemsForClass($lookupName, $hit, $callerClassFqn, $line, $character);
    }

    /**
     * Build the member-completion list for a single class FQN.
     *
     * Extracted from `completeMembers` to support Cycle K.1's union/
     * intersection fan-out.  Visibility (private / protected) is
     * evaluated against the caller's enclosing class -- threaded in
     * rather than recomputed so the union-fan-out's repeated calls
     * agree on the caller scope.
     *
     * @param array{kind: string, receiverEnd: int, prefix: string} $hit
     * @return list<CompletionItem>
     */
    private function itemsForClass(string $lookupName, array $hit, ?string $callerClassFqn, int $line, int $character): array
    {
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

        // Interfaces don't have properties -- in PHP, only classes,
        // traits, and enums do.  Worse-reflection's `ReflectionInterface`
        // omits the `properties()` method entirely; calling it throws
        // `Error: Call to undefined method ReflectionInterface::properties()`
        // which top-level-catches to an empty completion list.  Symptom:
        // `\DateTimeInterface::|` returns no items, while `\DateTime::|`
        // works fine.  Gate every `properties()` access on a method
        // existence check rather than `instanceof ReflectionInterface`
        // -- there are multiple TolerantParser / Core variants of the
        // class, and `method_exists` covers them all without us having
        // to enumerate them.
        $hasProperties = method_exists($class, 'properties');
        $methodsAll = count($class->methods());
        $propsAll = $hasProperties ? count($class->properties()) : 0;
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
        $isStaticProp = $hit['kind'] === 'static-prop';
        // Static-prop is a STATIC context (the `::$prop` form), but the
        // member set we want is only static properties -- no methods,
        // no constants.  Treat as static for the same-class / subclass
        // visibility plumbing below; gate the iteration shapes below
        // on the discriminating $isStaticProp flag.
        if ($isStaticProp) {
            $isStatic = true;
        }
        $droppedMagic = 0;
        $droppedStatic = 0;
        $droppedVis = 0;
        $droppedPrefix = 0;

        // Phase 3 polish: thread the caller's enclosing class FQN so
        // private + protected members are visible when the cursor is
        // inside the same class.  Subclass-protected (caller is a
        // descendant of the receiver class) consults worse-reflection's
        // parents() walk -- protected becomes visible there too;
        // private stays gated to same-class only.
        //
        // Cycle K.1: $callerClassFqn is now threaded in by the caller
        // (`completeMembers` for the single-class path, `fanOutMembers`
        // for each union/intersection constituent) so the same caller-
        // scope decision is shared across every per-component call.
        $isSameClass = $callerClassFqn !== null && $callerClassFqn === $lookupName;
        $isSubclass = !$isSameClass
            && $callerClassFqn !== null
            && $this->isSubclassOf($callerClassFqn, $lookupName);

        // static-prop is a narrow context -- only static properties show.
        // Skip methods + constants entirely; that keeps `Cls::$|` from
        // polluting the popup with unrelated members.
        if (!$isStaticProp) {
            foreach ($class->methods() as $method) {
                if (str_starts_with($method->name(), '__')) {
                    $droppedMagic++;
                    continue;
                }
                if ($isStatic xor $method->isStatic()) {
                    $droppedStatic++;
                    continue;
                }
                if (!self::isVisibleFromCaller($method->visibility(), $isSameClass, $isSubclass)) {
                    $droppedVis++;
                    continue;
                }
                if (!self::matchesPrefix($method->name(), $hit['prefix'])) {
                    $droppedPrefix++;
                    continue;
                }
                $items[] = self::methodItem($method);
            }
        }

        if ($isStaticProp) {
            // `Cls::$|` -- only static properties.
            // The textEdit's range starts at the cursor (in LSP coords)
            // and extends to the same point: an explicit empty-range
            // anchor.  Without it PhpStorm extends the replacement
            // range backwards through the `$` and swallows it on
            // accept, producing `Cls::name` (constant access) instead
            // of `Cls::$name` (static-property access).  PhpStorm
            // observed in prod log id=28 of xphp-20260525-172338-536.log.
            $staticPropAnchorStart = new Position($line, $character);
            $staticPropPrefixLen = strlen($hit['prefix']);
            $staticPropAnchorEnd = new Position($line, $character);
            // When the user has typed `Stats::$la|`, the popup-side
            // filter has already narrowed to items whose label has
            // `$la` prefix.  On accept we still need to replace the
            // `la` they typed -- extend the range BACKWARDS to cover
            // the typed prefix (but NOT the `$`).
            if ($staticPropPrefixLen > 0) {
                $staticPropAnchorStart = new Position($line, max(0, $character - $staticPropPrefixLen));
            }
            if ($hasProperties) {
                foreach ($class->properties() as $property) {
                    if (!$property->isStatic()) {
                        continue;
                    }
                    if (!self::isVisibleFromCaller($property->visibility(), $isSameClass, $isSubclass)) {
                        continue;
                    }
                    if (!self::matchesPrefix($property->name(), $hit['prefix'])) {
                        continue;
                    }
                    $items[] = $this->propertyItem(
                        $property,
                        forStaticProp: true,
                        textEditRange: new Range($staticPropAnchorStart, $staticPropAnchorEnd),
                    );
                }
            }
        } elseif (!$isStatic) {
            // `$obj->|` -- only instance properties.  Interfaces have
            // no properties so we skip the iteration when `$class` is
            // a ReflectionInterface.  Methods on interfaces are still
            // surfaced by the earlier `methods()` loop.
            if ($hasProperties) {
                foreach ($class->properties() as $property) {
                    if (!self::isVisibleFromCaller($property->visibility(), $isSameClass, $isSubclass)) {
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
            }
        } else {
            // `Cls::|` -- static methods (above) + static properties +
            // class constants.  Static properties used to be silently
            // skipped here (the parallel `Cls::$|` branch handled them
            // but the bare-static branch had only constants), so
            // `$repo::$test` never surfaced for a `public static
            // string $test` declared on the receiver class.
            //
            // Item shape mirrors `Cls::$|` (the static-prop branch
            // above): label carries `$` for popup display; filterText
            // is the bare name so PhpStorm filters the typed prefix
            // (which doesn't yet include `$`) against the candidate;
            // the textEdit replaces the typed prefix with `$<name>`
            // so the `$` lands in source on accept regardless of
            // how PhpStorm would otherwise pick the implicit range.
            $bareStaticPropAnchorStart = new Position($line, max(0, $character - strlen($hit['prefix'])));
            $bareStaticPropAnchorEnd = new Position($line, $character);
            if ($hasProperties) {
                foreach ($class->properties() as $property) {
                    if (!$property->isStatic()) {
                        continue;
                    }
                    if (!self::isVisibleFromCaller($property->visibility(), $isSameClass, $isSubclass)) {
                        $droppedVis++;
                        continue;
                    }
                    if (!self::matchesPrefix($property->name(), $hit['prefix'])) {
                        $droppedPrefix++;
                        continue;
                    }
                    $propType = $this->genericParams->prettify((string) $property->inferredType());
                    $propName = $property->name();
                    $completion = new CompletionItem(
                        label: '$' . $propName,
                        kind: CompletionItemKind::PROPERTY,
                        detail: $propType !== '' && $propType !== '<missing>' ? $propType : null,
                        insertText: '$' . $propName,
                        filterText: $propName,
                    );
                    $completion->textEdit = new TextEdit(
                        new Range($bareStaticPropAnchorStart, $bareStaticPropAnchorEnd),
                        '$' . $propName,
                    );
                    $items[] = $completion;
                }
            }
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
     * Cycle K.1 union/intersection fan-out for member completion.
     *
     * For each union arm (one per `|`), build the per-component
     * completion lists.  Intersect the components within the arm by
     * (kind, label), then union across arms (also deduped by
     * (kind, label)).  This matches the user-specified UX:
     *
     *   - `$x: A|B`   -> arms = [{A}, {B}], each arm yields its
     *                    component's full member set; union shows
     *                    everything from A OR B.
     *   - `$x: A&B`   -> arms = [{A,B}], intersection yields only
     *                    members common to A AND B.
     *   - `$x: (A&B)|C` -> arms = [{A,B}, {C}], result =
     *                      (A's members ∩ B's members) ∪ C's members.
     *
     * @param array{kind: string, receiverEnd: int, prefix: string} $hit
     * @return list<CompletionItem>
     */
    private function fanOutMembers(string $typeName, array $hit, string $uri, int $receiverProbe, int $line, int $character): array
    {
        $arms = TypeUnionSplitter::split($typeName);
        if ($arms === []) {
            self::trace(sprintf('union split yielded no class FQNs for %s', $typeName));
            return [];
        }
        // Per-call caller-class lookup: same scope for every component
        // in the fan-out.
        $callerClassFqn = $this->enclosingClassFqnAt($uri, $receiverProbe);

        $merged = [];
        $mergedKeys = [];
        foreach ($arms as $components) {
            $perComponent = [];
            foreach ($components as $componentFqn) {
                $perComponent[] = $this->itemsForClass($componentFqn, $hit, $callerClassFqn, $line, $character);
            }
            $armItems = count($perComponent) === 1
                ? $perComponent[0]
                : self::intersectByKindLabel($perComponent);
            foreach ($armItems as $item) {
                $key = (string) ($item->kind ?? '') . '::' . $item->label;
                if (isset($mergedKeys[$key])) {
                    continue;
                }
                $mergedKeys[$key] = true;
                $merged[] = $item;
            }
        }
        self::trace(sprintf('fan-out completion: %s -> %d items across %d arm(s)', $typeName, count($merged), count($arms)));
        return $merged;
    }

    /**
     * Return items whose (kind, label) appears in EVERY list of
     * `$perComponentItems`.  The returned items come from the first
     * list (so the `detail` / `insertText` reflect that component's
     * shape; the user-facing label/kind is what intersection
     * promised).
     *
     * @param list<list<CompletionItem>> $perComponentItems
     * @return list<CompletionItem>
     */
    private static function intersectByKindLabel(array $perComponentItems): array
    {
        if ($perComponentItems === []) {
            return [];
        }
        // Build key sets for every component except the first.
        $otherKeySets = [];
        for ($i = 1, $n = count($perComponentItems); $i < $n; $i++) {
            $set = [];
            foreach ($perComponentItems[$i] as $item) {
                $set[(string) ($item->kind ?? '') . '::' . $item->label] = true;
            }
            $otherKeySets[] = $set;
        }
        // Keep first-component items whose key appears in every
        // other component's set.
        $intersection = [];
        foreach ($perComponentItems[0] as $item) {
            $key = (string) ($item->kind ?? '') . '::' . $item->label;
            $inAll = true;
            foreach ($otherKeySets as $set) {
                if (!isset($set[$key])) {
                    $inAll = false;
                    break;
                }
            }
            if ($inAll) {
                $intersection[] = $item;
            }
        }
        return $intersection;
    }

    /**
     * Variable completion, scope-aware.
     *
     * Visible names at the cursor:
     *   - Top-level cursor: variables introduced anywhere at the script's
     *     top level (outside any function/method/closure).
     *   - Inside a function/method: only that body's params + locally-
     *     assigned variables.  PHP's function-scope barrier hides outer
     *     scope from regular `function` / method bodies.
     *   - Inside a `function () use ($a, $b) { ... }` closure: the
     *     closure's params + `use (...)` captures + closure-local
     *     variables.  Outer-scope names not in the use clause stay
     *     hidden.
     *   - Inside an `fn () => ...` arrow function: the arrow's params +
     *     all outer-scope variables (PHP's auto-capture semantics).
     *
     * Mid-edit safety: if the strict parse failed (typical for cursor on
     * `$us` with no statement terminator), retry with an error-collecting
     * handler so the variables defined BEFORE the broken region still
     * surface.
     *
     * @return list<CompletionItem>
     */
    private function completeVariables(string $uri, string $prefix, int $cursorOffset, int $line, int $character): array
    {
        if (!$this->workspace->has($uri)) {
            return [];
        }
        $item = $this->workspace->get($uri);
        $result = $this->cache->getOrParse($uri, $item->version, $item->text);

        $ast = $result->ast;
        if ($ast === null) {
            $ast = $this->tolerantParse($item->text);
            self::trace(sprintf(
                'variable completion: cache miss; tolerant-parse %s',
                $ast === null ? 'returned null too' : sprintf('recovered %d top-level stmts', count($ast)),
            ));
            if ($ast === null) {
                return [];
            }
        }

        // The script body itself is the outermost "scope" (null sentinel).
        // Inner scopes are Function_/ClassMethod/Closure/ArrowFunction nodes.
        // The chain runs outermost (null) -> ... -> innermost (the scope
        // containing the cursor).
        $chain = self::scopeChainAt($ast, $cursorOffset);

        /** @var array<string, true> $visible */
        $visible = [];

        $innermost = end($chain);
        if ($innermost === null) {
            // Top-level cursor -- collect top-level variables.
            self::collectScopeBodyVariables($ast, $visible);
        } else {
            self::collectScopeOwnVariables($innermost, $visible);

            // Closures: only explicit `use (...)` brings outer names in.
            // (Already captured by collectScopeOwnVariables.)
            //
            // ArrowFunctions: auto-capture from the chain of enclosing
            // arrow functions / top-level until we hit the first function
            // barrier (Function_, ClassMethod, Closure).  Closure stops
            // the walk because it ALREADY filters outer scope through its
            // own `use (...)` clause.
            if ($innermost instanceof ArrowFunction) {
                for ($i = count($chain) - 2; $i >= 0; $i--) {
                    $outer = $chain[$i];
                    if ($outer === null) {
                        self::collectScopeBodyVariables($ast, $visible);
                        break;
                    }
                    self::collectScopeOwnVariables($outer, $visible);
                    if ($outer instanceof Function_
                        || $outer instanceof ClassMethod
                        || $outer instanceof Closure
                    ) {
                        break;
                    }
                }
            }
        }

        $items = [];
        // Pin the replacement range to START at the typed prefix's first
        // character (right AFTER the `$` already in source).  Without
        // this textEdit, PhpStorm extends the implicit range backward
        // through the `$` -- treating it as part of the same word
        // token -- and the accept swallows it, leaving `item` instead
        // of `$item`.  Prod log id=178 of xphp-20260529-104259-087.log
        // captures the regression; the static-prop branch of
        // `propertyItem()` carries the same fix.
        $prefixLen = strlen($prefix);
        $anchorStart = new Position($line, max(0, $character - $prefixLen));
        $anchorEnd = new Position($line, $character);
        foreach (array_keys($visible) as $name) {
            if (!self::variableMatchesPrefix($name, $prefix)) {
                continue;
            }
            $completion = new CompletionItem(
                label: '$' . $name,
                kind: CompletionItemKind::VARIABLE,
                insertText: $name,
                // filterText keeps the item visible while the user
                // types more characters AFTER `$` (PhpStorm matches
                // the typed `$it` prefix against `filterText`, not
                // `insertText`).
                filterText: '$' . $name,
            );
            $completion->textEdit = new TextEdit(
                new Range($anchorStart, $anchorEnd),
                $name,
            );
            $items[] = $completion;
        }
        return $items;
    }

    /**
     * Build the scope chain (outermost -> innermost) for `$byteOffset`.
     * `null` represents the script's top-level scope.  Each non-null
     * entry is the scope node (Function_/ClassMethod/Closure/ArrowFunction)
     * whose `[startFilePos..endFilePos]` covers the cursor.
     *
     * @param list<Node\Stmt> $ast
     * @return list<Function_|ClassMethod|Closure|ArrowFunction|null>
     */
    private static function scopeChainAt(array $ast, int $byteOffset): array
    {
        /** @var list<Function_|ClassMethod|Closure|ArrowFunction> $covering */
        $covering = [];
        $visitor = new class($byteOffset, $covering) extends NodeVisitorAbstract {
            /** @param list<Function_|ClassMethod|Closure|ArrowFunction> $covering */
            public function __construct(
                private readonly int $cursor,
                private array &$covering,
            ) {
            }

            public function enterNode(Node $node): null
            {
                if (!$node instanceof Function_
                    && !$node instanceof ClassMethod
                    && !$node instanceof Closure
                    && !$node instanceof ArrowFunction
                ) {
                    return null;
                }
                $start = $node->getStartFilePos();
                $end = $node->getEndFilePos();
                if ($start === -1 || $end === -1) {
                    return null;
                }
                if ($this->cursor >= $start && $this->cursor <= $end + 1) {
                    $this->covering[] = $node;
                }
                return null;
            }
        };
        $traverser = new NodeTraverser();
        $traverser->addVisitor($visitor);
        $traverser->traverse($ast);

        // Sort by innermost (largest start, smallest end).  Simple sort by
        // startFilePos ascending puts ancestors first.
        usort($covering, static fn ($a, $b) => $a->getStartFilePos() <=> $b->getStartFilePos());
        return array_merge([null], $covering);
    }

    /**
     * Collect variables introduced in a scope's own body: its params,
     * its `use (...)` captures (for closures), and assignments / foreach
     * / closure-use within the body that don't descend into a nested
     * scope.
     *
     * @param array<string, true> $names  out-param accumulator
     */
    private static function collectScopeOwnVariables(
        Function_|ClassMethod|Closure|ArrowFunction $scope,
        array &$names,
    ): void {
        foreach ($scope->params as $param) {
            if ($param->var instanceof Variable && is_string($param->var->name)) {
                $names[$param->var->name] = true;
            }
        }
        if ($scope instanceof Closure) {
            foreach ($scope->uses as $use) {
                if ($use->var instanceof Variable && is_string($use->var->name)) {
                    $names[$use->var->name] = true;
                }
            }
        }
        $body = $scope instanceof ArrowFunction ? [$scope->expr] : ($scope->stmts ?? []);
        if (is_array($body)) {
            self::collectScopeBodyVariables($body, $names);
        }
    }

    /**
     * Walk `$nodes` collecting variable-defining sites, BUT skipping any
     * nested function/method/closure/arrow body so we don't leak names
     * from a sibling scope.  Used both for the script's top-level body
     * and for the body of a specific scope node.
     *
     * @param array<int, Node> $nodes
     * @param array<string, true> $names  out-param accumulator
     */
    private static function collectScopeBodyVariables(array $nodes, array &$names): void
    {
        $collector = new class($names) extends NodeVisitorAbstract {
            /** @param array<string, true> $names */
            public function __construct(private array &$names)
            {
            }

            public function enterNode(Node $node): null|int
            {
                if ($node instanceof Function_
                    || $node instanceof ClassMethod
                    || $node instanceof Closure
                    || $node instanceof ArrowFunction
                ) {
                    return NodeTraverser::DONT_TRAVERSE_CHILDREN;
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
                }
                return null;
            }
        };
        $traverser = new NodeTraverser();
        $traverser->addVisitor($collector);
        $traverser->traverse($nodes);
    }

    /**
     * @return list<CompletionItem>
     */
    private function completeClassesByPrefix(string $prefix, ClassNameImportContext $importContext): array
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
                // Scope-aware insertText: bare short name when the FQN is
                // already imported (or same-namespace), leading-backslash
                // FQ otherwise. Prevents the qualified-but-not-FQ form
                // (e.g. inserting `App\Models\Tag` inside `namespace App\Demos`)
                // from namespace-prepending to a non-existent class.
                insertText: $importContext->chooseInsertText($fqn),
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

    private function propertyItem($property, bool $forStaticProp = false, ?Range $textEditRange = null): CompletionItem
    {
        $type = $this->genericParams->prettify((string) $property->inferredType());
        $name = $property->name();
        if ($forStaticProp) {
            // For `Cls::$|`:
            //  - label carries the `$` so PhpStorm's popup-filter accepts
            //    items matching the user's typed `$` prefix.  (Without it,
            //    every item is dropped silently -- diagnosed via prod log
            //    id=6 of xphp-20260525-171654-492.log.)
            //  - textEdit pins the replacement range explicitly so
            //    PhpStorm doesn't extend the range backwards through the
            //    `$` and swallow it on accept (which produced
            //    `Stats::name` instead of `Stats::$name` in prod log
            //    id=28 of xphp-20260525-172338-536.log).
            //  - newText is the bare property name; the `$` already in
            //    source survives because the textEdit's range starts AT
            //    or AFTER the `$`.
            $item = new CompletionItem(
                label: '$' . $name,
                kind: CompletionItemKind::PROPERTY,
                detail: $type !== '' && $type !== '<missing>' ? $type : null,
                insertText: $name,
                filterText: '$' . $name,
            );
            if ($textEditRange !== null) {
                $item->textEdit = new TextEdit($textEditRange, $name);
            }
            return $item;
        }
        return new CompletionItem(
            label: $name,
            kind: CompletionItemKind::PROPERTY,
            detail: $type !== '' && $type !== '<missing>' ? $type : null,
            insertText: $name,
        );
    }

    private static function isVisibleFromCaller(Visibility $visibility, bool $isSameClass, bool $isSubclass): bool
    {
        if ($visibility->isPublic()) {
            return true;
        }
        if ($isSameClass) {
            return true;
        }
        // Private stays gated to the declaring class.  Protected leaks
        // one level: subclasses see their ancestors' protected members.
        if ($isSubclass && !$visibility->isPrivate()) {
            return true;
        }
        return false;
    }

    /**
     * "Does `$callerFqn` extend or implement `$receiverFqn`?"  Used by
     * member-completion to decide whether protected members of the
     * receiver class should surface at the cursor.  Delegates to
     * worse-reflection's `isInstanceOf` which walks the entire ancestor
     * chain (parents + interfaces, transitive).
     */
    private function isSubclassOf(string $callerFqn, string $receiverFqn): bool
    {
        $callerNorm = ltrim($callerFqn, '\\');
        $receiverNorm = ltrim($receiverFqn, '\\');
        if ($callerNorm === '' || $receiverNorm === '' || $callerNorm === $receiverNorm) {
            return false;
        }
        try {
            $class = $this->reflector->reflectClassLike($callerNorm);
            return $class->isInstanceOf(\Phpactor\WorseReflection\Core\ClassName::fromString($receiverNorm));
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Walk the document AST and return the FQN of the innermost
     * ClassLike whose `[startFilePos..endFilePos]` covers `$byteOffset`,
     * or null if the cursor isn't inside a class body.
     *
     * Used by member-completion to know whether the caller is inside
     * the receiver's class -- the gate for surfacing private +
     * protected members.
     */
    private function enclosingClassFqnAt(string $uri, int $byteOffset): ?string
    {
        $item = $this->workspace->has($uri) ? $this->workspace->get($uri) : null;
        if ($item === null) {
            return null;
        }
        $result = $this->cache->getOrParse($uri, $item->version, $item->text);
        $ast = $result->ast;
        if ($ast === null) {
            // Cache miss: the cached parse failed because the document is
            // syntactically mid-edit (a common case for completion -- the
            // user typed `$t` or similar that breaks the surrounding
            // statement).  Fall back to the tolerant parser so we still
            // recover the enclosing class.  Don't cache this result --
            // the regular `getOrParse` cache stays the source of truth
            // for non-completion callers.
            $ast = $this->parser->parseTolerant($item->text);
            if ($ast === null) {
                return null;
            }
        }
        // The receiverProbe came from the stripped-source byte offset
        // (worse-reflection already operates on stripped source), so the
        // AST positions match without any ByteOffsetMap translation.
        $visitor = new class($byteOffset) extends NodeVisitorAbstract {
            public ?string $bestFqn = null;
            public int $bestRange = PHP_INT_MAX;
            private string $currentNamespace = '';

            public function __construct(private readonly int $offset)
            {
            }

            public function enterNode(Node $node): null
            {
                if ($node instanceof Namespace_) {
                    $this->currentNamespace = $node->name?->toString() ?? '';
                    return null;
                }
                if (!$node instanceof ClassLike || $node->name === null) {
                    return null;
                }
                $start = $node->getStartFilePos();
                $end = $node->getEndFilePos();
                if ($start < 0 || $end < 0 || $this->offset < $start || $this->offset > $end) {
                    return null;
                }
                $range = $end - $start;
                if ($range >= $this->bestRange) {
                    return null;
                }
                $short = $node->name->toString();
                $this->bestFqn = $this->currentNamespace !== ''
                    ? $this->currentNamespace . '\\' . $short
                    : $short;
                $this->bestRange = $range;
                return null;
            }
        };

        $traverser = new NodeTraverser();
        $traverser->addVisitor($visitor);
        $traverser->traverse($ast);
        return $visitor->bestFqn;
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
        Stderr::write('[xphp-lsp completion] ' . $message . "\n");
    }

    private static function oneLine(string $message): string
    {
        return str_replace(["\r", "\n"], ' ', $message);
    }
}
