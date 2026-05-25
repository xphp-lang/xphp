<?php

declare(strict_types=1);

namespace XPHP\Lsp\Resolver;

use PhpParser\Node;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\Instanceof_;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Expr\NullsafeMethodCall;
use PhpParser\Node\Expr\NullsafePropertyFetch;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Expr\StaticPropertyFetch;
use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Function_;
use PhpParser\Node\Stmt\Namespace_;
use PhpParser\Node\Stmt\Property;
use PhpParser\Node\Stmt\PropertyProperty;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\NodeVisitorAbstract;
use Phpactor\LanguageServer\Core\Workspace\Workspace as PhpactorWorkspace;
use Phpactor\LanguageServerProtocol\Location;
use Phpactor\LanguageServerProtocol\Position;
use Phpactor\LanguageServerProtocol\Range;
use Phpactor\TextDocument\ByteOffset;
use Phpactor\TextDocument\TextDocumentBuilder;
use Phpactor\WorseReflection\Reflector;
use Throwable;
use XPHP\Lsp\Analyzer\ParsedDocumentCache;
use XPHP\Lsp\PositionMap;
use XPHP\Lsp\Reflection\FqnIndex;
use XPHP\Transpiler\Monomorphize\ByteOffsetMap;
use XPHP\Transpiler\Monomorphize\XphpSourceParser;

/**
 * Workspace-wide find-references engine for `textDocument/references`.
 *
 * Identifies the symbol at the cursor (class, top-level function,
 * method, or property), then sweeps every indexed file (open docs +
 * filesystem) collecting Name nodes and call sites that resolve to
 * the same FQN.
 *
 * Scope:
 *   - Class references: `new Foo()`, `Foo::method()`, `Foo::CONST`,
 *     `Foo::$prop`, `instanceof Foo`, `extends Foo`, `implements Foo`,
 *     parameter / return / property type hints, `use Foo;` imports.
 *   - Function references: `foo(...)` calls.
 *   - Method references: `$x->method(...)`, `$x?->method(...)`, and
 *     `Foo::method(...)` where the receiver's inferred class matches.
 *     V1 matches by exact class FQN; subclass-inherited calls aren't
 *     surfaced yet (a follow-up worth the inheritance walk).
 *   - Property references: `$x->prop`, `$x?->prop`, `Foo::$prop`,
 *     same exact-FQN-match rule.
 *
 * Receiver-type inference at each call site uses the same worse-
 * reflection + `GenericResolver` swap completion uses for `$x->|`,
 * so xphp-generic shapes (`Repository<User>`) substitute correctly.
 */
