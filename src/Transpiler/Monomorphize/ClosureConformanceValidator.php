<?php

declare(strict_types=1);

namespace XPHP\Transpiler\Monomorphize;

use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr\ArrowFunction;
use PhpParser\Node\Expr\Closure;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\NullableType;
use PhpParser\Node\Param;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Function_;
use PhpParser\Node\Stmt\Namespace_;
use PhpParser\Node\Stmt\Return_;
use PhpParser\Node\Stmt\Use_;
use PhpParser\Node\VariadicPlaceholder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;
use RuntimeException;
use XPHP\Diagnostics\Diagnostic;
use XPHP\Diagnostics\DiagnosticCollector;
use XPHP\Diagnostics\Severity;
use XPHP\Diagnostics\SourceLocation;

/**
 * Wires {@see ClosureSignatureConformance} to the source site where a closure
 * LITERAL statically meets a `Closure(...)` target type, and turns a returned
 * {@see ClosureConformanceViolation} into a diagnostic (collect) or a
 * {@see RuntimeException} (throw). Nothing runtime is emitted — the check is a
 * compile-time gate; the `Closure(...)` type has already been erased to
 * `\Closure` by the parser.
 *
 * The one statically decidable site is the **return position** (a factory that
 * declares a `Closure(...)` return and hands back a closure literal):
 *
 *  - `return <literal>;` inside a function / method / closure whose return type
 *    is `Closure(...)` — paired with the innermost enclosing function-like.
 *  - an arrow function `fn(...): Closure(...) => <literal>` whose body IS the
 *    literal (arrows have no `Return_` node).
 *
 * A parameter/property *default* is deliberately NOT a site: PHP requires a
 * default to be a constant expression, and a closure literal is not one, so a
 * `Closure(...)`-typed default can never hold a literal candidate. Other value
 * flows (a variable assigned then passed, a call argument, a property assignment)
 * leave the candidate non-literal at the target, so the model stays gradual
 * (accept). A target that references an enclosing type parameter is checked with
 * those leaves still abstract here (⇒ gradual); the grounded per-specialization
 * re-check happens later in the pipeline.
 */
final class ClosureConformanceValidator
{
    /** Diagnostic code for a closure literal that provably violates its `Closure(...)` target. */
    public const CODE = 'xphp.closure_conformance';

    private readonly ClosureSignatureConformance $engine;

    public function __construct(TypeHierarchy $hierarchy)
    {
        $this->engine = new ClosureSignatureConformance($hierarchy);
    }

    /**
     * Validate one source file's AST. With a {@see DiagnosticCollector} every
     * violation is collected (the `xphp check` path); without one the first
     * violation throws (the fail-fast `compile` path).
     *
     * With `$groundedTypesOnly = true` the engine runs only the type-relation half
     * of conformance (parameter contravariance / return covariance), skipping the
     * grounding-independent arity / by-reference checks. Used for the grounded
     * per-specialization re-check, where structural mismatches were already decided
     * at the abstract template and only the leaf types change under substitution —
     * so they must not be re-reported at a second (specialized) location.
     *
     * @param list<Node\Stmt> $ast
     */
    public function validateFile(array $ast, string $file, ?DiagnosticCollector $diagnostics, bool $groundedTypesOnly = false): void
    {
        // A fresh context is already global-scope (no namespace, no uses); a
        // `namespace` node encountered during the walk re-scopes it.
        $ctx = new NamespaceContext();
        $traverser = new NodeTraverser();
        $traverser->addVisitor($this->buildVisitor($ctx, $file, $diagnostics, $groundedTypesOnly));
        $traverser->traverse($ast);
    }

    /**
     * The resolved {@see ClosureSignature} a type node carries as its target, or
     * null when the node is not a `Closure(...)` type. A `?Closure(...)` tags the
     * inner {@see Name}, so a nullable wrapper is unwrapped first.
     */
    public static function closureSigOf(?Node $type): ?ClosureSignature
    {
        if ($type instanceof NullableType) {
            $type = $type->type;
        }
        if ($type instanceof Name) {
            $sig = $type->getAttribute(XphpSourceParser::ATTR_CLOSURE_SIG);
            return $sig instanceof ClosureSignature ? $sig : null;
        }
        return null;
    }

