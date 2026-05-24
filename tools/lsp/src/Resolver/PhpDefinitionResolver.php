<?php

declare(strict_types=1);

namespace XPHP\Lsp\Resolver;

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

    public function resolve(string $uri, int $line, int $character): ?Location
    {
        // Belt-and-braces: the resolver calls into third-party
        // worse-reflection which has its own surprises on edge cases
        // (e.g. `MissingType::name()` -- the original cause of the LSP
        // crash captured in xphp-20260524-125122-098.log).  A single
        // top-level catch makes any unexpected internal failure surface
        // as "no result" instead of a fatal that poisons the LSP
        // transport via stdout.
        try {
            return $this->resolveInner($uri, $line, $character);
        } catch (Throwable) {
            return null;
        }
    }

    private function resolveInner(string $uri, int $line, int $character): ?Location
    {
        $document = $this->workspace->has($uri) ? $this->workspace->get($uri) : null;
        if ($document === null) {
            return null;
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
            return null;
        }

        $context = $reflectionOffset->nodeContext();
        $symbol = $context->symbol();

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
        return match ($symbol->symbolType()) {
            Symbol::CLASS_     => $this->locateClass(self::preferType($context, $symbol->name())),
            Symbol::FUNCTION   => $this->locateFunction($symbol->name()),
            Symbol::METHOD     => ($c = self::containerOrNull($context)) !== null
                                    ? $this->locateMethod($c, $symbol->name())
                                    : null,
            Symbol::PROPERTY   => $this->locateProperty(
                                    // Resolver-first: substituted receiver wins
                                    // when GenericResolver has a binding for
                                    // `$x->method()?->prop` (Phase 0.7).  Falls
                                    // back to worse-reflection's containerType.
                                    $this->genericResolver->resolvePropertyReceiverClassAt($uri, $offset)
                                        ?? self::containerOrNull($context),
                                    $symbol->name(),
                                ),
            Symbol::CONSTANT   => $this->locateConstant($context, $symbol->name()),
            Symbol::CASE       => ($c = self::containerOrNull($context)) !== null
                                    ? $this->locateEnumCase($c, $symbol->name())
                                    : null,
            Symbol::VARIABLE   => $this->locateVariable($uri, $symbol->name()),
            default            => null,
        };
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
        try {
            $class = $this->reflector->reflectClassLike($fqn);
        } catch (NotFound | SourceNotFound) {
            return null;
        }
        return $this->classNameRange($class, $fqn);
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
            try {
                $class = $this->reflector->reflectClassLike($containerName);
                $constant = $class->constants()->get($name);
            } catch (Throwable) {
                return null;
            }
            return $this->memberNameRange($constant->declaringClass()->sourceCode(), $constant->nameRange());
        }

        try {
            $constant = $this->reflector->reflectConstant($name);
        } catch (NotFound | SourceNotFound) {
            return null;
        }
        // ReflectionDeclaredConstant exposes position via AbstractReflectedNode.
        $position = $constant->position();
        return $this->locationFromSource($constant->sourceCode(), $position->start()->toInt(), $position->end()->toInt());
    }

    private function locateEnumCase(string $enumFqn, string $caseName): ?Location
    {
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