final class ReferenceFinder
{
    public function __construct(
        private readonly PhpactorWorkspace $workspace,
        private readonly ParsedDocumentCache $cache,
        private readonly FqnIndex $fqnIndex,
        private readonly XphpSourceParser $parser,
        private readonly Reflector $reflector,
        private readonly GenericResolver $genericResolver,
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
            foreach ($this->collectReferences($ast, $target, $item->text, (string) $docUri) as $hit) {
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
            foreach ($this->collectReferences($parsed->ast, $target, $source, $fsUri) as $hit) {
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
     * Identify what the user clicked on and produce a target descriptor.
     * Returns null when the cursor isn't on a referenceable symbol.
     *
     * Target shapes:
     *   - {kind:'class',    fqn:'App\Foo'}
     *   - {kind:'function', fqn:'App\foo'}
     *   - {kind:'method',   className:'App\Foo', memberName:'bar'}
     *   - {kind:'property', className:'App\Foo', memberName:'baz'}
     *
     * @return array{kind: string, fqn?: string, className?: string, memberName?: string, declUri?: string, declLine?: int, declChar?: int}|null
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
        // Also handles ClassMethod / Property declarations and member-
        // access call sites.
        if ($best instanceof Identifier) {
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
            if ($parent instanceof ClassMethod && $parent->name === $best) {
                $className = self::enclosingClassFqn($ast, $parent);
                if ($className !== null) {
                    return [
                        'kind' => 'method',
                        'className' => $className,
                        'memberName' => $best->toString(),
                        'declUri' => $uri,
                        'declLine' => self::lineCharFromOffset($item->text, $offsets->toOriginal($best->getStartFilePos()))[0],
                        'declChar' => self::lineCharFromOffset($item->text, $offsets->toOriginal($best->getStartFilePos()))[1],
                    ];
                }
            }
            // Identifier as the name token of a method call: `$x->FOO(...)`
            // or `Foo::BAR(...)`.  Infer the receiver class and treat as
            // a method-ref target.
            if ($parent instanceof MethodCall || $parent instanceof NullsafeMethodCall) {
                if ($parent->name === $best) {
                    $receiverClass = $this->inferReceiverClassAt(
                        $item->text,
                        $uri,
                        max(0, $parent->var->getEndFilePos()),
                    );
                    if ($receiverClass !== null) {
                        return [
                            'kind' => 'method',
                            'className' => $receiverClass,
                            'memberName' => $best->toString(),
                        ];
                    }
                }
            }
            if ($parent instanceof StaticCall && $parent->name === $best) {
                $receiverClass = self::resolvedNameOf($parent->class);
                if ($receiverClass !== null) {
                    return [
                        'kind' => 'method',
                        'className' => $receiverClass,
                        'memberName' => $best->toString(),
                    ];
                }
            }
            if ($parent instanceof PropertyFetch || $parent instanceof NullsafePropertyFetch) {
                if ($parent->name === $best) {
                    $receiverClass = $this->inferReceiverClassAt(
                        $item->text,
                        $uri,
                        max(0, $parent->var->getEndFilePos()),
                    );
                    if ($receiverClass !== null) {
                        return [
                            'kind' => 'property',
                            'className' => $receiverClass,
                            'memberName' => $best->toString(),
                        ];
                    }
                }
            }
            if ($parent instanceof StaticPropertyFetch && $parent->name === $best) {
                $receiverClass = self::resolvedNameOf($parent->class);
                if ($receiverClass !== null) {
                    return [
                        'kind' => 'property',
                        'className' => $receiverClass,
                        'memberName' => $best->toString(),
                    ];
                }
            }
        }

        // VarLikeIdentifier on a Property declaration (PropertyProperty's
        // `name` field is a VarLikeIdentifier in some php-parser versions;
        // Identifier in others -- handle both via parent walk).
        if ($best instanceof Node\VarLikeIdentifier || $best instanceof Identifier) {
            $parent = self::findParentOfIdentifier($ast, $best);
            if ($parent instanceof PropertyProperty && $parent->name === $best) {
                $propertyStmt = self::findEnclosingProperty($ast, $parent);
                if ($propertyStmt !== null) {
                    $className = self::enclosingClassFqn($ast, $propertyStmt);
                    if ($className !== null) {
                        return [
                            'kind' => 'property',
                            'className' => $className,
                            'memberName' => $best->toString(),
                            'declUri' => $uri,
                            'declLine' => self::lineCharFromOffset($item->text, $offsets->toOriginal($best->getStartFilePos()))[0],
                            'declChar' => self::lineCharFromOffset($item->text, $offsets->toOriginal($best->getStartFilePos()))[1],
                        ];
                    }
                }
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
     * @return iterable<array{node: Node, kind: string}>
     */
    private function collectReferences(array $ast, array $target, string $source, string $uri): iterable
    {
        $ast = self::cloneWithResolvedNames($ast);
        $finder = new NodeFinder();

        if ($target['kind'] === 'class' || $target['kind'] === 'function') {
            $targetFqn = ltrim((string) $target['fqn'], '\\');
            foreach ($finder->find($ast, static fn (Node $n): bool => true) as $node) {
                if ($target['kind'] === 'class') {
                    if ($node instanceof Name) {
                        if (self::isFunctionNameContext($ast, $node)) {
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
            return;
        }

        // Member target: method or property.
        $targetClass = ltrim((string) $target['className'], '\\');
        $targetName = (string) $target['memberName'];

        foreach ($finder->find($ast, static fn (Node $n): bool => true) as $node) {
            if ($target['kind'] === 'method') {
                if (($node instanceof MethodCall || $node instanceof NullsafeMethodCall)
                    && $node->name instanceof Identifier
                    && $node->name->toString() === $targetName
                ) {
                    $receiver = $this->inferReceiverClassAt(
                        $source,
                        $uri,
                        max(0, $node->var->getEndFilePos()),
                    );
                    if ($receiver !== null && ltrim($receiver, '\\') === $targetClass) {
                        yield ['node' => $node->name, 'kind' => 'method'];
                    }
                    continue;
                }
                if ($node instanceof StaticCall
                    && $node->name instanceof Identifier
                    && $node->name->toString() === $targetName
                ) {
                    $receiver = self::resolvedNameOf($node->class);
                    if ($receiver !== null && ltrim($receiver, '\\') === $targetClass) {
                        yield ['node' => $node->name, 'kind' => 'method'];
                    }
                    continue;
                }
                if ($node instanceof ClassMethod
                    && $node->name->toString() === $targetName
                ) {
                    $declClass = self::enclosingClassFqn($ast, $node);
                    if ($declClass !== null && ltrim($declClass, '\\') === $targetClass) {
                        yield ['node' => $node->name, 'kind' => 'method-decl'];
                    }
                }
                continue;
            }
            // Property target.
            if (($node instanceof PropertyFetch || $node instanceof NullsafePropertyFetch)
                && $node->name instanceof Identifier
                && $node->name->toString() === $targetName
            ) {
                $receiver = $this->inferReceiverClassAt(
                    $source,
                    $uri,
                    max(0, $node->var->getEndFilePos()),
                );
                if ($receiver !== null && ltrim($receiver, '\\') === $targetClass) {
                    yield ['node' => $node->name, 'kind' => 'property'];
                }
                continue;
            }
            if ($node instanceof StaticPropertyFetch
                && $node->name instanceof Node\VarLikeIdentifier
                && $node->name->toString() === $targetName
            ) {
                $receiver = self::resolvedNameOf($node->class);
                if ($receiver !== null && ltrim($receiver, '\\') === $targetClass) {
                    yield ['node' => $node->name, 'kind' => 'property'];
                }
                continue;
            }
            if ($node instanceof PropertyProperty
                && $node->name->toString() === $targetName
            ) {
                $propStmt = self::findEnclosingProperty($ast, $node);
                if ($propStmt === null) {
                    continue;
                }
                $declClass = self::enclosingClassFqn($ast, $propStmt);
                if ($declClass !== null && ltrim($declClass, '\\') === $targetClass) {
                    yield ['node' => $node->name, 'kind' => 'property-decl'];
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
    private static function findParentOfIdentifier(array $ast, Node $needle): ?Node
    {
        $visitor = new class($needle) extends NodeVisitorAbstract {
            public ?Node $parent = null;

            public function __construct(private readonly Node $needle)
            {
            }

            public function enterNode(Node $node): null
            {
                if ($this->parent !== null) {
                    return null;
                }
                if ($node instanceof ClassLike && $node->name === $this->needle) {
                    $this->parent = $node;
                    return null;
                }
                if ($node instanceof Function_ && $node->name === $this->needle) {
                    $this->parent = $node;
                    return null;
                }
                if ($node instanceof ClassMethod && $node->name === $this->needle) {
                    $this->parent = $node;
                    return null;
                }
                if ($node instanceof PropertyProperty && $node->name === $this->needle) {
                    $this->parent = $node;
                    return null;
                }
                if (($node instanceof MethodCall
                        || $node instanceof NullsafeMethodCall
                        || $node instanceof StaticCall
                        || $node instanceof PropertyFetch
                        || $node instanceof NullsafePropertyFetch
                        || $node instanceof StaticPropertyFetch
                    ) && $node->name === $this->needle) {
                    $this->parent = $node;
                    return null;
                }
                return null;
            }
        };
        $t = new NodeTraverser();
        $t->addVisitor($visitor);
        $t->traverse($ast);
        return $visitor->parent;
    }

    /**
     * Walk the AST to find the ClassLike that contains `$target`, then
     * return its FQN.  Returns null when `$target` isn't lexically inside
     * any class (defensive; method declarations always are).
     *
     * @param list<Node\Stmt> $ast
     */
    private static function enclosingClassFqn(array $ast, Node $target): ?string
    {
        $visitor = new class($target) extends NodeVisitorAbstract {
            public ?string $fqn = null;
            private array $stack = [];

            public function __construct(private readonly Node $target)
            {
            }

            public function enterNode(Node $node): null
            {
                if ($node instanceof ClassLike) {
                    $this->stack[] = $node;
                }
                if ($node === $this->target && $this->stack !== [] && $this->fqn === null) {
                    $cls = end($this->stack);
                    if ($cls instanceof ClassLike) {
                        $this->fqn = isset($cls->namespacedName)
                            ? $cls->namespacedName->toString()
                            : ($cls->name?->toString() ?? '');
                    }
                }
                return null;
            }

            public function leaveNode(Node $node): null
            {
                if ($node instanceof ClassLike) {
                    array_pop($this->stack);
                }
                return null;
            }
        };
        $t = new NodeTraverser();
        $t->addVisitor($visitor);
        $t->traverse($ast);
        return $visitor->fqn !== null && $visitor->fqn !== '' ? $visitor->fqn : null;
    }

    /**
     * @param list<Node\Stmt> $ast
     */
    private static function findEnclosingProperty(array $ast, PropertyProperty $needle): ?Property
    {
        $visitor = new class($needle) extends NodeVisitorAbstract {
            public ?Property $found = null;

            public function __construct(private readonly PropertyProperty $needle)
            {
            }

            public function enterNode(Node $node): null
            {
                if ($this->found !== null) {
                    return null;
                }
                if ($node instanceof Property) {
                    foreach ($node->props as $p) {
                        if ($p === $this->needle) {
                            $this->found = $node;
                            return null;
                        }
                    }
                }
                return null;
            }
        };
        $t = new NodeTraverser();
        $t->addVisitor($visitor);
        $t->traverse($ast);
        return $visitor->found;
    }

    /**
     * Best-effort receiver-class inference for member-access calls.  Uses
     * worse-reflection's offset reflection (the same machinery completion
     * uses for `$x->|`) and consults `GenericResolver` to swap xphp
     * generic-class placeholders for their concrete substituted type.
     *
     * `$byteOffset` is the position of the last byte of the receiver
     * expression (one byte before the operator).
     */
    private function inferReceiverClassAt(string $source, string $uri, int $byteOffset): ?string
    {
        $stripped = $this->parser->strip($source);
        $textDoc = TextDocumentBuilder::create($stripped)->uri($uri)->language('php')->build();
        try {
            $context = $this->reflector
                ->reflectOffset($textDoc, ByteOffset::fromInt($byteOffset))
                ->nodeContext();
        } catch (Throwable) {
            return null;
        }
        $typeName = (string) $context->type();
        if ($typeName === '' || $typeName === '<missing>') {
            return null;
        }
        $lookupName = ltrim($typeName, '?');
        $swapped = $this->genericResolver->resolveMemberAccessReceiverClassAt($uri, $byteOffset);
        if ($swapped !== null && $swapped !== '') {
            $lookupName = $swapped;
        }
        return $lookupName !== '' ? $lookupName : null;
    }

    /**
     * Resolve a `Foo::method()` left-hand side to a class FQN.  After
     * NameResolver has run, the `resolvedName` attribute carries it for
     * static names; `self` / `static` / `parent` resolve to the enclosing
     * class which we can't surface without an extra scope walk -- defer.
     */
    private static function resolvedNameOf(Node $classExpr): ?string
    {
        if (!$classExpr instanceof Name) {
            return null;
        }
        $resolved = $classExpr->getAttribute('resolvedName');
        if ($resolved instanceof Name) {
            return $resolved->toString();
        }
        if ($classExpr->isFullyQualified()) {
            return ltrim($classExpr->toString(), '\\');
        }
        return null;
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
