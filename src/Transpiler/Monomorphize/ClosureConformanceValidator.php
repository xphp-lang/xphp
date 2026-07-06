<?php

declare(strict_types=1);

namespace XPHP\Transpiler\Monomorphize;

use PhpParser\Node;
use PhpParser\Node\Expr\ArrowFunction;
use PhpParser\Node\Expr\Closure;
use PhpParser\Node\Name;
use PhpParser\Node\NullableType;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Function_;
use PhpParser\Node\Stmt\Namespace_;
use PhpParser\Node\Stmt\Return_;
use PhpParser\Node\Stmt\Use_;
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
     * @param list<Node\Stmt> $ast
     */
    public function validateFile(array $ast, string $file, ?DiagnosticCollector $diagnostics): void
    {
        // A fresh context is already global-scope (no namespace, no uses); a
        // `namespace` node encountered during the walk re-scopes it.
        $ctx = new NamespaceContext();
        $traverser = new NodeTraverser();
        $traverser->addVisitor($this->buildVisitor($ctx, $file, $diagnostics));
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
    ): void {
        if (!self::isLiteral($literal)) {
            return;
        }
        $candidate = ClosureLiteralSignature::extract($literal, $ctx);
        $violation = $this->engine->check($candidate, $target);
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

    private static function message(ClosureConformanceViolation $violation): string
    {
        return 'Closure literal does not conform to the declared `Closure(...)` type: ' . $violation->detail;
    }

    private function buildVisitor(NamespaceContext $ctx, string $file, ?DiagnosticCollector $diagnostics): NodeVisitorAbstract
    {
        return new class($this, $ctx, $file, $diagnostics) extends NodeVisitorAbstract {
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
                        $this->validator->checkLiteral($target, $node->expr, $this->ctx, $this->file, $this->diagnostics);
                    }
                } elseif ($node instanceof Return_) {
                    // Return position: the literal is the returned value of the
                    // innermost enclosing function-like.
                    $target = $this->returnTargets === [] ? null : $this->returnTargets[count($this->returnTargets) - 1];
                    if ($target !== null && $node->expr !== null) {
                        $this->validator->checkLiteral($target, $node->expr, $this->ctx, $this->file, $this->diagnostics);
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
