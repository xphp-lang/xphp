<?php

declare(strict_types=1);

namespace XPHP\Lsp\Resolver;

use Amp\CancellationToken;
use PhpParser\Node;
use PhpParser\Node\ClosureUse;
use PhpParser\Node\Expr\Assign;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Param;
use PhpParser\Node\Stmt\Foreach_;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;
use Phpactor\LanguageServer\Core\Workspace\Workspace as PhpactorWorkspace;
use Phpactor\LanguageServerProtocol\Location;
use Phpactor\LanguageServerProtocol\Position;
use Phpactor\LanguageServerProtocol\Range;
use Phpactor\TextDocument\ByteOffset;
use Phpactor\TextDocument\TextDocumentBuilder;
use Phpactor\WorseReflection\Core\Exception\NotFound;
use Phpactor\WorseReflection\Core\Exception\SourceNotFound;
use Phpactor\WorseReflection\Core\Inference\Symbol;
use Phpactor\WorseReflection\Core\Reflection\ReflectionClassLike;
use Phpactor\WorseReflection\Core\Reflection\ReflectionFunction;
use Phpactor\WorseReflection\Reflector;
use Throwable;
use XPHP\Lsp\Analyzer\ParsedDocumentCache;
use XPHP\Lsp\PositionMap;
use XPHP\Transpiler\Monomorphize\XphpSourceParser;

/**
 * PHP-semantic Go-To-Declaration backed by worse-reflection.
 *
 * Pipeline:
 *  1. Look up the document at the LSP cursor in the workspace.
 *  2. Strip xphp generic clauses (equal-length whitespace -- offsets
 *     preserved) so worse-reflection's tolerant parser sees pure PHP.
 *  3. Ask worse-reflection's `reflectOffset()` what symbol the cursor
 *     is on.
 *  4. Dispatch on `Symbol::symbolType()`:
 *     - `class`     -> resolve the class FQN, return its declaration location.
 *     - `function`  -> resolve the function name (workspace + stubs).
 *     - `method`    -> use `containerType()` to find the class, then look up
 *                       the method.
 *     - `property`  -> same shape as method.
 *     - `constant`  -> same shape as method (class const) OR top-level
 *                       constant lookup.
 *  5. Translate the resulting reflection's name-range to an LSP `Location`.
 *
 * Returns null for any cursor position that doesn't resolve to a known
 * symbol -- worse-reflection's null-object pattern (`SymbolType::UNKNOWN`,
 * `Type::unknown()`) bubbles up as "no answer" rather than an exception
 * here.  This is intentional: GTD on unknown identifiers is silent in
 * editors, not noisy.
 *
 * Native function GTD lands inside `vendor/jetbrains/phpstorm-stubs/...`
 * which is exactly what PhpStorm does on .php files, so the UX is
 * consistent.
 */
final class PhpDefinitionResolver
{
    public function __construct(
        private readonly PhpactorWorkspace $workspace,
        private readonly XphpSourceParser $parser,
        private readonly Reflector $reflector,
        private readonly ParsedDocumentCache $cache,
        private readonly GenericResolver $genericResolver,
    ) {
    }

    public function resolve(string $uri, int $line, int $character, ?CancellationToken $cancel = null): ?Location
    {
        // Backwards-compat wrapper: returns the FIRST location from
        // {@see resolveAll}, or null when there are none.  Existing
        // tests + the single-Location path of XphpDefinitionHandler
        // keep working unchanged; the handler uses `resolveAll` for
        // the array case Cycle K introduced.
        $all = $this->resolveAll($uri, $line, $character, $cancel);
        return $all === [] ? null : $all[0];
    }

