<?php

declare(strict_types=1);

namespace XPHP\Lsp\Handler;

use Amp\Promise;
use Amp\Success;
use PhpParser\ErrorHandler\Collecting;
use PhpParser\Node;
use PhpParser\Node\Identifier;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\Node\Stmt\Namespace_;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use Phpactor\LanguageServer\Core\Handler\CanRegisterCapabilities;
use Phpactor\LanguageServer\Core\Handler\Handler;
use Phpactor\LanguageServer\Core\Workspace\Workspace as PhpactorWorkspace;
use Phpactor\LanguageServerProtocol\ServerCapabilities;
use Phpactor\LanguageServerProtocol\SymbolKind;
use Phpactor\LanguageServerProtocol\TextDocumentPositionParams;
use Throwable;
use XPHP\Lsp\Analyzer\ParsedDocumentCache;
use XPHP\Lsp\PositionMap;
use XPHP\Lsp\Reflection\FqnIndex;
use XPHP\Transpiler\Monomorphize\XphpSourceParser;

/**
 * Type hierarchy.
 *
 * Implements `textDocument/prepareTypeHierarchy`,
 * `typeHierarchy/supertypes`, `typeHierarchy/subtypes` -- LSP 3.17
 * spec.  Cycle H deferred this half because the vendored
 * `phpactor/language-server-protocol` (v3.5) lacks the typed
 * `TypeHierarchyItem` / `TypeHierarchyPrepareParams` classes.  The
 * handler therefore returns raw associative arrays in the
 * documented LSP shape -- the phpactor serializer accepts these,
 * matching the same pattern XphpCallHierarchyHandler uses for its
 * raw-array params on `prepareCallHierarchy`.
 *
 * - **Prepare**: locate the ClassLike at the cursor.  Emit one
 *   TypeHierarchyItem carrying the resolved FQN in `data['fqn']`
 *   so subsequent supertypes / subtypes calls can resolve without
 *   re-running NameResolver.
 *
 * - **Supertypes**: read the target ClassLike's direct `extends` +
 *   `implements` clauses (one hop -- the client recurses through
 *   each returned item for deeper ancestry).  Resolve each name to
 *   an FQN via the file's use map; find the declaring file via
 *   FqnIndex + the workspace; emit one item per parent.
 *
 * - **Subtypes**: walk every open document AND filesystem-indexed
 *   document for ClassLike nodes whose `extends` or `implements`
 *   resolves to the target FQN.  One hop only; client recurses.
 */
final class XphpTypeHierarchyHandler implements Handler, CanRegisterCapabilities
{
    public function __construct(
        private readonly PhpactorWorkspace $workspace,
        private readonly ParsedDocumentCache $cache,
        private readonly XphpSourceParser $parser,
        private readonly FqnIndex $fqnIndex,
    ) {
    }

    public function methods(): array
    {
        return [
            'textDocument/prepareTypeHierarchy' => 'prepare',
            'typeHierarchy/supertypes' => 'supertypes',
            'typeHierarchy/subtypes' => 'subtypes',
        ];
    }

    public function registerCapabiltiies(ServerCapabilities $capabilities): void
    {
        $capabilities->typeHierarchyProvider = true;
    }

    /**
     * `prepareTypeHierarchy` params are `{textDocument, position}`,
     * the same shape `TextDocumentPositionParams` describes.  Using
     * the typed class lets phpactor's `LanguageSeverProtocolParamsResolver`
     * deserialize the JSON into real `TextDocumentIdentifier` /
     * `Position` instances and pass them as a single typed arg --
     * the framework's PassThroughArgumentResolver splats raw arrays,
     * so an untyped `array $params` would only receive the
     * textDocument value (not the full params), and the handler
     * would silently return empty.
     *
     * @return Promise<list<array<string, mixed>>>
     */
    public function prepare(TextDocumentPositionParams $params): Promise
    {
        $uri = $params->textDocument->uri;
        if (!$this->workspace->has($uri)) {
            return new Success([]);
        }
        $item = $this->workspace->get($uri);
        $result = $this->cache->getOrParse($uri, $item->version, $item->text);
        if ($result->ast === null || $result->ast === []) {
            return new Success([]);
        }
        $positionMap = new PositionMap($item->text);
        $offset = $positionMap->positionToOffset(
            $params->position->line,
            $params->position->character,
        );

        $located = self::findClassLikeAt($result->ast, $offset);
        if ($located === null) {
            return new Success([]);
        }
        [$classLike, $namespace] = $located;
        return new Success([
            self::buildItem($uri, $classLike, $positionMap, $namespace),
        ]);
    }

