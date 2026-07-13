<?php

declare(strict_types=1);

namespace XPHP\Transpiler\Monomorphize;

use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr\ArrowFunction;
use PhpParser\Node\Expr\Closure;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\NullableType;
use PhpParser\Node\Param;
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
        $target = self::closureSigOf($param->type);
        if ($target === null) {
            return;
        }
        $this->checkLiteral(
            Specializer::substituteClosureSignature($target, $subst),
            $value, $ctx, $file, $diagnostics, $groundedTypesOnly,
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
             * The `Closure(...)` return target of each enclosing function-like that
             * can hold `return` statements, innermost last. An arrow function has no
             * `return` body, so it never pushes a frame.
             *
             * @var list<?ClosureSignature>
             */
            private array $returnTargets = [];

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
                } elseif ($node instanceof Function_ || $node instanceof ClassMethod || $node instanceof Closure) {
                    $this->returnTargets[] = ClosureConformanceValidator::closureSigOf($node->returnType);
                } elseif ($node instanceof ArrowFunction) {
                    // Arrow body: the body expression IS the returned value.
                    $target = ClosureConformanceValidator::closureSigOf($node->returnType);
                    if ($target !== null) {
                        $this->validator->checkLiteral($target, $node->expr, $this->ctx, $this->file, $this->diagnostics, $this->groundedTypesOnly);
                    }
                } elseif ($node instanceof Return_) {
                    // Return position: the literal is the returned value of the
                    // innermost enclosing function-like.
                    $target = $this->returnTargets === [] ? null : $this->returnTargets[count($this->returnTargets) - 1];
                    if ($target !== null && $node->expr !== null) {
                        $this->validator->checkLiteral($target, $node->expr, $this->ctx, $this->file, $this->diagnostics, $this->groundedTypesOnly);
                    }
                }

                return null;
            }

            public function leaveNode(Node $node): null
            {
                if ($node instanceof Function_ || $node instanceof ClassMethod || $node instanceof Closure) {
                    array_pop($this->returnTargets);
                }

                return null;
            }
        };
    }
}
