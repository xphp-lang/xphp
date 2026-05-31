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
     * Expose the cursor's target descriptor for callers that need to
     * inspect the symbol's kind / FQN (e.g. RenameProvider's file-rename
     * branch for class targets).  Mirrors `resolveTargetAt` shape; see
     * its docblock for the available keys.
     *
     * @return array{kind: string, fqn?: string, className?: string, memberName?: string, aliasName?: string, scopeUri?: string, declUri?: string, declLine?: int, declChar?: int}|null
     */
    public function targetAt(string $uri, int $byteOffset): ?array
    {
        return $this->resolveTargetAt($uri, $byteOffset);
    }

    /**
     * Short name (last `\`-segment for FQN targets, the member name for
     * method/property targets) of the symbol the cursor is on.  Used by
     * RenameProvider to skip aliased references whose source text
     * doesn't match the original symbol -- e.g. `bar()` calls that
     * resolve to `foo` via `use function foo as bar`.
     */
    public function shortNameAt(string $uri, int $byteOffset): ?string
    {
        $target = $this->resolveTargetAt($uri, $byteOffset);
        if ($target === null) {
            return null;
        }
        if (isset($target['aliasName'])) {
            return (string) $target['aliasName'];
        }
        if (isset($target['memberName'])) {
            return (string) $target['memberName'];
        }
        if (!isset($target['fqn'])) {
            return null;
        }
        $fqn = ltrim((string) $target['fqn'], '\\');
        $idx = strrpos($fqn, '\\');
        return $idx === false ? $fqn : substr($fqn, $idx + 1);
    }

    /**
     * @return list<Location>
     */
    public function findReferences(
        string $uri,
        int $byteOffset,
        bool $includeDeclaration,
        ?\Amp\CancellationToken $cancel = null,
        ?string $restrictToUri = null,
    ): array {
        $target = $this->resolveTargetAt($uri, $byteOffset);
        if ($target === null) {
            return [];
        }

        $locations = [];
        $seenUris = [];

        // Open-doc pass: live state beats on-disk.
        //
        // `$restrictToUri` short-circuits to a single open document --
        // documentHighlight needs only in-file results, and walking
        // hundreds of filesystem files only to throw them away was the
        // 2026-05-27 prod-log stall (~2.7s of single-thread work
        // blocking 5 queued requests behind it).  With the restriction
        // we scan exactly one URI's AST.
        foreach ($this->workspace as $docUri => $item) {
            // Cancellation poll per file: the open-doc set is typically
            // small (tens of files at most) so checking on every
            // iteration is essentially free.
            if ($cancel !== null && $cancel->isRequested()) {
                return [];
            }
            $docUriStr = (string) $docUri;
            if ($restrictToUri !== null && $docUriStr !== $restrictToUri) {
                continue;
            }
            $seenUris[$docUriStr] = true;
            $result = $this->cache->getOrParse($docUriStr, $item->version, $item->text);
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
            foreach ($this->collectReferences($ast, $target, $item->text, $docUriStr, $cancel) as $hit) {
                $locations[] = $this->buildLocation($docUriStr, $item->text, $offsets, $hit);
            }
        }

        // Filesystem pass: parse on demand, skipping any URI the workspace
        // already covered (open-doc precedence).  This can be hundreds of
        // files on big projects, so the cancellation poll is the
        // load-bearing one for fix D -- if the user moves their cursor
        // mid-find-references, the scan abandons rather than running to
        // completion.
        //
        // Skipped entirely when `$restrictToUri` is set: a single-file
        // request can't get matches from any other file.
        if ($restrictToUri === null) {
            // Perf #2: cheap-bail short-name pre-filter.  Most class /
            // function / method targets have a unique short name that
            // textually appears in only a small fraction of workspace
            // files; for those the raw-text `str_contains` check costs
            // microseconds per file vs the ~30ms parse + walk it
            // avoids.  Build the set of short names that count as a
            // textual hit; if none of them appears in $source, skip
            // parsing entirely.
            $shortNameNeedles = self::shortNameNeedles($target);
            foreach ($this->fqnIndex->indexedFilesystemPaths() as $path) {
                if ($cancel !== null && $cancel->isRequested()) {
                    return [];
                }
                $fsUri = 'file://' . $path;
                if (isset($seenUris[$fsUri])) {
                    continue;
                }
                $source = @file_get_contents($path);
                if ($source === false) {
                    continue;
                }
                if (!self::sourceMatchesShortNames($source, $shortNameNeedles)) {
                    continue;
                }
                // Perf #1: consult ParsedDocumentCache before re-parsing
                // -- ParsedDocumentCacheWarmer pre-seeds every
                // filesystem-indexed URI at the sentinel version 0, and
                // anyone who's opened this file since pushed a
                // versioned entry that we can also reuse here.  On
                // miss (rare after warm-up), fall back to the tolerant
                // parser path and seed for the next call.
                $cachedAst = null;
                $cachedOffsets = null;
                $cachedParse = $this->cache->peek($fsUri);
                if ($cachedParse !== null && $cachedParse->ast !== null) {
                    $cachedAst = $cachedParse->ast;
                    $cachedOffsets = $cachedParse->byteOffsetMap;
                }
                if ($cachedAst === null) {
                    try {
                        $parsed = $this->parser->parseTolerantWithMap($source);
                    } catch (Throwable) {
                        continue;
                    }
                    if ($parsed === null) {
                        continue;
                    }
                    $cachedAst = $parsed->ast;
                    $cachedOffsets = $parsed->byteOffsetMap;
                }
                foreach ($this->collectReferences($cachedAst, $target, $source, $fsUri, $cancel) as $hit) {
                    $locations[] = $this->buildLocation($fsUri, $source, $cachedOffsets, $hit);
                }
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
            //
            // Item 1: when the receiver inherits the member from an
            // ancestor, climb to the declaring class.  Without this,
            // cursor on `$dog->speak()` (with `Dog extends Animal`)
            // targets the non-existent `Dog::speak`; find-references
            // then finds nothing and rename does nothing.  Resolving up
            // to `Animal::speak` makes the symbol identity match every
            // call site through the inheritance chain.
            if ($parent instanceof MethodCall || $parent instanceof NullsafeMethodCall) {
                if ($parent->name === $best) {
                    // Cycle K.1: union receiver -> all constituents
                    // become declaring-class candidates so call sites
                    // typed as ANY of them match in find-references.
                    $receivers = $this->inferReceiverClassesAt(
                        $item->text,
                        $uri,
                        max(0, $parent->var->getEndFilePos()),
                    );
                    if ($receivers !== []) {
                        $memberName = $best->toString();
                        $declared = [];
                        foreach ($receivers as $receiverClass) {
                            $declared[] = $this->declaringClassOf($receiverClass, $memberName, true) ?? $receiverClass;
                        }
                        $declared = array_values(array_unique($declared));
                        $target = [
                            'kind' => 'method',
                            'className' => $declared[0],
                            'memberName' => $memberName,
                        ];
                        if (count($declared) > 1) {
                            $target['classNames'] = $declared;
                        }
                        return $target;
                    }
                }
            }
            if ($parent instanceof StaticCall && $parent->name === $best) {
                $receiverClass = self::resolvedNameOf($parent->class);
                if ($receiverClass !== null) {
                    $memberName = $best->toString();
                    $declaring = $this->declaringClassOf($receiverClass, $memberName, true) ?? $receiverClass;
                    return [
                        'kind' => 'method',
                        'className' => $declaring,
                        'memberName' => $memberName,
                    ];
                }
            }
            if ($parent instanceof PropertyFetch || $parent instanceof NullsafePropertyFetch) {
                if ($parent->name === $best) {
                    // Cycle K.1: same union receiver fan-out as
                    // MethodCall above.
                    $receivers = $this->inferReceiverClassesAt(
                        $item->text,
                        $uri,
                        max(0, $parent->var->getEndFilePos()),
                    );
                    if ($receivers !== []) {
                        $memberName = $best->toString();
                        $declared = [];
                        foreach ($receivers as $receiverClass) {
                            $declared[] = $this->declaringClassOf($receiverClass, $memberName, false) ?? $receiverClass;
                        }
                        $declared = array_values(array_unique($declared));
                        $target = [
                            'kind' => 'property',
                            'className' => $declared[0],
                            'memberName' => $memberName,
                        ];
                        if (count($declared) > 1) {
                            $target['classNames'] = $declared;
                        }
                        return $target;
                    }
                }
            }
            if ($parent instanceof StaticPropertyFetch && $parent->name === $best) {
                $receiverClass = self::resolvedNameOf($parent->class);
                if ($receiverClass !== null) {
                    $memberName = $best->toString();
                    $declaring = $this->declaringClassOf($receiverClass, $memberName, false) ?? $receiverClass;
                    return [
                        'kind' => 'property',
                        'className' => $declaring,
                        'memberName' => $memberName,
                    ];
                }
            }
            // Alias declaration token: cursor on `xyz` inside
            // `use ... as xyz;` (or the group-use variant).  Treat as a
            // file-scoped alias rename target.  Same shape collect-
            // References already understands.
            if ($parent instanceof Node\UseItem && $parent->alias === $best) {
                return [
                    'kind' => 'alias',
                    'aliasName' => $best->toString(),
                    'scopeUri' => $uri,
                ];
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
            $literalShort = self::shortSegment($best->toString());
            if ($resolved instanceof Name) {
                $resolvedShort = self::shortSegment($resolved->toString());
                // Alias-resolved reference: the literal text the user typed
                // differs from the underlying short name (`use App\Foo as
                // Bar; new Bar()` -- literal `Bar`, resolved short `Foo`).
                // PhpStorm-style: rename should be SCOPED to the alias
                // locally, not pivot to the source symbol the user can't
                // see at this site.
                if ($literalShort !== $resolvedShort && !$best->isFullyQualified()) {
                    return [
                        'kind' => 'alias',
                        'aliasName' => $literalShort,
                        'scopeUri' => $uri,
                    ];
                }
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

    private static function shortSegment(string $name): string
    {
        $trimmed = ltrim($name, '\\');
        $idx = strrpos($trimmed, '\\');
        return $idx === false ? $trimmed : substr($trimmed, $idx + 1);
    }

    /**
     * Build the textual-hint set for {@see sourceMatchesShortNames}.
     * One short name per identifier we'd accept as a reference --
     * the trailing `\`-segment of an FQN target, or the member name
     * for method/property targets, or the alias text for aliases.
     *
     * Trusts the descriptor shape produced by {@see resolveTargetAt}:
     * every kind always carries its corresponding key.  Defensive
     * null-coalesce + string casts here just generated mutation noise
     * for code paths that can't fire in production.  An empty list
     * disables the filter (returned for kinds we don't model);
     * filter-disabled means "walk every file" -- safe-but-slow,
     * never silently drops references.
     *
     * @param array{kind: string, fqn?: string, className?: string, memberName?: string, aliasName?: string, classNames?: list<string>} $target
     * @return list<string>
     */
    private static function shortNameNeedles(array $target): array
    {
        return match ($target['kind']) {
            'alias' => [$target['aliasName']],
            'class', 'function' => [self::shortSegment($target['fqn'])],
            'method', 'property' => [$target['memberName']],
            default => [],
        };
    }

    /**
     * Perf #2: raw-text short-name pre-filter.  Decide whether a
     * file's source text contains ANY of the target's short names
     * as a substring; if not, no reference to that symbol can exist
     * in this file and we skip the parse + AST walk entirely.
     *
     * False positives are fine (we parse, find nothing, move on --
     * the existing per-node match logic is the authority).  False
     * negatives would silently drop real references, so the rules
     * are conservative:
     *
     *   - Empty needle set -> always return true (filter disabled).
     *   - `str_contains` rather than word-boundary regex; PHP
     *     identifiers can sit inside `->`, `::`, `$`, etc. with no
     *     whitespace, and Boyer-Moore-style substring is what the
     *     `strpos` family compiles to.
     *
     * Aliased imports (`use App\Foo as Bar`) DO get false negatives
     * here -- the file references `Bar`, not `Foo`'s short name --
     * but the surrounding architecture already routes aliases
     * through a separate scoped path ({@see collectReferences}'s
     * alias branch returns early before reaching the filesystem
     * pass), so this isn't a real correctness gap in V1.
     *
     * @param list<string> $needles
     */
    private static function sourceMatchesShortNames(string $source, array $needles): bool
    {
        if ($needles === []) {
            return true;
        }
        foreach ($needles as $needle) {
            if (str_contains($source, $needle)) {
                return true;
            }
        }
        return false;
    }

    /**
     * @param list<Node\Stmt> $ast
     * @return iterable<array{node: Node, kind: string}>
     */
    private function collectReferences(
        array $ast,
        array $target,
        string $source,
        string $uri,
        ?\Amp\CancellationToken $cancel = null,
    ): iterable {
        // Alias rename is file-scoped: PHP's `use ... as <alias>` lives
        // for the rest of the current file and nowhere else.  Skip every
        // file except the one the cursor lives in.
        if ($target['kind'] === 'alias') {
            if ($uri !== (string) $target['scopeUri']) {
                return;
            }
            $ast = self::cloneWithResolvedNames($ast);
            $finder = new NodeFinder();
            $aliasName = (string) $target['aliasName'];
            foreach ($finder->find($ast, static fn (Node $n): bool => true) as $node) {
                if ($cancel !== null && $cancel->isRequested()) {
                    return;
                }
                if ($node instanceof Node\UseItem
                    && $node->alias instanceof Identifier
                    && $node->alias->toString() === $aliasName
                ) {
                    yield ['node' => $node->alias, 'kind' => 'alias-decl'];
                    continue;
                }
                if ($node instanceof Name
                    && !$node->isFullyQualified()
                    && $node->toString() === $aliasName
                ) {
                    yield ['node' => $node, 'kind' => 'alias-use'];
                }
            }
            return;
        }

        $ast = self::cloneWithResolvedNames($ast);
        $finder = new NodeFinder();

        if ($target['kind'] === 'class' || $target['kind'] === 'function') {
            $targetFqn = ltrim((string) $target['fqn'], '\\');
            foreach ($finder->find($ast, static fn (Node $n): bool => true) as $node) {
                if ($cancel !== null && $cancel->isRequested()) {
                    return;
                }
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
                // `use function App\foo;` and `use function App\{foo};`
                // import statements -- the imported Name is a function
                // reference too, otherwise rename leaves the alias stale.
                if ($node instanceof Node\Stmt\Use_
                    && $node->type === Node\Stmt\Use_::TYPE_FUNCTION
                ) {
                    foreach ($node->uses as $useUse) {
                        $candidate = ltrim($useUse->name->toString(), '\\');
                        if ($candidate === $targetFqn) {
                            yield ['node' => $useUse->name, 'kind' => 'function-use'];
                        }
                    }
                    continue;
                }
                if ($node instanceof Node\Stmt\GroupUse
                    && $node->type === Node\Stmt\Use_::TYPE_FUNCTION
                ) {
                    $prefix = $node->prefix->toString();
                    foreach ($node->uses as $useUse) {
                        $candidate = ltrim($prefix . '\\' . $useUse->name->toString(), '\\');
                        if ($candidate === $targetFqn) {
                            yield ['node' => $useUse->name, 'kind' => 'function-use'];
                        }
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
        // Cycle K.1: when the cursor's receiver was a union/
        // intersection type, `classNames` lists every declaring
        // class candidate.  The receiver-side match yields if the
        // call site's receiver inherits the member from ANY of
        // those candidates; the declaration-side match still
        // requires exact equality with the canonical target.
        $targetClass = ltrim((string) $target['className'], '\\');
        $targetName = (string) $target['memberName'];
        /** @var list<string> $targetClasses */
        $targetClasses = isset($target['classNames'])
            ? array_values(array_map(static fn (string $c): string => ltrim($c, '\\'), $target['classNames']))
            : [$targetClass];

        // Item 1: receiver-side match is "does the receiver class inherit
        // this member from `$targetClass`?" -- exact-FQN match preserved
        // for the common case (cheap path), inheritance walk for the
        // subclass case via `inheritsMemberFromTarget`.  Declaration-side
        // matches stay as exact `$targetClass` comparisons because we
        // only want the canonical declaration site, not every
        // unrelated class that happens to use the same name.
        foreach ($finder->find($ast, static fn (Node $n): bool => true) as $node) {
            // Cancel-poll inside the per-node loop: each iteration on
            // a method/property target can trigger an `inferReceiverClassAt`
            // worse-reflection round-trip, so a file with N method calls
            // costs N reflections.  Without polling, a single mid-flight
            // request could keep the dispatcher pinned long enough to
            // stall every queued message behind it (the 2026-05-27
            // prod-log 2:43 stall was 2.7s of in-loop work).
            if ($cancel !== null && $cancel->isRequested()) {
                return;
            }
            if ($target['kind'] === 'method') {
                if (($node instanceof MethodCall || $node instanceof NullsafeMethodCall)
                    && $node->name instanceof Identifier
                    && $node->name->toString() === $targetName
                ) {
                    // Cycle K.1: union/intersection receiver call
                    // sites match if ANY constituent inherits the
                    // member from ANY target candidate.
                    $receivers = $this->inferReceiverClassesAt(
                        $source,
                        $uri,
                        max(0, $node->var->getEndFilePos()),
                    );
                    $matched = false;
                    foreach ($receivers as $receiver) {
                        foreach ($targetClasses as $candidate) {
                            if ($this->inheritsMemberFromTarget($receiver, $targetName, $candidate, true)) {
                                $matched = true;
                                break 2;
                            }
                        }
                    }
                    if ($matched) {
                        yield ['node' => $node->name, 'kind' => 'method'];
                    }
                    continue;
                }
                if ($node instanceof StaticCall
                    && $node->name instanceof Identifier
                    && $node->name->toString() === $targetName
                ) {
                    $receiver = self::resolvedNameOf($node->class);
                    if ($receiver !== null && $this->inheritsMemberFromTarget($receiver, $targetName, $targetClass, true)) {
                        yield ['node' => $node->name, 'kind' => 'method'];
                    }
                    continue;
                }
                if ($node instanceof ClassMethod
                    && $node->name->toString() === $targetName
                ) {
                    $declClass = self::enclosingClassFqn($ast, $node);
                    if ($declClass !== null
                        && $this->declarationMatchesTarget($declClass, $targetName, $targetClass, true)
                    ) {
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
                // Cycle K.1: same union-receiver + union-target
                // fan-out as methods.
                $receivers = $this->inferReceiverClassesAt(
                    $source,
                    $uri,
                    max(0, $node->var->getEndFilePos()),
                );
                $matched = false;
                foreach ($receivers as $receiver) {
                    foreach ($targetClasses as $candidate) {
                        if ($this->inheritsMemberFromTarget($receiver, $targetName, $candidate, false)) {
                            $matched = true;
                            break 2;
                        }
                    }
                }
                if ($matched) {
                    yield ['node' => $node->name, 'kind' => 'property'];
                }
                continue;
            }
            if ($node instanceof StaticPropertyFetch
                && $node->name instanceof Node\VarLikeIdentifier
                && $node->name->toString() === $targetName
            ) {
                $receiver = self::resolvedNameOf($node->class);
                if ($receiver !== null && $this->inheritsMemberFromTarget($receiver, $targetName, $targetClass, false)) {
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
                if ($declClass !== null
                    && $this->declarationMatchesTarget($declClass, $targetName, $targetClass, false)
                ) {
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
        // Pass a Collecting handler instead of the default Throwing one:
        // the tolerant parse fallback in Analyzer (the "$x->" / `a` /
        // similar recovery cases) sometimes yields an AST whose use
        // statements look like duplicates to NameContext, even though
        // the SOURCE has no duplicate use.  Without the collecting
        // handler, NameResolver's `Cannot use ... as ... because the
        // name is already in use` ripples up through documentHighlight
        // / references and PhpStorm renders an error toast.  Collecting
        // the errors keeps the (partially resolved) AST usable; any
        // Name nodes that DID resolve carry the `resolvedName`
        // attribute, the rest just lack it.  References / highlight
        // still work for the well-formed subset of the file.
        $errorHandler = new \PhpParser\ErrorHandler\Collecting();
        $resolver = new NameResolver($errorHandler, ['replaceNodes' => false]);
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
                if ($node instanceof Node\UseItem
                    && $node->alias === $this->needle
                ) {
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
        $all = $this->inferReceiverClassesAt($source, $uri, $byteOffset);
        return $all === [] ? null : $all[0];
    }

    /**
     * Cycle K.1: return EVERY constituent class FQN that the
     * receiver expression at `$byteOffset` could resolve to.
     *
     *   - Single-class receiver -> 1-element list.
     *   - Union receiver (`A|B`) -> 2-element list.
     *   - Intersection (`A&B`)   -> 2-element list (both apply).
     *   - Mixed (`(A&B)|C`)      -> 3-element list (A, B, C).
     *
     * Callers use this to fan out per-receiver inheritance / member
     * lookups so call sites on union-typed variables surface in
     * find-references / rename / documentHighlight.
     *
     * @return list<string>
     */
    private function inferReceiverClassesAt(string $source, string $uri, int $byteOffset): array
    {
        $stripped = $this->parser->strip($source);
        $textDoc = TextDocumentBuilder::create($stripped)->uri($uri)->language('php')->build();
        try {
            $context = $this->reflector
                ->reflectOffset($textDoc, ByteOffset::fromInt($byteOffset))
                ->nodeContext();
        } catch (Throwable) {
            return [];
        }
        $typeName = (string) $context->type();

        // Single-class fast path (the dominant case).  Cycle C's
        // ClassFqnPredicate gate stays load-bearing: it accepts
        // `?A` / `\A` / namespaced shapes and rejects literals,
        // `<missing>`, etc.
        if (ClassFqnPredicate::is($typeName)) {
            $lookupName = ltrim($typeName, '?');
            $swapped = $this->genericResolver->resolveMemberAccessReceiverClassAt($uri, $byteOffset);
            if ($swapped !== null && $swapped !== '') {
                $lookupName = $swapped;
            }
            return $lookupName !== '' ? [$lookupName] : [];
        }

        // Cycle K.1 fan-out: union / intersection receivers split
        // via TypeUnionSplitter.  The resulting list combines every
        // arm's intersection components -- find-references treats
        // them as parallel receivers (a call site on any
        // constituent counts as a match).
        $receivers = [];
        foreach (TypeUnionSplitter::split($typeName) as $intersectionArm) {
            foreach ($intersectionArm as $componentFqn) {
                $receivers[] = $componentFqn;
            }
        }
        return array_values(array_unique($receivers));
    }

    /**
     * Worse-reflection-backed lookup: "given a class FQN, which class
     * declares this method/property?"  Walks the receiver's MRO (parent
     * classes, then implemented interfaces, then traits) and returns the
     * first declarer's FQN -- which equals the receiver's FQN when the
     * member is locally declared, and an ancestor's FQN when the member
     * is inherited.  Null when the class has no such member, or when
     * reflection fails (closed-source class, parse error, etc.).
     *
     * Item 1: this is the single chokepoint for the V1 "exact FQN match"
     * limitation in find-references / rename.  Both the cursor side
     * (`resolveTargetAt` for member access) and the collector side
     * (`collectReferences` member-match) consult this to bridge calls
     * on a subclass receiver back to the ancestor that actually declared
     * the member.
     */
    private function declaringClassOf(string $receiverFqn, string $memberName, bool $isMethod): ?string
    {
        $lookup = ltrim($receiverFqn, '\\');
        if ($lookup === '' || $memberName === '') {
            return null;
        }
        try {
            $class = $this->reflector->reflectClassLike($lookup);
            $member = $isMethod
                ? $class->methods()->get($memberName)
                : $class->properties()->get($memberName);
        } catch (Throwable) {
            return null;
        }
        $declaring = (string) $member->declaringClass()->name();
        return $declaring !== '' ? ltrim($declaring, '\\') : null;
    }

    /**
     * "Does `$receiverFqn` reach `$targetClass` when looking up
     * `$memberName`?"  True when the receiver is the target, or when
     * the receiver inherits the member from the target (and hasn't
     * overridden it -- override would make `declaringClassOf` return the
     * subclass instead).
     */
    private function inheritsMemberFromTarget(
        string $receiverFqn,
        string $memberName,
        string $targetClass,
        bool $isMethod,
    ): bool {
        $receiverNorm = ltrim($receiverFqn, '\\');
        $targetNorm = ltrim($targetClass, '\\');
        if ($receiverNorm === $targetNorm) {
            return true;
        }
        $declaring = $this->declaringClassOf($receiverNorm, $memberName, $isMethod);
        if ($declaring !== null && $declaring === $targetNorm) {
            return true;
        }
        // Interface-up: receiver class transitively implements the target
        // interface AND the interface declares the member.  Restores
        // Cycle A (#116) interface walks that were accidentally removed
        // during the Cycle C `isClassFqn` refactor.
        if ($this->classImplementsTransitively($receiverNorm, $targetNorm)
            && $this->declaresMember($targetNorm, $memberName, $isMethod)
        ) {
            return true;
        }
        // Interface-down: target class transitively implements the
        // receiver interface AND the receiver interface declares the
        // member.  Mirror of the above.
        if ($this->classImplementsTransitively($targetNorm, $receiverNorm)
            && $this->declaresMember($receiverNorm, $memberName, $isMethod)
        ) {
            return true;
        }
        return false;
    }

    /**
     * Declaration-side mirror of {@see inheritsMemberFromTarget}.
     * "Should we treat the declaration in `$declClassFqn` as a
     * declaration of the same logical symbol as `$targetClass::$memberName`?"
     *
     * Yields:
     *   - exact match (the existing canonical-declaration site)
     *   - impl decls when the target is an interface method (we want
     *     `interface Iface { function m(); }` AND every
     *     `class Impl implements Iface { function m() {…} }` to surface)
     *   - the interface decl when the target is a concrete impl method
     *     (symmetric to interface-down in the receiver-side check).
     */
    private function declarationMatchesTarget(
        string $declClassFqn,
        string $memberName,
        string $targetClass,
        bool $isMethod,
    ): bool {
        $declNorm = ltrim($declClassFqn, '\\');
        $targetNorm = ltrim($targetClass, '\\');
        if ($declNorm === $targetNorm) {
            return true;
        }
        if ($this->classImplementsTransitively($declNorm, $targetNorm)
            && $this->declaresMember($targetNorm, $memberName, $isMethod)
        ) {
            return true;
        }
        if ($this->classImplementsTransitively($targetNorm, $declNorm)
            && $this->declaresMember($declNorm, $memberName, $isMethod)
        ) {
            return true;
        }
        return false;
    }

    /**
     * Does `$classFqn` implement (or extend, for interfaces) `$ifaceFqn`
     * transitively?
     *
     * For a class receiver: worse-reflection's `ReflectionClass::interfaces()`
     * already returns the FULL transitive set (parent classes' implements
     * clauses + interface-extends-interface chains), so a single membership
     * check is enough.
     *
     * For an interface receiver: `ReflectionInterface::parents()` is SHALLOW
     * (only direct `extends` clauses), so we walk transitively here.
     */
    private function classImplementsTransitively(string $classFqn, string $ifaceFqn): bool
    {
        $lookup = ltrim($classFqn, '\\');
        $needle = ltrim($ifaceFqn, '\\');
        if ($lookup === '' || $needle === '') {
            return false;
        }
        try {
            $class = $this->reflector->reflectClassLike($lookup);
        } catch (Throwable) {
            return false;
        }
        if ($class instanceof \Phpactor\WorseReflection\Core\Reflection\ReflectionClass) {
            try {
                foreach ($class->interfaces() as $iface) {
                    if (ltrim((string) $iface->name(), '\\') === $needle) {
                        return true;
                    }
                }
            } catch (Throwable) {
            }
            return false;
        }
        if ($class instanceof \Phpactor\WorseReflection\Core\Reflection\ReflectionInterface) {
            return $this->interfaceExtendsTransitively($class, $needle, []);
        }
        return false;
    }

    /**
     * Transitive walk for `interface X extends Y, Z`.  worse-reflection's
     * `parents()` only returns the direct `extends` clause; we recurse
     * to cover multi-hop chains like `interface K extends J extends I`.
     *
     * @param array<string,true> $visited
     */
    private function interfaceExtendsTransitively(
        \Phpactor\WorseReflection\Core\Reflection\ReflectionInterface $iface,
        string $needle,
        array $visited,
    ): bool {
        try {
            foreach ($iface->parents() as $parent) {
                $name = ltrim((string) $parent->name(), '\\');
                if ($name === $needle) {
                    return true;
                }
                if (isset($visited[$name])) {
                    continue;
                }
                $visited[$name] = true;
                if ($this->interfaceExtendsTransitively($parent, $needle, $visited)) {
                    return true;
                }
            }
        } catch (Throwable) {
        }
        return false;
    }

    /**
     * Is `$memberName` declared directly on `$classFqn` (not just
     * inherited)?  Used to confirm the interface side of an interface
     * walk actually owns the method/property we're linking through --
     * a class implementing an unrelated interface shouldn't match.
     */
    private function declaresMember(string $classFqn, string $memberName, bool $isMethod): bool
    {
        $lookup = ltrim($classFqn, '\\');
        if ($lookup === '' || $memberName === '') {
            return false;
        }
        try {
            $class = $this->reflector->reflectClassLike($lookup);
        } catch (Throwable) {
            return false;
        }
        try {
            if ($isMethod) {
                return $class->methods()->has($memberName);
            }
            // Interfaces don't have properties; method_exists guards
            // against `Call to undefined method ReflectionInterface::
            // properties()`.
            if (!method_exists($class, 'properties')) {
                return false;
            }
            return $class->properties()->has($memberName);
        } catch (Throwable) {
            return false;
        }
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