    /**
     * The `Closure(...)` target a type node carries, resolved for the pass mode.
     * In the grounded pass (`$groundedTypesOnly`) it returns the target ONLY when
     * specialization actually grounded a type-parameter leaf of it (the Name carries
     * {@see XphpSourceParser::ATTR_CLOSURE_SIG_TEMPLATE}); a fully-concrete target was
     * already decided by the pre-specialization pass, so returning it here would
     * duplicate that diagnostic. Outside the grounded pass every target is returned.
     */
    public static function targetSigFor(?Node $type, bool $groundedTypesOnly): ?ClosureSignature
    {
        $sig = self::closureSigOf($type);
        if ($sig === null || !$groundedTypesOnly) {
            return $sig;
        }
        return self::ungroundedTargetSig($type) !== null ? $sig : null;
    }

    /**
     * The PRE-substitution `Closure(...)` signature a specialized type node carries
     * (its {@see XphpSourceParser::ATTR_CLOSURE_SIG_TEMPLATE}), or null when the target
     * was never grounded. The grounded pass checks a literal against this ungrounded
     * form too: a violation provable here was already reported by the pre-specialization
     * pass (it sits on a concrete leaf, unchanged by grounding), so the grounded pass
     * suppresses it — only a NEWLY-provable, grounding-induced violation is reported.
     */
    public static function ungroundedTargetSig(?Node $type): ?ClosureSignature
    {
        if ($type instanceof NullableType) {
            $type = $type->type;
        }
        if ($type instanceof Name) {
            $sig = $type->getAttribute(XphpSourceParser::ATTR_CLOSURE_SIG_TEMPLATE);
            return $sig instanceof ClosureSignature ? $sig : null;
        }
        return null;
    }

    /**
     * The (lower-cased) name of the enclosing-class method a self-call names, or null
     * when the node is not a `$this->m(...)` / `self::m(...)` self-call the grounded
     * pass rechecks. Restricted to `$this->` and `self::` — both bind to the enclosing
     * class's own contract, exactly what PHP statically checks. A `static::` call is
     * excluded: late static binding could resolve it to a wider override in a derived
     * specialization, so checking the in-class method could over-reject. A dynamic
     * method name, a non-`$this` / non-`self` target, and a first-class callable
     * (`$this->m(...)`) all return null (gradual).
     */
    public static function selfCallMethodName(Node $node): ?string
    {
        if ($node instanceof MethodCall) {
            return $node->var instanceof Variable
                && $node->var->name === 'this'
                && $node->name instanceof Identifier
                && !$node->isFirstClassCallable()
                    ? $node->name->toLowerString()
                    : null;
        }
        if ($node instanceof StaticCall) {
            return $node->class instanceof Name
                && $node->class->toLowerString() === 'self'
                && $node->name instanceof Identifier
                && !$node->isFirstClassCallable()
                    ? $node->name->toLowerString()
                    : null;
        }
        return null;
    }

    /**
     * Whether an expression is a closure literal — the only candidate shape this
     * validator can extract a signature from. A first-class callable
     * (`foo(...)`) is a `*Call` node, not a {@see Closure}, so it is not a literal.
     *
     * @phpstan-assert-if-true Closure|ArrowFunction $node
     */
    private static function isLiteral(?Node $node): bool
    {
        return $node instanceof Closure || $node instanceof ArrowFunction;
    }

