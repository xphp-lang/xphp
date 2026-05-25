<?php

declare(strict_types=1);

namespace XPHP\Lsp\Resolver;

use PhpParser\Node;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\Instanceof_;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Expr\StaticPropertyFetch;
use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\Node\Stmt\Function_;
use PhpParser\Node\Stmt\Namespace_;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\NodeVisitorAbstract;
use Phpactor\LanguageServer\Core\Workspace\Workspace as PhpactorWorkspace;
use Phpactor\LanguageServerProtocol\Location;
use Phpactor\LanguageServerProtocol\Position;
use Phpactor\LanguageServerProtocol\Range;
use Throwable;
use XPHP\Lsp\Analyzer\ParsedDocumentCache;
use XPHP\Lsp\PositionMap;
use XPHP\Lsp\Reflection\FqnIndex;
use XPHP\Transpiler\Monomorphize\ByteOffsetMap;
use XPHP\Transpiler\Monomorphize\XphpSourceParser;

/**
 * Workspace-wide find-references engine for `textDocument/references`.
 *
 * Identifies the symbol at the cursor (a class or top-level function),
 * then sweeps every indexed file (open docs + filesystem) collecting
 * Name nodes and call sites that resolve to the same FQN.
 *
 * MVP scope:
 *   - Class references: `new Foo()`, `Foo::method()`, `Foo::CONST`,
 *     `Foo::$prop`, `instanceof Foo`, `extends Foo`, `implements Foo`,
 *     parameter / return / property type hints.
 *   - Function references: `foo(...)` calls.
 *
 * Deferred:
 *   - Method references (`$x->method()`).  Require type inference at
 *     each call site; symmetric to property refs.
 *   - Property references (`$x->prop`).  Same.
 *   - Use-statement references (`use Foo;`).  Could be added cheaply,
 *     but PhpStorm's UI typically de-emphasises these so the cost/
 *     benefit is borderline.
 */
final class ReferenceFinder
{
    public function __construct(
        private readonly PhpactorWorkspace $workspace,
        private readonly ParsedDocumentCache $cache,
        private readonly FqnIndex $fqnIndex,
        private readonly XphpSourceParser $parser,
    ) {
    }

    /**
     * @return list<Location>
     */
    public function findReferences(string $uri, int $byteOffset, bool $includeDeclaration): array
    {
        $target = $this->resolveTargetAt($uri, $byteOffset);
        if ($target === null) {
            return [];
        }

        $locations = [];
        $seenUris = [];

        // Open-doc pass: live state beats on-disk.
        foreach ($this->workspace as $docUri => $item) {
            $seenUris[(string) $docUri] = true;
            $result = $this->cache->getOrParse((string) $docUri, $item->version, $item->text);
            $ast = $result->ast;
            $offsets = $result->byteOffsetMap;
            if ($ast === null) {
                $parsed = $this->parser->parseTolerantWithMap($item->text);
                if ($parsed === null) {
                    continue;
                }
                $ast = $parsed->ast;
                $offsets = $parsed->byteOffsetMap;
            }
            foreach (self::collectReferences($ast, $target) as $hit) {
                $locations[] = $this->buildLocation((string) $docUri, $item->text, $offsets, $hit);
            }
        }

        // Filesystem pass: parse on demand, skipping any URI the workspace
        // already covered (open-doc precedence).
        foreach ($this->fqnIndex->indexedFilesystemPaths() as $path) {
            $fsUri = 'file://' . $path;
            if (isset($seenUris[$fsUri])) {
                continue;
            }
            $source = @file_get_contents($path);
            if ($source === false) {
                continue;
            }
            try {
                $parsed = $this->parser->parseTolerantWithMap($source);
            } catch (Throwable) {
                continue;
            }
            if ($parsed === null) {
                continue;
            }
            foreach (self::collectReferences($parsed->ast, $target) as $hit) {
                $locations[] = $this->buildLocation($fsUri, $source, $parsed->byteOffsetMap, $hit);
            }
        }

        if (!$includeDeclaration) {
            $locations = array_values(array_filter(
                $locations,
                fn (Location $l): bool => !self::isDeclarationLocation($l, $target),
            ));
        }

        return $locations;
    }