    /**
     * `typeHierarchy/supertypes` params are `{item}`.  The framework
     * splats the params object into positional args, so the first
     * positional argument is the inner `item` dict -- NOT a wrapper.
     * Signature reflects that splat order.
     *
     * @param array<string, mixed> $item the inner TypeHierarchyItem dict
     * @return Promise<list<array<string, mixed>>>
     */
    public function supertypes(array $item): Promise
    {
        $targetFqn = $item['data']['fqn'] ?? null;
        if (!is_string($targetFqn) || $targetFqn === '') {
            return new Success([]);
        }
        $located = $this->locateClassLike($targetFqn);
        if ($located === null) {
            return new Success([]);
        }
        [, $classLike] = $located;
        $supertypeFqns = self::collectSupertypeFqns($classLike);
        $items = [];
        foreach ($supertypeFqns as $fqn) {
            $located = $this->locateClassLike($fqn);
            if ($located === null) {
                continue;
            }
            [$uri, $node, $source] = $located;
            $items[] = self::buildItem(
                $uri,
                $node,
                new PositionMap($source),
                self::namespaceOfFqn($fqn),
            );
        }
        return new Success($items);
    }

    /**
     * `typeHierarchy/subtypes` -- same splat shape as supertypes.
     *
     * @param array<string, mixed> $item the inner TypeHierarchyItem dict
     * @return Promise<list<array<string, mixed>>>
     */
    public function subtypes(array $item): Promise
    {
        $targetFqn = $item['data']['fqn'] ?? null;
        if (!is_string($targetFqn) || $targetFqn === '') {
            return new Success([]);
        }
        $items = [];
        $seenUriFqn = [];
        $this->forEachClassLikeInWorkspace(
            static function (string $uri, ClassLike $node, string $source, string $ownFqn) use (
                $targetFqn,
                &$items,
                &$seenUriFqn,
            ): void {
                if (!self::extendsOrImplementsDirectly($node, $targetFqn)) {
                    return;
                }
                $key = $uri . '|' . $ownFqn;
                if (isset($seenUriFqn[$key])) {
                    return;
                }
                $seenUriFqn[$key] = true;
                $items[] = self::buildItem(
                    $uri,
                    $node,
                    new PositionMap($source),
                    self::namespaceOfFqn($ownFqn),
                );
            },
        );
        return new Success($items);
    }

    /**
     * Find the ClassLike whose name token contains the offset.
     *
     * @param list<Node\Stmt> $ast
     * @return array{0: ClassLike, 1: string}|null tuple of {classLike, namespace}
     */
    private static function findClassLikeAt(array $ast, int $offset): ?array
    {
        $finder = new NodeFinder();
        foreach ($finder->find($ast, static fn (Node $n): bool => $n instanceof ClassLike) as $node) {
            if ($node->name === null) {
                continue;
            }
            $start = $node->name->getStartFilePos();
            $end = $node->name->getEndFilePos();
            if ($start < 0 || $end < 0) {
                continue;
            }
            if ($offset < $start || $offset > $end) {
                continue;
            }
            return [$node, self::namespaceFromAst($ast, $node)];
        }
        return null;
    }

    /**
     * Build a raw-array TypeHierarchyItem from a ClassLike node.
     *
     * @return array<string, mixed>
     */
    private static function buildItem(
        string $uri,
        ClassLike $node,
        PositionMap $positionMap,
        string $namespace,
    ): array {
        $rangeStart = $node->getStartFilePos();
        $rangeEnd = $node->getEndFilePos();
        $nameNode = $node->name;
        $selStart = $nameNode?->getStartFilePos() ?? $rangeStart;
        $selEnd = $nameNode?->getEndFilePos() ?? $rangeEnd;
        [$rsl, $rsc] = $positionMap->offsetToPosition($rangeStart);
        [$rel, $rec] = $positionMap->offsetToPosition($rangeEnd + 1);
        [$ssl, $ssc] = $positionMap->offsetToPosition($selStart);
        [$sel, $sec] = $positionMap->offsetToPosition($selEnd + 1);
        $shortName = $nameNode !== null ? (string) $nameNode : '';
        $fqn = $namespace !== '' ? $namespace . '\\' . $shortName : $shortName;
        return [
            'name' => $shortName,
            'kind' => self::symbolKind($node),
            'uri' => $uri,
            'range' => [
                'start' => ['line' => $rsl, 'character' => $rsc],
                'end' => ['line' => $rel, 'character' => $rec],
            ],
            'selectionRange' => [
                'start' => ['line' => $ssl, 'character' => $ssc],
                'end' => ['line' => $sel, 'character' => $sec],
            ],
            'data' => ['fqn' => $fqn],
        ];
    }