    /**
     * Check the returned expression against its `Closure(...)` return target and
     * report the first violation (throw or collect). A non-literal expression is
     * not a candidate (a variable, a call, a property fetch) — it stays gradual
     * and is silently skipped here.
     */
    public function checkLiteral(
        ClosureSignature $target,
        Node\Expr $literal,
        NamespaceContext $ctx,
        string $file,
        ?DiagnosticCollector $diagnostics,
        bool $groundedTypesOnly,
        ?ClosureSignature $ungroundedTarget = null,
    ): void {
        if (!self::isLiteral($literal)) {
            return;
        }
        $candidate = ClosureLiteralSignature::extract($literal, $ctx);
        $violation = $groundedTypesOnly
            ? $this->engine->checkTypesOnly($candidate, $target)
            : $this->engine->check($candidate, $target);
        if ($violation === null) {
            return;
        }
        // A partially-grounded target (`Closure(int, E)`) can violate on a CONCRETE leaf
        // that grounding never touched — the pre-specialization pass already reported it.
        // Suppress here so it is not double-reported; only a grounding-induced violation
        // (provable now but not against the ungrounded target) survives.
        if ($ungroundedTarget !== null
            && $this->engine->checkTypesOnly($candidate, $ungroundedTarget) !== null
        ) {
            return;
        }

        $message = self::message($violation);
        if ($diagnostics === null) {
            throw new RuntimeException($message);
        }
        $diagnostics->add(new Diagnostic(
            Severity::Error,
            self::CODE,
            $message,
            new SourceLocation($file, $literal->getStartLine()),
        ));
    }

    /**
     * Check each closure-LITERAL call argument against its paired parameter's
     * `Closure(...)` target type, grounding that target through `$subst` (the class-
     * and method-level type-parameter substitution resolved at the call site). This
     * is the shared call-argument site every statically-resolvable call shape feeds.
     *
     * A parameter without a `Closure(...)` type, an argument that is not a closure
     * literal (a variable, a first-class callable, a spread value), and any position
     * the pairing can't resolve all stay gradual (accepted). Arguments are paired to
     * parameters PHP-faithfully: a named argument binds by parameter name; a spread
     * (`...$x`) stops positional pairing (PHP rebinds every later position at call
     * time), so the tail stays gradual; a trailing variadic parameter absorbs the
     * positional arguments past the fixed arity.
     *
     * The candidate literal's own types are resolved against `$ctx` — the CALLER's
     * namespace/use scope, where the literal is written — while `$target` arrives
     * already resolved (at parse time, against the callee) and grounded here.
     *
     * @param list<Param> $calleeParams              the callee's declared value parameters
     * @param array<int, Arg|VariadicPlaceholder> $callArgs  the call's arguments
     * @param array<string, TypeRef> $subst          grounding map (class + method type params)
     */
    public function checkCallArguments(
        array $calleeParams,
        array $callArgs,
        array $subst,
        NamespaceContext $ctx,
        string $file,
        ?DiagnosticCollector $diagnostics,
        bool $groundedTypesOnly = false,
    ): void {
        $position = 0;
        $sawSpread = false;
        foreach ($callArgs as $arg) {
            // A first-class-callable placeholder (`foo(...)`) carries no value/name.
            if (!$arg instanceof Arg) {
                continue;
            }
            if ($arg->name instanceof Identifier) {
                // Named argument: bind by the callee parameter's name, wherever it sits.
                $this->checkOneCallArgument(
                    self::paramByName($calleeParams, $arg->name->toString()),
                    $arg->value, $subst, $ctx, $file, $diagnostics, $groundedTypesOnly,
                );
                continue;
            }
            // A spread rebinds every following positional slot at runtime, so once one
            // is seen no later positional argument can be soundly paired — leave the
            // tail gradual rather than risk checking a literal against the wrong slot.
            if ($sawSpread) {
                continue;
            }
            if ($arg->unpack) {
                $sawSpread = true;
                continue;
            }
            $this->checkOneCallArgument(
                self::paramForPosition($calleeParams, $position),
                $arg->value, $subst, $ctx, $file, $diagnostics, $groundedTypesOnly,
            );
            $position++;
        }
    }

