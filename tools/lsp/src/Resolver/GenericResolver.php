<?php

declare(strict_types=1);

namespace XPHP\Lsp\Resolver;

use PhpParser\Node;
use PhpParser\Node\ComplexType;
use PhpParser\Node\Expr\Assign;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PhpParser\Node\IntersectionType;
use PhpParser\Node\Name;
use PhpParser\Node\NullableType;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\UnionType;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;
use Phpactor\LanguageServer\Core\Workspace\Workspace as PhpactorWorkspace;
use XPHP\Lsp\Analyzer\ParsedDocumentCache;
use XPHP\Transpiler\Monomorphize\Specializer;
use XPHP\Transpiler\Monomorphize\TypeParam;
use XPHP\Transpiler\Monomorphize\TypeRef;
use XPHP\Transpiler\Monomorphize\XphpSourceParser;

/**
 * Monomorphization-aware variable type substitution for the LSP.
 *
 * Worse-reflection sees the xphp-stripped source, where `<T>` clauses are
 * blanked to whitespace -- so the type-arg context (`T = User`) baked into
 * `new Collection<User>(...)` is gone by the time it computes the inferred
 * type of a downstream variable.  Result: `$user = $users->first()` shows
 * `?T` (the unresolved placeholder) instead of `?User` (the substituted
 * concrete).
 *
 * This resolver walks the *unstripped* AST that `XphpSourceParser` cached
 * via `ParsedDocumentCache` -- which still carries `ATTR_GENERIC_ARGS` on
 * `new` Name nodes and `ATTR_GENERIC_PARAMS` on ClassLike templates --
 * tracks per-variable bindings, and substitutes via the same
 * `Specializer::substituteTypeRef` the compile-time monomorphizer uses.
 * That keeps LSP-time and compile-time substitution semantics in lockstep
 * (a divergence here would be a bug factory).
 *
 * Scope: in-same-file assignments only, one method hop maximum.
 *
 * Supported:
 *   $x = new Generic<TypeArgs>(...);
 *   $y = $x->method(...);    // returns substituted type
 *
 * Out of scope (returns null, fallback to GenericParamRegistry::prettify):
 *   - chained calls in one statement (`$a->b()->c()`)
 *   - static method calls (`Cls::method<T>(...)`)
 *   - generic functions (`identity<T>(...)`)
 *   - param-typed scope entry (`function f(Collection<User> $users)`)
 *   - closure-captured variables
 *   - filesystem-only classes (ClassLikeLookup needs the file open)
 */
final class GenericResolver
{
    /**
     * @var array<string, array{version: int, bindings: array<string, VarBinding|ResolvedType>}>
     */
    private array $cache = [];

    public function __construct(
        private readonly PhpactorWorkspace $workspace,
        private readonly ParsedDocumentCache $documents,
        private readonly ClassLikeLookup $classes,
        private readonly XphpSourceParser $parser,
    ) {
    }

    /**
     * Return the substituted display string for `$varName` at the current
     * version of `$uri`, or null when the resolver can't model the
     * variable's RHS (caller falls back to its existing path).
     */
    public function resolveVariable(string $uri, string $varName): ?string
    {
        if (!$this->workspace->has($uri)) {
            return null;
        }
        $item = $this->workspace->get($uri);
        $bindings = $this->bindingsFor($uri, $item->version, $item->text);

        $binding = $bindings[$varName] ?? null;
        if ($binding === null) {
            return null;
        }
        // A direct `$x = new Generic<...>(...)` produces a VarBinding -- we
        // can render the receiver type with its type-arg list spelt out,
        // e.g. "App\Containers\Collection<App\Models\User>".  The existing
        // path renders just `App\Containers\Collection` (the worse-reflection
        // view); ours adds the type-arg context.
        if ($binding instanceof VarBinding) {
            return $this->renderBinding($binding);
        }
        return $binding->render();
    }