    /**
     * Extract every FQN named in $node's `extends` / `implements`
     * clauses, resolved via NameResolver-stamped attributes.
     *
     * @return list<string>
     */
    private static function collectSupertypeFqns(ClassLike $node): array
    {
        $fqns = [];
        // Class: extends (single), implements (many)
        if ($node instanceof Node\Stmt\Class_) {
            if ($node->extends !== null) {
                $resolved = self::nameOf($node->extends);
                if ($resolved !== '') {
                    $fqns[] = $resolved;
                }
            }
            foreach ($node->implements as $iface) {
                $resolved = self::nameOf($iface);
                if ($resolved !== '') {
                    $fqns[] = $resolved;
                }
            }
        }
        // Interface: extends (many)
        if ($node instanceof Node\Stmt\Interface_) {
            foreach ($node->extends as $iface) {
                $resolved = self::nameOf($iface);
                if ($resolved !== '') {
                    $fqns[] = $resolved;
                }
            }
        }
        return array_values(array_unique($fqns));
    }

    /**
     * Read the resolvedName attribute (set by NameResolver) or fall
     * back to toString() on the Name node.  Trims leading `\` so the
     * result matches our internal FQN convention.
     */
    private static function nameOf(Node\Name $name): string
    {
        $resolved = $name->getAttribute('resolvedName');
        if ($resolved instanceof Node\Name) {
            return ltrim($resolved->toString(), '\\');
        }
        return ltrim($name->toString(), '\\');
    }

    /**
     * Check whether $node directly extends or implements $targetFqn
     * (one hop -- no recursion through ancestors).
     */
    private static function extendsOrImplementsDirectly(ClassLike $node, string $targetFqn): bool
    {
        $target = ltrim($targetFqn, '\\');
        if ($node instanceof Node\Stmt\Class_) {
            if ($node->extends !== null && self::nameOf($node->extends) === $target) {
                return true;
            }
            foreach ($node->implements as $iface) {
                if (self::nameOf($iface) === $target) {
                    return true;
                }
            }
        }
        if ($node instanceof Node\Stmt\Interface_) {
            foreach ($node->extends as $iface) {
                if (self::nameOf($iface) === $target) {
                    return true;
                }
            }
        }
        return false;
    }

    private static function symbolKind(ClassLike $node): int
    {
        // Classes AND traits both map to SymbolKind::CLASS_ -- LSP
        // doesn't have a separate trait kind and PhpStorm renders
        // both with the class icon.  Only interfaces get their own
        // kind.
        return $node instanceof Node\Stmt\Interface_ ? SymbolKind::INTERFACE : SymbolKind::CLASS_;
    }

    /**
     * Resolve a FQN to {uri, ClassLike, source}.  Walks open
     * documents first (so live edits trump the on-disk version),
     * then falls back to filesystem-indexed paths via FqnIndex.
     *
     * @return array{0: string, 1: ClassLike, 2: string}|null
     */
    private function locateClassLike(string $fqn): ?array
    {
        $needle = ltrim($fqn, '\\');
        if ($needle === '') {
            return null;
        }
        foreach ($this->workspace as $uri => $item) {
            $result = $this->cache->getOrParse((string) $uri, $item->version, $item->text);
            if ($result->ast === null) {
                continue;
            }
            $resolvedAst = self::cloneWithResolvedNames($result->ast);
            $hit = self::findClassLikeByFqn($resolvedAst, $needle);
            if ($hit !== null) {
                return [(string) $uri, $hit, $item->text];
            }
        }
        $path = $this->fqnIndex->pathFor($needle);
        if ($path === null) {
            return null;
        }
        try {
            $source = file_get_contents($path);
        } catch (Throwable) {
            return null;
        }
        if ($source === false) {
            return null;
        }
        $parsed = $this->parser->parseTolerant($source);
        if ($parsed === null) {
            return null;
        }
        $resolvedAst = self::cloneWithResolvedNames($parsed);
        $hit = self::findClassLikeByFqn($resolvedAst, $needle);
        if ($hit === null) {
            return null;
        }
        return ['file://' . $path, $hit, $source];
    }