    /**
     * @return list<Location>
     */
    public function resolveAll(string $uri, int $line, int $character, ?CancellationToken $cancel = null): array
    {
        // Belt-and-braces: the resolver calls into third-party
        // worse-reflection which has its own surprises on edge cases
        // (e.g. `MissingType::name()` -- the original cause of the LSP
        // crash captured in xphp-20260524-125122-098.log).  A single
        // top-level catch makes any unexpected internal failure surface
        // as "no result" instead of a fatal that poisons the LSP
        // transport via stdout.
        try {
            return $this->resolveInner($uri, $line, $character, $cancel);
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * Resolve the cursor to the definition of the symbol's INFERRED
     * TYPE rather than the symbol's own declaration site.  Backs
     * `textDocument/typeDefinition` -- e.g. on `$user = new User();`
     * with the cursor on the second `$user`, regular `definition`
     * jumps to the first `$user` (the variable's declaration), while
     * `typeDefinition` jumps to `class User`.
     *
     * For a class reference (cursor on `User`), `(string) $context->type()`
     * already yields the class FQN -- so this collapses to the same
     * behaviour as `definition`'s CLASS_ branch.
     *
     * For symbol kinds with no meaningful "type" (FUNCTION /
     * CONSTANT / CASE), returns null -- LSP clients render that as
     * "no Go To Type Declaration target".
     */
    public function resolveType(string $uri, int $line, int $character, ?CancellationToken $cancel = null): ?Location
    {
        $all = $this->resolveTypeAll($uri, $line, $character, $cancel);
        return $all === [] ? null : $all[0];
    }

    /**
     * @return list<Location>
     */
    public function resolveTypeAll(string $uri, int $line, int $character, ?CancellationToken $cancel = null): array
    {
        try {
            return $this->resolveTypeInner($uri, $line, $character, $cancel);
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * @return list<Location>
     */
    private function resolveTypeInner(string $uri, int $line, int $character, ?CancellationToken $cancel): array
    {
        if ($cancel !== null && $cancel->isRequested()) {
            return [];
        }
        $document = $this->workspace->has($uri) ? $this->workspace->get($uri) : null;
        if ($document === null) {
            return [];
        }

        $offset = (new PositionMap($document->text))->positionToOffset($line, $character);
        $stripped = $this->parser->strip($document->text);
        $sourceCode = TextDocumentBuilder::create($stripped)
            ->uri($uri)
            ->language('php')
            ->build();

        try {
            $reflectionOffset = $this->reflector->reflectOffset($sourceCode, ByteOffset::fromInt($offset));
        } catch (Throwable) {
            return [];
        }

        if ($cancel !== null && $cancel->isRequested()) {
            return [];
        }

        $context = $reflectionOffset->nodeContext();
        $symbol = $context->symbol();

        // For VARIABLE / PROPERTY / METHOD cursors the meaningful
        // "type" is the inferred type at the cursor position.  For
        // CLASS_ the symbol IS the class, so $context->type() returns
        // the same FQN.  Everything else (FUNCTION, CONSTANT, CASE)
        // has no useful type to jump to.
        $kind = $symbol->symbolType();
        $typeBearing = $kind === Symbol::VARIABLE
            || $kind === Symbol::PROPERTY
            || $kind === Symbol::METHOD
            || $kind === Symbol::CLASS_;
        if (!$typeBearing) {
            return [];
        }

        // Cycle K: typeDefinition on `$x: A|B` returns the union of
        // type-declaration locations so PhpStorm can render a picker.
        return $this->fanOutLocate(
            (string) $context->type(),
            fn (string $fqn): ?Location => $this->locateClass($fqn),
        );
    }

    /**
     * Reject non-class type strings BEFORE they reach the locator.
     *
     * Backwards-compatible alias for the shared
     * {@see ClassFqnPredicate::is}.  Originally introduced inline
     * here in commit 4f22c4a (Phase 6 Fix 1); promoted to the shared
     * resolver in Cycle C of the open backlog so every
     * `reflectClassLike` caller can short-circuit on union /
     * intersection / scalar-literal / `<missing>` strings.  Kept as a
     * static method on this class so the test surface (and any
     * external callers) don't have to be re-routed.
     */
    public static function isClassFqn(string $typeName): bool
    {
        return ClassFqnPredicate::is($typeName);
    }

    /**
     * @return list<Location>
     */
    private function resolveInner(string $uri, int $line, int $character, ?CancellationToken $cancel): array
    {
        if ($cancel !== null && $cancel->isRequested()) {
            return [];
        }
        $document = $this->workspace->has($uri) ? $this->workspace->get($uri) : null;
        if ($document === null) {
            return [];
        }

        $offset = (new PositionMap($document->text))->positionToOffset($line, $character);
        $stripped = $this->parser->strip($document->text);
        $sourceCode = TextDocumentBuilder::create($stripped)
            ->uri($uri)
            ->language('php')
            ->build();

        try {
            $reflectionOffset = $this->reflector->reflectOffset($sourceCode, ByteOffset::fromInt($offset));
        } catch (Throwable) {
            return [];
        }

        if ($cancel !== null && $cancel->isRequested()) {
            // worse-reflection's reflectOffset is one of the heavier
            // ops in the chain; bail before locate-* if the user
            // moved on.
            return [];
        }

        $context = $reflectionOffset->nodeContext();
        $symbol = $context->symbol();

        // Worse-reflection classifies the imported name inside
        // `use function App\foo;` as Symbol::CLASS_ (its TolerantParser
        // sees `App\foo` and assumes it's a class), so the function
        // dispatch never fires and locateClass throws SourceNotFound.
        // Override to FUNCTION when the cursor's AST context says we're
        // inside a `use function` (or `use function Foo\{...}` group)
        // statement.  Same logic applies to PhpHoverResolver.
        $useFunctionFqn = $this->useFunctionFqnAtOffset($uri, $offset, $symbol->name());
        if ($useFunctionFqn !== null) {
            return self::asList($this->locateFunction($useFunctionFqn));
        }

        // For class references, worse-reflection puts the SHORT name (or
        // the literal source name) on the Symbol and the resolved FQN on
        // the inferred Type.  In `new User(...)` after `use App\User;` the
        // symbol name is "User" but the type name is "App\User" -- which
        // is what we need to feed to reflectClassLike().  When the cursor
        // is on a use statement itself, both happen to be the FQN.
        //
        // For method/property/case dispatch, containerType() may be a
        // MissingType when worse-reflection couldn't infer the receiver
        // (e.g. xphp generic-method return values stripped to bare `T`,
        // dynamic property access on unknown variables, etc.).  We funnel
        // through `containerOrNull()` so MissingType means "give up
        // gracefully" instead of "crash on undefined method name()".
        //
        // Cycle K: union / intersection receiver types fan out via
        // {@see fanOutLocate}, returning one Location per constituent
        // class.  PhpStorm renders the resulting array as a picker.
        return match ($symbol->symbolType()) {
            Symbol::CLASS_     => $this->fanOutLocate(
                                    self::preferType($context, $symbol->name()),
                                    fn (string $fqn): ?Location => $this->locateClass($fqn),
                                ),
            Symbol::FUNCTION   => self::asList($this->locateFunction($symbol->name())),
            Symbol::METHOD     => ($c = self::containerOrNull($context)) !== null
                                    ? $this->fanOutLocate(
                                        $c,
                                        fn (string $fqn): ?Location => $this->locateMethod($fqn, $symbol->name()),
                                    )
                                    : [],
            Symbol::PROPERTY   => $this->fanOutLocate(
                                    // Resolver-first: substituted receiver wins
                                    // when GenericResolver has a binding for
                                    // `$x->method()?->prop` (Phase 0.7).  Falls
                                    // back to worse-reflection's containerType.
                                    $this->genericResolver->resolvePropertyReceiverClassAt($uri, $offset)
                                        ?? self::containerOrNull($context)
                                        ?? '',
                                    fn (string $fqn): ?Location => $this->locateProperty($fqn, $symbol->name()),
                                ),
            Symbol::CONSTANT,
            Symbol::DECLARED_CONSTANT
                               => self::asList($this->locateConstant($context, $symbol->name())),
            Symbol::CASE       => ($c = self::containerOrNull($context)) !== null
                                    ? $this->fanOutLocate(
                                        $c,
                                        fn (string $fqn): ?Location => $this->locateEnumCase($fqn, $symbol->name()),
                                    )
                                    : [],
            Symbol::VARIABLE   => self::asList($this->locateVariable($uri, $symbol->name())),
            default            => [],
        };
    }

    /**
     * Run `$singleLocator` against every constituent class FQN of the
     * type string.  For single-class types (the common case) this
     * just calls the locator once with the input.  For union /
     * intersection / `(A&B)|C` shapes (the Cycle K UX) the splitter
     * yields each FQN in order and the per-FQN results are
     * concatenated, then deduped by (uri, range).
     *
     * @param callable(string): ?Location $singleLocator
     * @return list<Location>
     */
    private function fanOutLocate(string $typeName, callable $singleLocator): array
    {
        $typeName = ltrim($typeName, '\\');
        // Fast path: ClassFqnPredicate-shaped FQN -- skip the splitter
        // entirely.  The splitter's single-class case is correct but
        // adds a string scan + regex per locate.
        if (ClassFqnPredicate::is($typeName)) {
            $location = $singleLocator(ltrim($typeName, '?'));
            return $location === null ? [] : [$location];
        }
        $locations = [];
        $seen = [];
        foreach (TypeUnionSplitter::split($typeName) as $intersectionArm) {
            foreach ($intersectionArm as $componentFqn) {
                $location = $singleLocator($componentFqn);
                if ($location === null) {
                    continue;
                }
                $key = $location->uri . '@' . $location->range->start->line
                    . ':' . $location->range->start->character
                    . '-' . $location->range->end->line
                    . ':' . $location->range->end->character;
                if (isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;
                $locations[] = $location;
            }
        }
        return $locations;
    }

    /**
     * Promote a `?Location` into `list<Location>` for the dispatch
     * arms that don't fan out (FUNCTION / CONSTANT / VARIABLE).
     *
     * @return list<Location>
     */
    private static function asList(?Location $location): array
    {
        return $location === null ? [] : [$location];
    }

    /**
     * Prefer the inferred-Type FQN over the surface symbol name when the
     * type is known.  Falls back to the symbol name (which may still be
     * resolvable -- e.g. in `use App\X;` the symbol is already the FQN).
     */
    private static function preferType(
        \Phpactor\WorseReflection\Core\Inference\NodeContext $context,
        string $fallback,
    ): string {
        // `(string) $type` works for every Type subclass; calling
        // `name()` directly blows up on `MissingType` which doesn't
        // expose `name()`.
        $typeName = (string) $context->type();
        return $typeName !== '' && $typeName !== '<missing>' ? $typeName : $fallback;
    }

    /**
     * Return the resolved FQN of the symbol's containing class/interface
     * (for METHOD/PROPERTY/CASE access), or null when worse-reflection
     * couldn't infer it.  Centralises the MissingType safety check so
     * the dispatch site doesn't crash when an upstream inference
     * failure makes containerType a `MissingType` (which lacks `name()`).
     */
    private static function containerOrNull(\Phpactor\WorseReflection\Core\Inference\NodeContext $context): ?string
    {
        $name = (string) $context->containerType();
        return ($name === '' || $name === '<missing>') ? null : $name;
    }

    /**
     * Resolve a variable cursor to its first definition site in the
     * document.  PhpStorm's native PHP GTD navigates `$x` to wherever
     * `$x` was introduced; we replicate that by walking the cached
     * nikic AST for the document and picking the first node that
     * INTRODUCES the variable (param, assignment target, foreach var,
     * closure-use), ignoring later reads of the same name.
     *
     * Scope caveat: this resolver looks at the whole file, not just
     * the smallest enclosing function/method/closure.  Shadowed
     * variables (same name in two different functions) all resolve
     * to the first one in document order.  Good enough for the
     * common case; revisit with a scope-aware walker when shadowing
     * shows up in user reports.
     */
    /**
     * Detect whether the cursor sits inside a `use function App\foo;` (or
     * group-use `use function App\{foo, bar};`) statement's imported name.
     * Returns the function FQN if so -- the caller routes to
     * `locateFunction()` to bypass worse-reflection's misclassification of
     * the imported name as Symbol::CLASS_.
     *
     * `$fallbackName` is the symbol name worse-reflection reported (already
     * an FQN like `App\foo`) -- used when the AST walk identifies the
     * `use function` context but we still need a name to look up.
     */
    private function useFunctionFqnAtOffset(string $uri, int $byteOffset, string $fallbackName): ?string
    {
        if (!$this->workspace->has($uri)) {
            return null;
        }
        $item = $this->workspace->get($uri);
        $result = $this->cache->getOrParse($uri, $item->version, $item->text);
        $ast = $result->ast;
        if ($ast === null) {
            $ast = $this->parser->parseTolerant($item->text);
            if ($ast === null) {
                return null;
            }
        }

        $visitor = new class($byteOffset) extends NodeVisitorAbstract {
            public ?string $fqn = null;
            private bool $insideUseFunction = false;
            private string $groupPrefix = '';

            public function __construct(private readonly int $offset)
            {
            }

            public function enterNode(Node $node): null
            {
                if ($node instanceof Node\Stmt\Use_ && $node->type === Node\Stmt\Use_::TYPE_FUNCTION) {
                    $this->insideUseFunction = true;
                }
                if ($node instanceof Node\Stmt\GroupUse && $node->type === Node\Stmt\Use_::TYPE_FUNCTION) {
                    $this->insideUseFunction = true;
                    $this->groupPrefix = $node->prefix->toString();
                }
                if (!$this->insideUseFunction || $this->fqn !== null) {
                    return null;
                }
                if ($node instanceof Node\UseItem) {
                    $nameStart = $node->name->getStartFilePos();
                    $nameEnd = $node->name->getEndFilePos();
                    if ($nameStart >= 0 && $this->offset >= $nameStart && $this->offset <= $nameEnd) {
                        $name = $node->name->toString();
                        $this->fqn = $this->groupPrefix !== ''
                            ? $this->groupPrefix . '\\' . $name
                            : $name;
                    }
                }
                return null;
            }

            public function leaveNode(Node $node): null
            {
                if ($node instanceof Node\Stmt\Use_ && $node->type === Node\Stmt\Use_::TYPE_FUNCTION) {
                    $this->insideUseFunction = false;
                }
                if ($node instanceof Node\Stmt\GroupUse && $node->type === Node\Stmt\Use_::TYPE_FUNCTION) {
                    $this->insideUseFunction = false;
                    $this->groupPrefix = '';
                }
                return null;
            }
        };
        $traverser = new NodeTraverser();
        $traverser->addVisitor($visitor);
        $traverser->traverse($ast);
        return $visitor->fqn ?? null;
    }

    private function locateVariable(string $uri, string $varName): ?Location
    {
        if (!$this->workspace->has($uri)) {
            return null;
        }
        $item = $this->workspace->get($uri);
        $result = $this->cache->getOrParse($uri, $item->version, $item->text);
        if ($result->ast === null) {
            return null;
        }

        $finder = new class($varName) extends NodeVisitorAbstract {
            public ?Variable $hit = null;

            public function __construct(private readonly string $varName)
            {
            }

            public function enterNode(Node $node): null
            {
                if ($this->hit !== null) {
                    return null;
                }

                // Function / method / closure parameters: `function f($x)` introduces $x.
                if ($node instanceof Param
                    && $node->var instanceof Variable
                    && $node->var->name === $this->varName
                ) {
                    $this->hit = $node->var;
                    return null;
                }

                // Assignment LHS: `$x = ...` introduces (or re-introduces) $x.
                if ($node instanceof Assign
                    && $node->var instanceof Variable
                    && $node->var->name === $this->varName
                ) {
                    $this->hit = $node->var;
                    return null;
                }

                // `foreach ($arr as $k => $v)` -- both $k (if present) and $v are introductions.
                if ($node instanceof Foreach_) {
                    if ($node->keyVar instanceof Variable && $node->keyVar->name === $this->varName) {
                        $this->hit = $node->keyVar;
                        return null;
                    }
                    if ($node->valueVar instanceof Variable && $node->valueVar->name === $this->varName) {
                        $this->hit = $node->valueVar;
                    }
                    return null;
                }

                // `function () use ($x)` brings $x into the closure body.
                if ($node instanceof ClosureUse
                    && $node->var instanceof Variable
                    && $node->var->name === $this->varName
                ) {
                    $this->hit = $node->var;
                }

                return null;
            }
        };

        $traverser = new NodeTraverser();
        $traverser->addVisitor($finder);
        $traverser->traverse($result->ast);

        if ($finder->hit === null) {
            return null;
        }

        $start = $finder->hit->getStartFilePos();
        $end = $finder->hit->getEndFilePos() + 1;

        $positionMap = new PositionMap($item->text);
        [$startLine, $startChar] = $positionMap->offsetToPosition($start);
        [$endLine, $endChar] = $positionMap->offsetToPosition($end);

        return new Location(
            $uri,
            new Range(
                new Position($startLine, $startChar),
                new Position($endLine, $endChar),
            ),
        );
    }

    private function locateClass(string $fqn): ?Location
    {
        // Cycle C: gate at the locator entry point.  `resolveInner`'s
        // Symbol::CLASS_ dispatch funnels both inferred-type FQNs
        // (which `resolveTypeInner` may have skipped via isClassFqn)
        // and surface symbol names through here; ensure neither path
        // hits the locator with a union / intersection / scalar-
        // literal shape.
        if (!ClassFqnPredicate::is($fqn)) {
            return null;
        }
        try {
            $class = $this->reflector->reflectClassLike($fqn);
            return $this->classNameRange($class, $fqn);
        } catch (NotFound | SourceNotFound) {
            // Fall through to the constant fallback below.
        }

        // Worse-reflection classifies bare uppercase identifiers as
        // `Symbol::CLASS_` even when they're actually constant references
        // (`echo PHP_EOL;`, `if (DEBUG) ...`).  The dispatch routes
        // through us; we just failed to find a class.  Before reporting
        // null, retry as a constant -- with both the original FQN and
        // its short-name fallback (matching PHP's global-namespace
        // resolution for constants inside namespaced files).
        //
        // Prod evidence: GTD on `PHP_EOL` inside `namespace App\Demos`
        // produced `App\Demos\PHP_EOL` as the lookup name; both the
        // namespaced lookup and the bare lookup must be tried before
        // we admit defeat.
        $constant = self::tryReflectConstant($this->reflector, $fqn);
        if ($constant === null) {
            return null;
        }
        $position = $constant->position();
        return $this->locationFromSource(
            $constant->sourceCode(),
            $position->start()->toInt(),
            $position->end()->toInt(),
        );
    }

    private function locateFunction(string $fqn): ?Location
    {
        try {
            $function = $this->reflector->reflectFunction($fqn);
        } catch (NotFound | SourceNotFound) {
            return null;
        }
        return $this->functionNameRange($function);
    }

    private function locateMethod(string $classFqn, string $methodName): ?Location
    {
        // Cycle C: receiver inferred type can be a union/intersection.
        if (!ClassFqnPredicate::is($classFqn)) {
            return null;
        }
        try {
            $class = $this->reflector->reflectClassLike($classFqn);
            $method = $class->methods()->get($methodName);
        } catch (Throwable) {
            return null;
        }
        return $this->memberNameRange($method->declaringClass()->sourceCode(), $method->nameRange());
    }

    private function locateProperty(?string $classFqn, string $propertyName): ?Location
    {
        if ($classFqn === null) {
            return null;
        }
        // Cycle C: same receiver-inference gate as locateMethod.
        if (!ClassFqnPredicate::is($classFqn)) {
            return null;
        }
        try {
            $class = $this->reflector->reflectClassLike($classFqn);
            if (!$class->isClass() && !$class->isInterface() && !$class->isTrait()) {
                return null;
            }
            $property = $class->properties()->get($propertyName);
        } catch (Throwable) {
            return null;
        }
        return $this->memberNameRange($property->declaringClass()->sourceCode(), $property->nameRange());
    }

    private function locateConstant(\Phpactor\WorseReflection\Core\Inference\NodeContext $context, string $name): ?Location
    {
        // Two shapes: class constants `Foo::BAR` (containerType resolves)
        // OR global constants `BAR` (containerType is missing/empty -- fall
        // through to top-level reflectConstant).
        $containerName = self::containerOrNull($context);
        if ($containerName !== null) {
            // Cycle C: gate against union/intersection container types.
            if (!ClassFqnPredicate::is($containerName)) {
                return null;
            }
            try {
                $class = $this->reflector->reflectClassLike($containerName);
                $constant = $class->constants()->get($name);
            } catch (Throwable) {
                return null;
            }
            return $this->memberNameRange($constant->declaringClass()->sourceCode(), $constant->nameRange());
        }

        $constant = self::tryReflectConstant($this->reflector, $name);
        if ($constant === null) {
            return null;
        }
        // ReflectionDeclaredConstant exposes position via AbstractReflectedNode.
        $position = $constant->position();
        return $this->locationFromSource($constant->sourceCode(), $position->start()->toInt(), $position->end()->toInt());
    }

    /**
     * Resolve a constant via worse-reflection, with the same global-
     * namespace fallback PHP's runtime applies at call time.
     *
     * Worse-reflection's NameResolver attaches the enclosing namespace
     * to every bare constant reference -- a `PHP_EOL` mentioned inside
     * `namespace App\Demos` becomes `App\Demos\PHP_EOL` as the symbol
     * name worse-reflection asks the locator for.  But PHP's runtime
     * falls back to the GLOBAL `PHP_EOL` when the namespaced form isn't
     * defined, and the stub locator only knows the global form.  Without
     * this retry, `\PHP_EOL` (and every other namespaced reference to a
     * built-in constant) GTDs to null and PhpStorm reports "Cannot find
     * declaration to go to."
     *
     * The retry uses the LAST segment after the trailing `\` (the
     * short name).  We only retry when the original was namespaced --
     * a bare `Foo` that doesn't resolve is genuinely unknown, not a
     * global-namespace fallback candidate.
     */
    private static function tryReflectConstant(
        \Phpactor\WorseReflection\Reflector $reflector,
        string $name,
    ): ?\Phpactor\WorseReflection\Core\Reflection\ReflectionDeclaredConstant {
        try {
            return $reflector->reflectConstant($name);
        } catch (NotFound | SourceNotFound) {
            // fall through to global retry
        }
        $needle = ltrim($name, '\\');
        $lastBackslash = strrpos($needle, '\\');
        if ($lastBackslash === false) {
            return null;
        }
        $shortName = substr($needle, $lastBackslash + 1);
        if ($shortName === '') {
            return null;
        }
        try {
            return $reflector->reflectConstant($shortName);
        } catch (NotFound | SourceNotFound) {
            return null;
        }
    }

    private function locateEnumCase(string $enumFqn, string $caseName): ?Location
    {
        // Cycle C: gate enum's container FQN identically.
        if (!ClassFqnPredicate::is($enumFqn)) {
            return null;
        }
        try {
            $class = $this->reflector->reflectClassLike($enumFqn);
            if (!$class->isEnum()) {
                return null;
            }
            $case = $class->members()->byMemberType(Symbol::CASE)->get($caseName);
        } catch (Throwable) {
            return null;
        }
        return $this->memberNameRange($case->declaringClass()->sourceCode(), $case->nameRange());
    }

    /**
     * Compute the LSP location of the class identifier token within its
     * declaration. worse-reflection's `position()` covers the whole class
     * body (`class … { … }`); we narrow that to the identifier so the
     * caret lands on the name, matching the existing
     * `WorkspaceSymbols::findClassByName` convention used by xphp-specific
     * GTD (and matching PhpStorm's native PHP GTD behaviour).
     */
    private function classNameRange(ReflectionClassLike $class, string $fqn): ?Location
    {
        $source = $class->sourceCode();
        $position = $class->position();
        $shortName = self::shortName($fqn);
        $sourceText = (string) $source;
        $offset = strpos($sourceText, $shortName, $position->start()->toInt());
        if ($offset === false) {
            // Fallback to the whole class range -- unusual but better than
            // returning null on a successfully-reflected class.
            return $this->locationFromSource($source, $position->start()->toInt(), $position->end()->toInt());
        }
        return $this->locationFromSource($source, $offset, $offset + strlen($shortName));
    }

    private function functionNameRange(ReflectionFunction $function): ?Location
    {
        $source = $function->sourceCode();
        $position = $function->position();
        $shortName = self::shortName((string) $function->name());
        $sourceText = (string) $source;
        $offset = strpos($sourceText, $shortName, $position->start()->toInt());
        if ($offset === false) {
            return $this->locationFromSource($source, $position->start()->toInt(), $position->end()->toInt());
        }
        return $this->locationFromSource($source, $offset, $offset + strlen($shortName));
    }

    private function memberNameRange(
        \Phpactor\TextDocument\TextDocument $source,
        \Phpactor\TextDocument\ByteOffsetRange $nameRange,
    ): Location {
        return $this->locationFromSource($source, $nameRange->start()->toInt(), $nameRange->end()->toInt());
    }

    private function locationFromSource(
        \Phpactor\TextDocument\TextDocument $source,
        int $start,
        int $end,
    ): Location {
        $text = (string) $source;
        $positionMap = new PositionMap($text);
        [$startLine, $startChar] = $positionMap->offsetToPosition($start);
        [$endLine, $endChar] = $positionMap->offsetToPosition($end);
        return new Location(
            (string) $source->uri(),
            new Range(
                new Position($startLine, $startChar),
                new Position($endLine, $endChar),
            ),
        );
    }

    private static function shortName(string $fqn): string
    {
        $segments = explode('\\', ltrim($fqn, '\\'));
        return $segments[count($segments) - 1];
    }
}
