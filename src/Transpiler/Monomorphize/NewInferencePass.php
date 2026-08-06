<?php

declare(strict_types=1);

namespace XPHP\Transpiler\Monomorphize;

use PhpParser\Node;
use PhpParser\Node\Expr\Assign;
use PhpParser\Node\Expr\AssignOp;
use PhpParser\Node\Expr\AssignRef;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Expr\PostDec;
use PhpParser\Node\Expr\PostInc;
use PhpParser\Node\Expr\PreDec;
use PhpParser\Node\Expr\PreInc;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\FunctionLike;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\GroupUse;
use PhpParser\Node\Stmt\Namespace_;
use PhpParser\Node\Stmt\Property;
use PhpParser\Node\Stmt\Use_;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;

/**
 * Front-end pass that makes the turbofish optional on generic class instantiation: at each bare
 * `new X(...)` whose constructor arguments determine X's type parameters, it infers them and
 * annotates the `new` — attaching `ATTR_GENERIC_ARGS` + `ATTR_TEMPLATE_FQN` exactly as
 * {@see RegistryCollector::synthesizeBareNewIfAllDefaults} does for an all-defaults `new` — so the
 * instantiation collector, and (in compile) the call-site rewriter, treat it as though the
 * turbofish had been written. A `new` it cannot resolve to a complete concrete tuple is left bare,
 * falling back to today's behaviour (all-defaults synthesis, or the missing-type-argument error).
 *
 * It runs after definitions are collected (it needs the template registry) and before
 * instantiations are collected, at the identical point in both `compile()` and `check()`, so the
 * annotation is observed identically in both modes.
 *
 * Argument typing is deliberately conservative and sound. It types literals and `new` expressions
 * (via {@see LiteralTyper}), `$this`-property fetches (from the declared property type), and plain
 * parameter references — but only for a parameter with a concrete declared type that is never
 * reassigned in its function, so a `$x` whose value was overwritten can't be mistyped from its
 * original declaration. Anything else (a local, a reassigned parameter, a call return) is left
 * untyped, and its `new` falls back rather than risk an unsound instantiation.
 */
final class NewInferencePass extends NodeVisitorAbstract implements ExpressionTyper
{
    private NamespaceContext $ctx;
    private readonly LiteralTyper $literalTyper;
    private readonly TypeInference $inference;

    /** @var list<ClassLike> the enclosing class stack, for `$this`-property typing */
    private array $classStack = [];
    /** @var list<array<string, TypeRef>> per-function scope: variable name => trustworthy concrete type */
    private array $scopes = [];

    public function __construct(
        private readonly Registry $registry,
        TypeHierarchy $hierarchy,
    ) {
        $this->ctx = new NamespaceContext();
        $this->literalTyper = new LiteralTyper();
        $this->inference = new TypeInference($hierarchy);
    }

    /** @param array<string, list<Node\Stmt>> $astPerFile */
    public function run(array $astPerFile): void
    {
        foreach ($astPerFile as $ast) {
            $this->ctx = new NamespaceContext();
            $this->classStack = [];
            $this->scopes = [];
            $traverser = new NodeTraverser();
            $traverser->addVisitor($this);
            $traverser->traverse($ast);
        }
    }

    public function enterNode(Node $node): null
    {
        if ($node instanceof Namespace_) {
            $this->ctx->enterNamespace($node->name?->toString());
        }
        if ($node instanceof Use_) {
            $this->ctx->indexUse($node);
        } elseif ($node instanceof GroupUse) {
            $this->ctx->indexGroupUse($node);
        }
        if ($node instanceof ClassLike) {
            $this->classStack[] = $node;
        }
        if ($node instanceof FunctionLike) {
            $this->scopes[] = $this->scopeForFunction($node);
        }
        return null;
    }