    /**
     * Identify what the user clicked on and produce a {kind, fqn} target.
     * Returns null when the cursor isn't on a referenceable symbol.
     *
     * @return array{kind: 'class'|'function', fqn: string, declUri?: string, declLine?: int, declChar?: int}|null
     */
    private function resolveTargetAt(string $uri, int $byteOffset): ?array
    {
        if (!$this->workspace->has($uri)) {
            return null;
        }
        $item = $this->workspace->get($uri);
        $result = $this->cache->getOrParse($uri, $item->version, $item->text);
        $ast = $result->ast;
        $offsets = $result->byteOffsetMap;
        if ($ast === null) {
            $parsed = $this->parser->parseTolerantWithMap($item->text);
            if ($parsed === null) {
                return null;
            }
            $ast = $parsed->ast;
            $offsets = $parsed->byteOffsetMap;
        }

        // Run NameResolver to populate `resolvedName` on Name nodes and
        // `namespacedName` on declarations.  `replaceNodes: false`
        // preserves the original positions for cursor matching.
        $ast = self::cloneWithResolvedNames($ast);

        $finder = new NodeFinder();
        // Pick the smallest node covering the offset.
        $best = null;
        $bestRange = PHP_INT_MAX;
        foreach ($finder->find($ast, static fn (Node $n): bool => true) as $node) {
            $start = $node->getStartFilePos();
            $end = $node->getEndFilePos();
            if ($start < 0 || $end < 0) {
                continue;
            }
            if ($byteOffset < $start || $byteOffset > $end) {
                continue;
            }
            $range = $end - $start;
            if ($range < $bestRange) {
                $best = $node;
                $bestRange = $range;
            }
        }
        if ($best === null) {
            return null;
        }

        // Class declaration: cursor on `class Foo`.
        if ($best instanceof ClassLike && $best->name !== null) {
            $fqn = isset($best->namespacedName)
                ? $best->namespacedName->toString()
                : $best->name->toString();
            return [
                'kind' => 'class',
                'fqn' => $fqn,
                'declUri' => $uri,
                'declLine' => self::lineCharFromOffset($item->text, $offsets->toOriginal($best->name->getStartFilePos()))[0],
                'declChar' => self::lineCharFromOffset($item->text, $offsets->toOriginal($best->name->getStartFilePos()))[1],
            ];
        }

        // Function declaration: cursor on `function foo()`.
        if ($best instanceof Function_) {
            $fqn = isset($best->namespacedName)
                ? $best->namespacedName->toString()
                : $best->name->toString();
            return [
                'kind' => 'function',
                'fqn' => $fqn,
                'declUri' => $uri,
                'declLine' => self::lineCharFromOffset($item->text, $offsets->toOriginal($best->name->getStartFilePos()))[0],
                'declChar' => self::lineCharFromOffset($item->text, $offsets->toOriginal($best->name->getStartFilePos()))[1],
            ];
        }

        // Identifier of a ClassLike's name token (cursor exactly on the
        // name, which NodeFinder may pick over the surrounding ClassLike).
        if ($best instanceof Node\Identifier) {
            $parent = self::findParentOfIdentifier($ast, $best);
            if ($parent instanceof ClassLike && $parent->name === $best) {
                $fqn = isset($parent->namespacedName)
                    ? $parent->namespacedName->toString()
                    : $best->toString();
                return [
                    'kind' => 'class',
                    'fqn' => $fqn,
                    'declUri' => $uri,
                    'declLine' => self::lineCharFromOffset($item->text, $offsets->toOriginal($best->getStartFilePos()))[0],
                    'declChar' => self::lineCharFromOffset($item->text, $offsets->toOriginal($best->getStartFilePos()))[1],
                ];
            }
            if ($parent instanceof Function_ && $parent->name === $best) {
                $fqn = isset($parent->namespacedName)
                    ? $parent->namespacedName->toString()
                    : $best->toString();
                return [
                    'kind' => 'function',
                    'fqn' => $fqn,
                    'declUri' => $uri,
                    'declLine' => self::lineCharFromOffset($item->text, $offsets->toOriginal($best->getStartFilePos()))[0],
                    'declChar' => self::lineCharFromOffset($item->text, $offsets->toOriginal($best->getStartFilePos()))[1],
                ];
            }
        }

        // Name reference: resolvedName attribute carries the FQN after
        // NameResolver has run.
        if ($best instanceof Name) {
            $resolved = $best->getAttribute('resolvedName');
            if ($resolved instanceof Name) {
                $fqn = $resolved->toString();
                // Distinguish function names from class names by checking
                // the parent context.  FuncCall.name -> function;
                // everything else -> class.
                $kind = self::isFunctionNameContext($ast, $best) ? 'function' : 'class';
                return ['kind' => $kind, 'fqn' => $fqn];
            }
            // No resolvedName -> a fully-qualified leading `\Foo` that the
            // resolver passed through unchanged.  Use the literal.
            $fqn = ltrim($best->toString(), '\\');
            $kind = self::isFunctionNameContext($ast, $best) ? 'function' : 'class';
            return ['kind' => $kind, 'fqn' => $fqn];
        }

        return null;
    }

