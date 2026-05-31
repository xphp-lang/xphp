<?php

declare(strict_types=1);

namespace XPHP\Lsp\Handler;

use Amp\Promise;
use Amp\Success;
use PhpParser\ErrorHandler\Collecting;
use PhpParser\Node;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\Node\Stmt\Namespace_;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use Phpactor\LanguageServer\Core\Handler\CanRegisterCapabilities;
use Phpactor\LanguageServer\Core\Handler\Handler;
use Phpactor\LanguageServer\Core\Workspace\Workspace as PhpactorWorkspace;
use Phpactor\LanguageServerProtocol\ImplementationParams;
use Phpactor\LanguageServerProtocol\Location;
use Phpactor\LanguageServerProtocol\Position;
use Phpactor\LanguageServerProtocol\Range;
use Phpactor\LanguageServerProtocol\ServerCapabilities;
use Throwable;
use XPHP\Lsp\Analyzer\ParsedDocumentCache;
use XPHP\Lsp\PositionMap;
use XPHP\Lsp\Reflection\FqnIndex;
use XPHP\Transpiler\Monomorphize\XphpSourceParser;

/**
 * `textDocument/implementation` -- list classes that implement an
 * interface or extend an abstract / concrete class, from the cursor.
 *
 * MVP scope: ClassLike cursor positions only.  Cursor on
 *   - `interface I {}` name      → all classes implementing I (directly)
 *   - `class C {}` name           → all classes extending C (directly)
 *   - anywhere else               → null (the client falls back to its
 *                                          default navigation, typically
 *                                          textDocument/definition)
 *
 * Method-level implementation (cursor on an interface-method call,
 * surface every overriding method) is a follow-up.  Today the user
 * still gets the right outcome by calling `textDocument/references`
 * which already walks interface-implementation chains both ways.
 *
 * The walk is one-hop (direct implementers / direct subclasses).
 * Transitive descendants surface when the user invokes the action
 * again on a returned item.  This mirrors PhpStorm's own
 * "Implementations" gesture on PHP.
 */
final class XphpImplementationHandler implements Handler, CanRegisterCapabilities
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
        return ['textDocument/implementation' => 'implementation'];
    }

    public function registerCapabiltiies(ServerCapabilities $capabilities): void
    {
        $capabilities->implementationProvider = true;
    }

    /**
     * @return Promise<list<Location>>
     */
    public function implementation(ImplementationParams $params): Promise
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

        $targetFqn = self::findClassLikeFqnAt($result->ast, $offset);
        if ($targetFqn === null) {
            return new Success([]);
        }

        $locations = [];
        $seen = [];
        $this->forEachClassLikeInWorkspace(
            static function (string $uri, ClassLike $node, string $source, string $ownFqn) use (
                $targetFqn,
                &$locations,
                &$seen,
            ): void {
                if (!self::extendsOrImplementsDirectly($node, $targetFqn)) {
                    return;
                }
                $key = $uri . '|' . $ownFqn;
                if (isset($seen[$key])) {
                    return;
                }
                $seen[$key] = true;
                $location = self::buildLocation($uri, $node, $source);
                if ($location !== null) {
                    $locations[] = $location;
                }
            },
        );
        return new Success($locations);
    }

    /**
     * Find the ClassLike whose name token contains $offset and
     * return its FQN.  Returns null when the cursor is not on a
     * ClassLike's name token.
     *
     * @param list<Node\Stmt> $ast
     */
    private static function findClassLikeFqnAt(array $ast, int $offset): ?string
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
            $namespace = self::namespaceFromAst($ast, $node);
            $shortName = (string) $node->name;
            return $namespace !== '' ? $namespace . '\\' . $shortName : $shortName;
        }
        return null;
    }

    /**
     * Walk every open document AND every filesystem-indexed path,
     * calling $callback with {uri, ClassLike, source, ownFqn} per
     * ClassLike encountered.  Mirrors the same walk
     * XphpTypeHierarchyHandler uses for `subtypes`; extracted inline
     * here (rather than into a shared service) because it's a
     * 30-line helper and the two handlers' semantics may diverge.
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

    private static function nameOf(Node\Name $name): string
    {
        $resolved = $name->getAttribute('resolvedName');
        if ($resolved instanceof Node\Name) {
            return ltrim($resolved->toString(), '\\');
        }
        return ltrim($name->toString(), '\\');
    }

    /**
     * Build a Location pointing at the ClassLike's name token (so
     * PhpStorm highlights the class name, not the whole class
     * body, when the user clicks an entry in the implementations
     * popup).
     */
    private static function buildLocation(string $uri, ClassLike $node, string $source): ?Location
    {
        if ($node->name === null) {
            return null;
        }
        $start = $node->name->getStartFilePos();
        $end = $node->name->getEndFilePos();
        if ($start < 0 || $end < 0) {
            return null;
        }
        $positionMap = new PositionMap($source);
        [$sl, $sc] = $positionMap->offsetToPosition($start);
        [$el, $ec] = $positionMap->offsetToPosition($end + 1);
        return new Location($uri, new Range(new Position($sl, $sc), new Position($el, $ec)));
    }

    /**
     * @param list<Node\Stmt> $ast
     */
    private static function namespaceFromAst(array $ast, ClassLike $target): string
    {
        foreach ($ast as $stmt) {
            if ($stmt instanceof Namespace_) {
                foreach ($stmt->stmts as $inner) {
                    if ($inner === $target) {
                        return $stmt->name !== null ? $stmt->name->toString() : '';
                    }
                }
            }
        }
        return '';
    }

    /**
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