    /**
     * Walk every open document and every filesystem-indexed path,
     * calling $callback with {uri, ClassLike, source, ownFqn} per
     * ClassLike encountered.  Used by `subtypes` to scan for nodes
     * whose extends/implements match a target FQN.
     *
     * @param callable(string $uri, ClassLike $node, string $source, string $ownFqn): void $callback
     */
    private function forEachClassLikeInWorkspace(callable $callback): void
    {
        $seenUris = [];
        foreach ($this->workspace as $uri => $item) {
            $uriStr = (string) $uri;
            $seenUris[$uriStr] = true;
            $result = $this->cache->getOrParse($uriStr, $item->version, $item->text);
            if ($result->ast === null) {
                continue;
            }
            $resolvedAst = self::cloneWithResolvedNames($result->ast);
            self::visitClassLikes($resolvedAst, $uriStr, $item->text, $callback);
        }
        foreach ($this->fqnIndex->indexedFilesystemPaths() as $path) {
            $uri = 'file://' . $path;
            if (isset($seenUris[$uri])) {
                continue;
            }
            try {
                $source = file_get_contents($path);
            } catch (Throwable) {
                continue;
            }
            if ($source === false) {
                continue;
            }
            $parsed = $this->parser->parseTolerant($source);
            if ($parsed === null) {
                continue;
            }
            $resolvedAst = self::cloneWithResolvedNames($parsed);
            self::visitClassLikes($resolvedAst, $uri, $source, $callback);
        }
    }

    /**
     * @param list<Node\Stmt> $ast
     * @param callable(string $uri, ClassLike $node, string $source, string $ownFqn): void $callback
     */
    private static function visitClassLikes(array $ast, string $uri, string $source, callable $callback): void
    {
        $finder = new NodeFinder();
        foreach ($finder->find($ast, static fn (Node $n): bool => $n instanceof ClassLike) as $node) {
            if ($node->name === null) {
                continue;
            }
            $namespace = self::namespaceFromAst($ast, $node);
            $shortName = (string) $node->name;
            $ownFqn = $namespace !== '' ? $namespace . '\\' . $shortName : $shortName;
            $callback($uri, $node, $source, $ownFqn);
        }
    }

    /**
     * @param list<Node\Stmt> $ast
     */
    private static function findClassLikeByFqn(array $ast, string $needle): ?ClassLike
    {
        $finder = new NodeFinder();
        foreach ($finder->find($ast, static fn (Node $n): bool => $n instanceof ClassLike) as $node) {
            if ($node->name === null) {
                continue;
            }
            $namespace = self::namespaceFromAst($ast, $node);
            $shortName = (string) $node->name;
            $ownFqn = $namespace !== '' ? $namespace . '\\' . $shortName : $shortName;
            if ($ownFqn === $needle) {
                return $node;
            }
        }
        return null;
    }

    /**
     * @param list<Node\Stmt> $ast
     */
    private static function namespaceFromAst(array $ast, ClassLike $target): string
    {
        // ClassLike is inside a Namespace_ or at top-level.  Walk
        // top-level Stmts; if a Namespace_ contains $target in its
        // stmts (deeply), return its name.
        foreach ($ast as $stmt) {
            if ($stmt instanceof Namespace_) {
                if (self::containsNode($stmt, $target)) {
                    return $stmt->name !== null ? $stmt->name->toString() : '';
                }
            }
        }
        return '';
    }

    private static function containsNode(Namespace_ $namespace, ClassLike $target): bool
    {
        foreach ($namespace->stmts as $stmt) {
            if ($stmt === $target) {
                return true;
            }
        }
        return false;
    }

    private static function namespaceOfFqn(string $fqn): string
    {
        $pos = strrpos($fqn, '\\');
        return $pos === false ? '' : substr($fqn, 0, $pos);
    }

    /**
     * Clone the AST + run NameResolver so resolvedName attributes
     * surface on Name nodes (including extends / implements
     * clauses).  Matches the pattern ReferenceFinder uses; the
     * collecting error handler keeps partial-resolution ASTs usable
     * if the source has redundant `use` clauses.
     *
     * @param list<Node\Stmt> $ast
     * @return list<Node\Stmt>
     */
    private static function cloneWithResolvedNames(array $ast): array
    {
        $clone = unserialize(serialize($ast));
        $errorHandler = new Collecting();
        $resolver = new NameResolver($errorHandler, ['replaceNodes' => false]);
        $traverser = new NodeTraverser();
        $traverser->addVisitor($resolver);
        $traverser->traverse($clone);
        return $clone;
    }
}