    /**
     * @param list<Node\Stmt> $ast
     * @return iterable<array{node: Node, kind: 'class'|'function'}>
     */
    private static function collectReferences(array $ast, array $target): iterable
    {
        $ast = self::cloneWithResolvedNames($ast);

        $finder = new NodeFinder();
        $targetFqn = ltrim($target['fqn'], '\\');

        foreach ($finder->find($ast, static fn (Node $n): bool => true) as $node) {
            if ($target['kind'] === 'class') {
                if ($node instanceof Name) {
                    if (self::isFunctionNameContext($ast, $node)) {
                        // FuncCall name -- not a class reference.
                        continue;
                    }
                    $resolved = $node->getAttribute('resolvedName');
                    $candidate = $resolved instanceof Name
                        ? $resolved->toString()
                        : ltrim($node->toString(), '\\');
                    if ($candidate === $targetFqn) {
                        yield ['node' => $node, 'kind' => 'class'];
                    }
                    continue;
                }
                if ($node instanceof ClassLike && $node->name !== null) {
                    $declFqn = isset($node->namespacedName)
                        ? $node->namespacedName->toString()
                        : $node->name->toString();
                    if ($declFqn === $targetFqn) {
                        yield ['node' => $node->name, 'kind' => 'class-decl'];
                    }
                }
                continue;
            }
            // Function target.
            if ($node instanceof FuncCall && $node->name instanceof Name) {
                $resolved = $node->name->getAttribute('resolvedName');
                $candidate = $resolved instanceof Name
                    ? $resolved->toString()
                    : ltrim($node->name->toString(), '\\');
                if ($candidate === $targetFqn) {
                    yield ['node' => $node->name, 'kind' => 'function'];
                }
                continue;
            }
            if ($node instanceof Function_) {
                $declFqn = isset($node->namespacedName)
                    ? $node->namespacedName->toString()
                    : $node->name->toString();
                if ($declFqn === $targetFqn) {
                    yield ['node' => $node->name, 'kind' => 'function-decl'];
                }
            }
        }
    }