    public function leaveNode(Node $node): null
    {
        // Infer on leave (bottom-up): a nested `new` argument must be annotated with its own
        // inferred type arguments BEFORE its enclosing `new` reads it, or `new Box(new Box(5))`
        // would type the inner as the raw `Box` template and infer `Box<Box>` instead of
        // `Box<Box<int>>` — a specialization the explicit turbofish would never produce. The
        // namespace context, class stack, and scope are still in place here: those are popped
        // only when the enclosing ClassLike/FunctionLike leaves, which is strictly later.
        if ($node instanceof New_
            && $node->class instanceof Name
            && $node->class->getAttribute(XphpSourceParser::ATTR_GENERIC_ARGS) === null
        ) {
            $this->tryInferNew($node->class, $node);
        }
        if ($node instanceof FunctionLike) {
            array_pop($this->scopes);
        }
        if ($node instanceof ClassLike) {
            array_pop($this->classStack);
        }
        return null;
    }

    /**
     * Infer and attach the type arguments of a bare `new`, or leave it untouched. The class must
     * resolve to a generic template; a non-generic or unknown class is left for PHP / the bare-new
     * synthesis to handle.
     */
    private function tryInferNew(Name $class, New_ $node): void
    {
        $fqn = $this->ctx->resolveName($class);
        $definition = $this->registry->definition($fqn);
        if ($definition === null || $definition->typeParams === []) {
            return;
        }
        $inferred = $this->inference->infer(
            $definition->typeParams,
            self::constructorParams($definition->templateAst),
            $node->args,
            $this,
        );
        if ($inferred !== null) {
            // Mirror synthesizeBareNewIfAllDefaults / an explicit turbofish: the collector records
            // the instantiation off these two attributes, padding any defaulted tail itself.
            $class->setAttribute(XphpSourceParser::ATTR_GENERIC_ARGS, $inferred);
            $class->setAttribute(XphpSourceParser::ATTR_TEMPLATE_FQN, $fqn);
        }
    }

    public function typeOf(Node\Expr $expr): ?TypeRef
    {
        $literal = $this->literalTyper->typeOf($expr);
        if ($literal !== null) {
            return $literal;
        }
        if ($expr instanceof Variable && is_string($expr->name)) {
            $scope = $this->scopes === [] ? [] : $this->scopes[array_key_last($this->scopes)];
            return $scope[$expr->name] ?? null;
        }
        $propName = self::thisPropertyName($expr);
        if ($propName !== null) {
            return $this->typeOfThisProperty($propName);
        }
        return null;
    }

    /**
     * The property name of a `$this->prop` fetch, or null for any other expression. A defensive
     * AST-shape guard: the `&&` chain narrows to exactly a plain `$this->name` fetch, excluding a
     * `$other->prop`, a `$this->expr->prop`, a `$this->$dynamic`, or a method call.
     */
    private static function thisPropertyName(Node\Expr $expr): ?string
    {
        // @infection-ignore-all -- each `&&` guards a distinct AST shape; flipping one to `||`
        // either needs a receiver/name form that never appears as a plain property-fetch argument
        // (a `$this->a->b` or variable-variable) or is observationally identical here.
        if ($expr instanceof PropertyFetch
            && $expr->var instanceof Variable
            && $expr->var->name === 'this'
            && $expr->name instanceof Identifier
        ) {
            return $expr->name->toString();
        }
        return null;
    }

    /**
     * Build a function's variable-type scope: every parameter with a concrete declared type that is
     * never reassigned in the body. A reassigned parameter is dropped — after `$x = …` it no longer
     * holds its declared type, so inferring from that type would be unsound.
     *
     * @return array<string, TypeRef>
     */
    private function scopeForFunction(FunctionLike $fn): array
    {
        $typeParamNames = $this->enclosingTypeParamNames($fn);
        $reassigned = self::reassignedNames($fn);
        $scope = [];
        foreach ($fn->getParams() as $param) {
            // @infection-ignore-all LogicalOr -- defensive: a real parameter always has a Variable
            // var with a string name (Error vars / expression names only arise on parse failures
            // that never reach this pass), so both operands are always false and || vs && is
            // observationally identical.
            if (!$param->var instanceof Variable || !is_string($param->var->name)) {
                continue;
            }
            $name = $param->var->name;
            if (isset($reassigned[$name])) {
                continue;
            }
            $type = TypeInference::paramTypeRef($param->type, $typeParamNames);
            if ($type !== null && $type->isConcrete()) {
                $scope[$name] = $type;
            }
        }
        return $scope;
    }