    /**
     * Ground one parameter's `Closure(...)` target and check a single closure-literal
     * argument against it. A null / typeless / non-`Closure(...)` parameter or a
     * non-literal argument is a gradual no-op.
     *
     * @param array<string, TypeRef> $subst
     */
    private function checkOneCallArgument(
        ?Param $param,
        Node\Expr $value,
        array $subst,
        NamespaceContext $ctx,
        string $file,
        ?DiagnosticCollector $diagnostics,
        bool $groundedTypesOnly,
    ): void {
        if ($param === null || !self::isLiteral($value)) {
            return;
        }
        $target = self::targetSigFor($param->type, $groundedTypesOnly);
        if ($target === null) {
            return;
        }
        $this->checkLiteral(
            Specializer::substituteClosureSignature($target, $subst),
            $value, $ctx, $file, $diagnostics, $groundedTypesOnly,
            self::ungroundedTargetSig($param->type),
        );
    }

    /**
     * The callee parameter bound by a NAMED argument, or null when no parameter has
     * that name (a stale / misspelled name is a PHP error elsewhere; here it is gradual).
     *
     * @param list<Param> $params
     */
    private static function paramByName(array $params, string $name): ?Param
    {
        foreach ($params as $param) {
            if ($param->var instanceof Variable && $param->var->name === $name) {
                return $param;
            }
        }
        return null;
    }

    /**
     * The callee parameter a POSITIONAL argument at `$index` binds: the parameter at
     * that index, or a trailing variadic parameter that absorbs everything past the
     * fixed arity, or null when the call over-supplies a non-variadic parameter list.
     *
     * @param list<Param> $params
     */
    private static function paramForPosition(array $params, int $index): ?Param
    {
        if (isset($params[$index])) {
            return $params[$index];
        }
        $last = $params === [] ? null : $params[array_key_last($params)];
        return $last !== null && $last->variadic ? $last : null;
    }

    private static function message(ClosureConformanceViolation $violation): string
    {
        return 'Closure literal does not conform to the declared `Closure(...)` type: ' . $violation->detail;
    }