    /**
     * NameResolver mutates the AST -- to avoid corrupting the
     * ParsedDocumentCache's cached tree, we run it on a clone.  nikic's
     * trees are made of mutable nodes but cloning is cheap relative to
     * the per-call file walks we're already doing.
     *
     * `replaceNodes: false` keeps the original Name nodes (and their
     * positions) intact; resolved FQNs ride along as the `resolvedName`
     * attribute.
     *
     * @param list<Node\Stmt> $ast
     * @return list<Node\Stmt>
     */
    private static function cloneWithResolvedNames(array $ast): array
    {
        // The cheapest way to deep-clone an AST is to serialize-unserialize;
        // php-parser nodes implement neither __clone-deep nor a copy
        // constructor.  serialize() preserves position info on every node.
        $clone = unserialize(serialize($ast));
        $resolver = new NameResolver(null, ['replaceNodes' => false]);
        $traverser = new NodeTraverser();
        $traverser->addVisitor($resolver);
        $traverser->traverse($clone);
        return $clone;
    }

    /**
     * Decide whether a `Name` appears in a position where it names a
     * FUNCTION (only `FuncCall.name`) -- every other position is a class
     * reference (or won't reach this code).  We don't have parent links
     * after NameResolver, so we walk the AST to find the immediate
     * parent.
     *
     * @param list<Node\Stmt> $ast
     */
    private static function isFunctionNameContext(array $ast, Name $name): bool
    {
        $found = false;
        $visitor = new class($name, $found) extends NodeVisitorAbstract {
            public bool $isFunc = false;

            public function __construct(private readonly Name $needle, bool &$found)
            {
            }

            public function enterNode(Node $node): null
            {
                if ($this->isFunc) {
                    return null;
                }
                if ($node instanceof FuncCall && $node->name === $this->needle) {
                    $this->isFunc = true;
                }
                return null;
            }
        };
        $t = new NodeTraverser();
        $t->addVisitor($visitor);
        $t->traverse($ast);
        return $visitor->isFunc;
    }

    /**
     * @param list<Node\Stmt> $ast
     */
    private static function findParentOfIdentifier(array $ast, Node\Identifier $needle): ?Node
    {
        $parent = null;
        $visitor = new class($needle) extends NodeVisitorAbstract {
            public ?Node $parent = null;

            public function __construct(private readonly Node\Identifier $needle)
            {
            }

            public function enterNode(Node $node): null
            {
                if ($this->parent !== null) {
                    return null;
                }
                if ($node instanceof ClassLike && $node->name === $this->needle) {
                    $this->parent = $node;
                }
                if ($node instanceof Function_ && $node->name === $this->needle) {
                    $this->parent = $node;
                }
                return null;
            }
        };
        $t = new NodeTraverser();
        $t->addVisitor($visitor);
        $t->traverse($ast);
        return $visitor->parent;
    }

    private function buildLocation(string $uri, string $source, ByteOffsetMap $offsets, array $hit): Location
    {
        $node = $hit['node'];
        $start = $offsets->toOriginal($node->getStartFilePos());
        $end = $offsets->toOriginal($node->getEndFilePos() + 1);
        if ($start < 0) {
            $start = 0;
        }
        if ($end < $start) {
            $end = $start;
        }
        $map = new PositionMap($source);
        [$startLine, $startChar] = $map->offsetToPosition($start);
        [$endLine, $endChar] = $map->offsetToPosition($end);
        return new Location(
            $uri,
            new Range(
                new Position($startLine, $startChar),
                new Position($endLine, $endChar),
            ),
        );
    }

    /**
     * @return array{0: int, 1: int}
     */
    private static function lineCharFromOffset(string $source, int $byteOffset): array
    {
        return (new PositionMap($source))->offsetToPosition(max(0, $byteOffset));
    }

    /**
     * Exclude the declaration site when context.includeDeclaration=false.
     * Match by (uri, identifier start line/char) so we drop only the
     * exact declaration token, not other coincident class refs.
     *
     * @param array{kind: 'class'|'function', fqn: string, declUri?: string, declLine?: int, declChar?: int} $target
     */
    private static function isDeclarationLocation(Location $loc, array $target): bool
    {
        if (!isset($target['declUri'], $target['declLine'], $target['declChar'])) {
            return false;
        }
        if ($loc->uri !== $target['declUri']) {
            return false;
        }
        return $loc->range->start->line === $target['declLine']
            && $loc->range->start->character === $target['declChar'];
    }
}