    /**
     * Resolve the substituted concrete type of `$varName` as a
     * `ResolvedType`.  Mirrors `resolveVariable` but returns the
     * underlying `{TypeRef, nullable}` so the completion path can read
     * `ref->name` directly for `reflectClassLike` without parsing the
     * display string back.
     *
     * Returns null when the resolver has nothing for the variable -- the
     * caller should fall back to the unsubstituted receiver from
     * worse-reflection.
     */
    public function resolveVariableTypeRef(string $uri, string $varName): ?ResolvedType
    {
        if (!$this->workspace->has($uri)) {
            return null;
        }
        $item = $this->workspace->get($uri);
        $bindings = $this->bindingsFor($uri, $item->version, $item->text);

        $binding = $bindings[$varName] ?? null;
        if ($binding === null) {
            return null;
        }
        // VarBinding: variable holds an instance of the generic class
        // itself.  Surface it as a non-nullable TypeRef of the class FQN
        // so the completion path can reflect on the class directly.
        if ($binding instanceof VarBinding) {
            return new ResolvedType(new TypeRef($binding->classFqn), false);
        }
        // ResolvedType: variable holds the substituted result of a prior
        // method call.  Return as-is.
        return $binding;
    }

    /**
     * Resolve the substituted return-type display for the `MethodCall`
     * enclosing `$byteOffset`.  Returns null when the cursor isn't on a
     * method-call name, the receiver isn't a tracked variable, or the
     * return type can't be modelled -- in all cases the caller should
     * fall back to the unsubstituted render.
     *
     * Used by hover on a method-call token (e.g. cursor on `first` in
     * `$users->first()`).  Where `renderVariable` answers "what type does
     * the LHS variable end up with", this answers "what type does this
     * specific call site evaluate to" -- the same substitution, applied
     * one statement earlier in the chain.
     */
    public function resolveMethodReturnTypeAt(string $uri, int $byteOffset): ?string
    {
        if (!$this->workspace->has($uri)) {
            return null;
        }
        $item = $this->workspace->get($uri);
        $bindings = $this->bindingsFor($uri, $item->version, $item->text);

        $result = $this->documents->getOrParse($uri, $item->version, $item->text);
        if ($result->ast === null) {
            return null;
        }
        $call = self::findEnclosingMethodCallNameAt($result->ast, $byteOffset);
        if ($call === null) {
            return null;
        }
        $resolved = self::resolveMethodCall($call, $bindings, $this->classes);
        if ($resolved === null) {
            return null;
        }
        return $resolved->render();
    }

    /**
     * Walk the AST looking for a `MethodCall` whose method-name identifier
     * covers `$byteOffset`.  The cursor must land ON the name token --
     * landing on the receiver or the call's args returns null.  Matches
     * IDE behaviour where "hover the method" means hovering its identifier.
     *
     * @param list<Node\Stmt> $ast
     */
    private static function findEnclosingMethodCallNameAt(array $ast, int $byteOffset): ?MethodCall
    {
        $visitor = new class($byteOffset) extends NodeVisitorAbstract {
            public ?MethodCall $hit = null;

            public function __construct(private readonly int $offset)
            {
            }

            public function enterNode(Node $node): null
            {
                if ($this->hit !== null) {
                    return null;
                }
                if (!$node instanceof MethodCall) {
                    return null;
                }
                $name = $node->name;
                if (!$name instanceof Identifier) {
                    return null;
                }
                $start = $name->getStartFilePos();
                $end = $name->getEndFilePos();
                if ($start < 0 || $end < 0) {
                    return null;
                }
                // Inclusive end-of-token + 1 for cursor-just-past-end
                // (LSP positions point between characters, not at them).
                if ($this->offset >= $start && $this->offset <= $end + 1) {
                    $this->hit = $node;
                }
                return null;
            }
        };

        $traverser = new NodeTraverser();
        $traverser->addVisitor($visitor);
        $traverser->traverse($ast);
        return $visitor->hit;
    }