    private function buildVisitor(NamespaceContext $ctx, string $file, ?DiagnosticCollector $diagnostics, bool $groundedTypesOnly): NodeVisitorAbstract
    {
        return new class($this, $ctx, $file, $diagnostics, $groundedTypesOnly) extends NodeVisitorAbstract {
            /**
             * The declared return-type NODE of each enclosing function-like that can
             * hold `return` statements, innermost last (null when it declares none).
             * Stored as the node so both the grounded target and its ungrounded template
             * form are resolvable when a `return` literal is checked. An arrow function
             * has no `return` body, so it never pushes a frame.
             *
             * @var list<?Node>
             */
            private array $returnTypeNodes = [];

            /**
             * The value parameters of each enclosing class's own methods, keyed by
             * method name, innermost class last. Lets a `$this->m(...)` / `self::m(...)`
             * self-call inside a specialized class body reach `m`'s grounded parameters
             * so its closure-literal arguments can be rechecked after specialization.
             *
             * @var list<array<string, list<Param>>>
             */
            private array $selfMethodParams = [];

            public function __construct(
                private readonly ClosureConformanceValidator $validator,
                private readonly NamespaceContext $ctx,
                private readonly string $file,
                private readonly ?DiagnosticCollector $diagnostics,
                private readonly bool $groundedTypesOnly,
            ) {
            }

            public function enterNode(Node $node): null
            {
                if ($node instanceof Namespace_) {
                    $this->ctx->enterNamespace($node->name?->toString());
                } elseif ($node instanceof Use_) {
                    $this->ctx->indexUse($node);
                } elseif ($node instanceof ClassLike) {
                    $this->selfMethodParams[] = $this->indexOwnMethods($node);
                } elseif ($node instanceof Function_ || $node instanceof ClassMethod || $node instanceof Closure) {
                    $this->returnTypeNodes[] = $node->returnType;
                } elseif ($node instanceof ArrowFunction) {
                    // Arrow body: the body expression IS the returned value.
                    $this->checkReturnLiteral($node->returnType, $node->expr);
                } elseif ($node instanceof Return_) {
                    // Return position: the literal is the returned value of the
                    // innermost enclosing function-like.
                    if ($node->expr !== null) {
                        $rt = $this->returnTypeNodes === [] ? null : $this->returnTypeNodes[count($this->returnTypeNodes) - 1];
                        $this->checkReturnLiteral($rt, $node->expr);
                    }
                } else {
                    $this->checkSelfCall($node);
                }

                return null;
            }

            public function leaveNode(Node $node): null
            {
                if ($node instanceof Function_ || $node instanceof ClassMethod || $node instanceof Closure) {
                    array_pop($this->returnTypeNodes);
                } elseif ($node instanceof ClassLike) {
                    array_pop($this->selfMethodParams);
                }

                return null;
            }

            /**
             * Check a returned closure literal against a declared `Closure(...)` return
             * type, in the pass's mode. The grounded pass passes the ungrounded template
             * form alongside, so a violation already provable before grounding is not
             * double-reported (see {@see ClosureConformanceValidator::checkLiteral}).
             */
            private function checkReturnLiteral(?Node $returnType, Node\Expr $literal): void
            {
                $target = ClosureConformanceValidator::targetSigFor($returnType, $this->groundedTypesOnly);
                if ($target === null) {
                    return;
                }
                $this->validator->checkLiteral(
                    $target,
                    $literal,
                    $this->ctx,
                    $this->file,
                    $this->diagnostics,
                    $this->groundedTypesOnly,
                    ClosureConformanceValidator::ungroundedTargetSig($returnType),
                );
            }

            /**
             * Check the closure-literal arguments of a `$this->m(...)` or `self::m(...)`
             * call against the enclosing class's own `m` — the one call shape whose
             * `Closure(...)` parameter can reference the class type parameter and so
             * only becomes provable once the class specializes (bucket 3). The callee's
             * parameters are already grounded on the specialized class, so no
             * substitution is threaded (empty subst). A first-class callable, an
             * unresolved method, or a receiver other than `$this` / `self` stays gradual.
             *
             * Restricted to `$this->` and `self::`: both bind to the enclosing class's
             * own contract, which is exactly what PHP statically checks. `static::` is
             * left gradual — late static binding could resolve it to a wider override in
             * a derived specialization, which would make an in-class check over-reject.
             */
            private function checkSelfCall(Node $node): void
            {
                // Only in the grounded pass over specialized classes. In the raw
                // pre-specialization pass the call-argument surface is owned by
                // GenericMethodCompiler (WI-01…05); running here too would double-report.
                // The per-argument grounded guard (targetSigFor) then limits this to
                // targets specialization actually grounded — bucket 3.
                if (!$this->groundedTypesOnly) {
                    return;
                }
                $methodName = ClosureConformanceValidator::selfCallMethodName($node);
                if ($methodName === null) {
                    return;
                }
                // A self-call is always inside a class body, so the innermost frame exists;
                // the `?? null` still covers the degenerate empty-stack case (index -1).
                $params = $this->selfMethodParams[count($this->selfMethodParams) - 1][$methodName] ?? null;
                if ($params === null) {
                    return;
                }
                /** @var MethodCall|StaticCall $node — selfCallMethodName returns non-null only for these. */
                $this->validator->checkCallArguments(
                    $params,
                    $node->args,
                    [],
                    $this->ctx,
                    $this->file,
                    $this->diagnostics,
                    $this->groundedTypesOnly,
                );
            }

            /**
             * The value parameters of the class's own directly-declared methods, keyed
             * by (lower-cased) method name. Inherited methods are not indexed — a
             * self-call to an inherited method stays gradual (a documented follow-up).
             *
             * @return array<string, list<Param>>
             */
            private function indexOwnMethods(ClassLike $node): array
            {
                $index = [];
                foreach ($node->getMethods() as $method) {
                    $index[$method->name->toLowerString()] = array_values($method->params);
                }
                return $index;
            }
        };
    }
}