    /** The declared type of `$this->$propName`, or null when it is not a determinable concrete type. */
    private function typeOfThisProperty(string $propName): ?TypeRef
    {
        if ($this->classStack === []) {
            return null;
        }
        $class = $this->classStack[array_key_last($this->classStack)];
        $typeParamNames = self::typeParamNamesOf($class);
        foreach ($class->stmts as $stmt) {
            if (!$stmt instanceof Property) {
                continue;
            }
            foreach ($stmt->props as $prop) {
                if ($prop->name->toString() !== $propName) {
                    continue;
                }
                $type = TypeInference::paramTypeRef($stmt->type, $typeParamNames);
                return $type !== null && $type->isConcrete() ? $type : null;
            }
        }
        return null;
    }

    /**
     * The type-parameter names in scope for a function: its enclosing class's parameters plus its
     * own method/function-level generic parameters. A parameter or property typed by one of these
     * is abstract here, so it never becomes a (spuriously concrete) inference source.
     *
     * @return array<string, true>
     */
    private function enclosingTypeParamNames(FunctionLike $fn): array
    {
        $names = $this->classStack === []
            ? []
            : self::typeParamNamesOf($this->classStack[array_key_last($this->classStack)]);
        $methodParams = $fn->getAttribute(XphpSourceParser::ATTR_METHOD_GENERIC_PARAMS);
        if (is_array($methodParams)) {
            /** @var list<TypeParam> $methodParams */
            foreach ($methodParams as $param) {
                // @infection-ignore-all TrueValue -- $names is a set; membership is tested with
                // isset() in paramTypeRef, so the stored value is immaterial.
                $names[$param->name] = true;
            }
        }
        return $names;
    }

    /** @return array<string, true> */
    private static function typeParamNamesOf(ClassLike $class): array
    {
        $params = $class->getAttribute(XphpSourceParser::ATTR_GENERIC_PARAMS);
        $names = [];
        if (is_array($params)) {
            /** @var list<TypeParam> $params */
            foreach ($params as $param) {
                // @infection-ignore-all TrueValue -- $names is a set; membership is tested with
                // isset() in TypeInference::paramTypeRef, so the stored value is immaterial.
                $names[$param->name] = true;
            }
        }
        return $names;
    }

    /**
     * The variable names assigned anywhere in a function body — the conservative "do not trust"
     * set. Covers `=`, `=&`, compound-assign, and inc/dec; nested closures are included too, so a
     * parameter a closure mutates by reference is (safely) not trusted.
     *
     * @return array<string, true>
     */
    private static function reassignedNames(FunctionLike $fn): array
    {
        $stmts = $fn->getStmts();
        // @infection-ignore-all ReturnRemoval -- a bodiless method (interface/abstract) has null
        // stmts; the guard is for the type checker. Removing it is equivalent at runtime because
        // NodeFinder::find(null) returns [] anyway (verified), yielding the same empty result.
        if ($stmts === null) {
            return [];
        }
        $targets = (new NodeFinder())->find($stmts, static fn (Node $n): bool =>
            $n instanceof Assign || $n instanceof AssignRef || $n instanceof AssignOp
            || $n instanceof PreInc || $n instanceof PostInc
            || $n instanceof PreDec || $n instanceof PostDec);
        $names = [];
        foreach ($targets as $target) {
            /** @var Assign|AssignRef|AssignOp|PreInc|PostInc|PreDec|PostDec $target */
            $var = $target->var;
            if ($var instanceof Variable && is_string($var->name)) {
                // @infection-ignore-all TrueValue -- $names is a set; membership is tested with
                // isset() in scopeForFunction, so the stored value is immaterial.
                $names[$var->name] = true;
            }
        }
        return $names;
    }

    /**
     * The parameters of a template's `__construct`, or an empty list when it declares none — the
     * shape inference unifies the constructor arguments against.
     *
     * @return array<Node\Param>
     */
    private static function constructorParams(ClassLike $template): array
    {
        foreach ($template->stmts as $stmt) {
            if ($stmt instanceof ClassMethod && strtolower($stmt->name->toString()) === '__construct') {
                return $stmt->params;
            }
        }
        return [];
    }
}