    /**
     * @return array<string, VarBinding|ResolvedType>
     */
    private function bindingsFor(string $uri, int $version, string $text): array
    {
        if (isset($this->cache[$uri]) && $this->cache[$uri]['version'] === $version) {
            return $this->cache[$uri]['bindings'];
        }
        $result = $this->documents->getOrParse($uri, $version, $text);
        $ast = $result->ast;
        if ($ast === null) {
            // The cache's strict parse failed -- typical when the user is
            // mid-edit (e.g. cursor on `$u->` with no terminator).  Fall
            // back to tolerant parsing so we still see the completed
            // statements ABOVE the broken line (which is where the `new
            // Generic<...>(...)` binding usually lives).  Without this
            // fallback every completion the user triggers while typing
            // resets bindings to empty.
            try {
                $ast = $this->parser->parseTolerant($text);
            } catch (\Throwable) {
                $ast = null;
            }
        }
        $bindings = $ast === null ? [] : $this->build($ast);
        $this->cache[$uri] = ['version' => $version, 'bindings' => $bindings];
        return $bindings;
    }

    /**
     * @param list<Node\Stmt> $ast
     * @return array<string, VarBinding|ResolvedType>
     */
    private function build(array $ast): array
    {
        $bindings = [];
        $visitor = new class($bindings, $this->classes) extends NodeVisitorAbstract {
            /**
             * @param array<string, VarBinding|ResolvedType> $bindings
             */
            public function __construct(
                public array &$bindings,
                private readonly ClassLikeLookup $classes,
            ) {
            }

            public function enterNode(Node $node): null
            {
                if (!$node instanceof Assign) {
                    return null;
                }
                $lhs = $node->var;
                if (!$lhs instanceof Variable || !is_string($lhs->name)) {
                    return null;
                }
                $name = $lhs->name;

                $rhs = $node->expr;
                if ($rhs instanceof New_) {
                    $binding = GenericResolver::buildFromNew($rhs, $this->classes);
                    if ($binding !== null) {
                        $this->bindings[$name] = $binding;
                    }
                    return null;
                }
                if ($rhs instanceof MethodCall) {
                    $resolved = GenericResolver::resolveMethodCall(
                        $rhs,
                        $this->bindings,
                        $this->classes,
                    );
                    if ($resolved !== null) {
                        $this->bindings[$name] = $resolved;
                    }
                }
                return null;
            }
        };

        $traverser = new NodeTraverser();
        $traverser->addVisitor($visitor);
        $traverser->traverse($ast);
        return $visitor->bindings;
    }

    /**
     * Build a `VarBinding` from a `new Generic<TypeArgs>(...)` site.
     * Returns null when the class isn't a known template, the args don't
     * match the params, or the class declaration can't be located.
     *
     * @internal called from the visitor closure.
     */
    public static function buildFromNew(New_ $new, ClassLikeLookup $classes): ?VarBinding
    {
        $class = $new->class;
        if (!$class instanceof Name) {
            return null;
        }
        $templateFqn = $class->getAttribute(XphpSourceParser::ATTR_TEMPLATE_FQN);
        $args = $class->getAttribute(XphpSourceParser::ATTR_GENERIC_ARGS);
        if (!is_string($templateFqn) || !is_array($args) || $args === []) {
            return null;
        }
        $classLike = $classes->find($templateFqn);
        if ($classLike === null) {
            return null;
        }
        $params = $classLike->getAttribute(XphpSourceParser::ATTR_GENERIC_PARAMS);
        if (!is_array($params) || count($params) !== count($args)) {
            return null;
        }
        $paramMap = [];
        foreach ($params as $i => $param) {
            if (!$param instanceof TypeParam || !($args[$i] instanceof TypeRef)) {
                return null;
            }
            $paramMap[$param->name] = $args[$i];
        }
        return new VarBinding($templateFqn, $paramMap);
    }

    /**
     * Resolve a `$x->method(...)` RHS, looking up `$x`'s binding and
     * substituting the method's return type.  Returns a `ResolvedType` or
     * null when the call can't be modelled.
     *
     * @param array<string, VarBinding|ResolvedType> $bindings
     * @internal called from the visitor closure.
     */
    public static function resolveMethodCall(
        MethodCall $call,
        array $bindings,
        ClassLikeLookup $classes,
    ): ?ResolvedType {
        $receiver = $call->var;
        if (!$receiver instanceof Variable || !is_string($receiver->name)) {
            return null;
        }
        $binding = $bindings[$receiver->name] ?? null;
        if (!$binding instanceof VarBinding) {
            return null;
        }
        if (!$call->name instanceof Identifier) {
            return null;
        }
        $methodName = $call->name->toString();
        $classLike = $classes->find($binding->classFqn);
        if ($classLike === null) {
            return null;
        }
        $method = self::findMethod($classLike, $methodName);
        if ($method === null) {
            return null;
        }
        $returnType = $method->returnType;
        if ($returnType === null) {
            return null;
        }
        $paramNames = [];
        $params = $classLike->getAttribute(XphpSourceParser::ATTR_GENERIC_PARAMS);
        if (is_array($params)) {
            foreach ($params as $p) {
                if ($p instanceof TypeParam) {
                    $paramNames[] = $p->name;
                }
            }
        }
        [$nullable, $ref] = self::returnTypeToRef($returnType, $paramNames) ?? [null, null];
        if ($ref === null) {
            return null;
        }
        $substituted = Specializer::substituteTypeRef($ref, $binding->paramMap);
        return new ResolvedType($substituted, $nullable);
    }

    private static function findMethod(ClassLike $class, string $name): ?ClassMethod
    {
        foreach ($class->getMethods() as $method) {
            if (strcasecmp($method->name->toString(), $name) === 0) {
                return $method;
            }
        }
        return null;
    }

    /**
     * Convert a nikic return-type node into a (nullable, TypeRef) tuple.
     *
     * `NullableType` is unwrapped and the nullable flag is tracked
     * separately because `TypeRef` (compile-pipeline value type) doesn't
     * encode nullability -- monomorphization stamps the `?` at type-hint
     * emission, not in the ref.  Keeping it separate also lets the ref
     * pass cleanly through `Specializer::substituteTypeRef` without the
     * `?` polluting the param lookup key.
     *
     * Returns null for shapes we don't model (union, intersection).
     *
     * @param list<string> $paramNames  generic param names on the enclosing class
     * @return array{0: bool, 1: TypeRef}|null
     */
    private static function returnTypeToRef(Node $type, array $paramNames): ?array
    {
        if ($type instanceof NullableType) {
            $inner = self::returnTypeToRef($type->type, $paramNames);
            if ($inner === null) {
                return null;
            }
            return [true, $inner[1]];
        }
        if ($type instanceof Identifier) {
            return [false, new TypeRef($type->toString(), [], isScalar: true)];
        }
        if ($type instanceof Name) {
            $raw = $type->toString();
            if (in_array($raw, $paramNames, true)) {
                return [false, new TypeRef($raw, [], isTypeParam: true)];
            }
            // XphpSourceParser doesn't run nikic's NameResolver, so a bare
            // `User` in a return position stays a single-segment Name.
            // We hand back the raw name; downstream substitution treats
            // it as a real class.  Most generic class declarations either
            // return scalars, the template param, or fully-qualified
            // class names via use statements that xphp transpilation
            // doesn't touch -- so the bare-name case is mostly the
            // type-param case (handled above).
            return [false, new TypeRef($raw)];
        }
        if ($type instanceof UnionType || $type instanceof IntersectionType || $type instanceof ComplexType) {
            return null;
        }
        return null;
    }

    private function renderBinding(VarBinding $binding): string
    {
        if ($binding->paramMap === []) {
            return $binding->classFqn;
        }
        $args = array_map(
            static fn (TypeRef $r): string => $r->toDisplayString(),
            array_values($binding->paramMap),
        );
        return $binding->classFqn . '<' . implode(', ', $args) . '>';
    }
}
