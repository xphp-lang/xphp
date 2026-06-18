<?php

declare(strict_types=1);

namespace XPHP\Transpiler\Monomorphize;

use PhpParser\Node;
use PhpParser\Node\Expr\ArrowFunction;
use PhpParser\Node\Expr\Closure;
use PhpParser\Node\FunctionLike;
use PhpParser\Node\IntersectionType;
use PhpParser\Node\Name;
use PhpParser\Node\NullableType;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Function_;
use PhpParser\Node\UnionType;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;
use RuntimeException;
use XPHP\Diagnostics\Diagnostic;
use XPHP\Diagnostics\DiagnosticCollector;
use XPHP\Diagnostics\Severity;
use XPHP\Diagnostics\SourceLocation;

/**
 * Declaration-time check that every type named in a generic template member is
 * either a declared type parameter or a real (in-source-set or built-in) type —
 * catching a stray/undeclared type parameter such as the `T` in
 * `interface Foo<Z> { public function add(T $x): void; }`, which would otherwise
 * be silently emitted as a reference to a non-existent class `\App\Foo\T`.
 *
 * Works off the parser's {@see XphpSourceParser::ATTR_SUSPECT_UNDECLARED_TYPE}
 * tag: the parser stamps it on a bare, single-segment, non-imported class name
 * used inside a generic context after excluding declared params, scalars,
 * fully-qualified names, and generic-arg-bearing names. So a tagged name is
 * exactly "a real type or a stray type parameter"; a finding is a tagged name
 * whose resolved FQN is not {@see TypeHierarchy::isDeclared}.
 *
 * Walks the same signature positions as {@see VariancePositionValidator}
 * (properties, method/constructor params, returns, and nested closure/arrow
 * signatures) — NOT `extends`/`implements`/`new`/`catch` or method bodies.
 * Bounds and defaults are checked separately (they're TypeRef trees, not AST
 * type nodes). Runs over collected definitions; with a `DiagnosticCollector` it
 * gathers every finding (each at the offending member line) and continues,
 * without one it throws the first (shared message builder).
 */
final class UndeclaredTypeParameterValidator
{
    /** Stable diagnostic code for an undeclared type used in a generic member. */
    public const CODE_UNDECLARED_TYPE = 'xphp.undeclared_type';

    /** @var list<array{message: string, line: int}> */
    private array $violations = [];

    private function __construct(
        private readonly TypeHierarchy $hierarchy,
        private readonly string $context,
    ) {
    }

    /**
     * Validate a generic class/interface/trait template's member signatures and the
     * type names used in its type-parameter bounds and defaults.
     *
     * @param list<TypeParam> $params the template's declared type parameters
     */
    public static function assert(
        ClassLike $node,
        array $params,
        string $templateFqn,
        TypeHierarchy $hierarchy,
        ?DiagnosticCollector $diagnostics = null,
        ?string $file = null,
    ): void {
        $validator = new self($hierarchy, 'template `' . $templateFqn . '`');
        $validator->collect($node);
        $validator->collectBoundsAndDefaults($params, $node->getStartLine());
        self::report($validator->violations, $diagnostics, $file);
    }

    /**
     * Validate generic method/function/closure signatures declared OUTSIDE a generic
     * template (a generic method on a plain class, a free generic function, a generic
     * closure/arrow). Those nested inside a generic template are owned by {@see assert}
     * (via its member walk), so they're skipped here to avoid reporting the same node
     * twice.
     *
     * @param array<string, list<Node>> $astPerFile keyed by filepath
     */
    public static function assertMethodLevel(
        array $astPerFile,
        TypeHierarchy $hierarchy,
        ?DiagnosticCollector $diagnostics = null,
    ): void {
        /** @var list<array{message: string, line: int, file: string}> $findings */
        $findings = [];
        foreach ($astPerFile as $file => $ast) {
            foreach (self::findMethodLevelGenerics($ast) as $generic) {
                $validator = new self($hierarchy, $generic['context']);
                $validator->checkCallable($generic['node']);
                foreach ($validator->violations as $violation) {
                    $findings[] = $violation + ['file' => $file];
                }
            }
        }

        if ($findings === []) {
            return;
        }
        if ($diagnostics === null) {
            throw new RuntimeException($findings[0]['message']);
        }
        foreach ($findings as $finding) {
            $diagnostics->add(new Diagnostic(
                Severity::Error,
                self::CODE_UNDECLARED_TYPE,
                $finding['message'],
                new SourceLocation($finding['file'], $finding['line']),
            ));
        }
    }

    /**
     * @param list<array{message: string, line: int}> $violations
     */
    private static function report(array $violations, ?DiagnosticCollector $diagnostics, ?string $file): void
    {
        if ($violations === []) {
            return;
        }
        if ($diagnostics === null) {
            // Compile-mode: fail fast on the first finding rather than emit broken PHP.
            throw new RuntimeException($violations[0]['message']);
        }
        foreach ($violations as $violation) {
            $location = $file !== null ? new SourceLocation($file, $violation['line']) : null;
            $diagnostics->add(new Diagnostic(
                Severity::Error,
                self::CODE_UNDECLARED_TYPE,
                $violation['message'],
                $location,
            ));
        }
    }

    /**
     * Find every generic method/function/closure NOT enclosed by a generic template.
     *
     * @param list<Node> $ast
     * @return list<array{node: FunctionLike, context: string}>
     */
    private static function findMethodLevelGenerics(array $ast): array
    {
        $visitor = new class extends NodeVisitorAbstract {
            public int $genericClassDepth = 0;
            /** @var list<array{node: FunctionLike, context: string}> */
            public array $found = [];

            public function enterNode(Node $node): null
            {
                // Skip generics nested in a generic template — its member walk owns them.
                // Known limitation: a generic method on an ANONYMOUS class buried in a
                // generic class's method body is owned by neither pass (the member walk
                // doesn't recurse into anon classes, and depth>0 skips it here). That shape
                // can't be specialized downstream anyway, so it's a latent edge, not a regression.
                if ($node instanceof ClassLike && $node->getAttribute(XphpSourceParser::ATTR_GENERIC_PARAMS) !== null) {
                    $this->genericClassDepth++;
                }
                if ($this->genericClassDepth === 0
                    && $node instanceof FunctionLike
                    && $node->getAttribute(XphpSourceParser::ATTR_METHOD_GENERIC_PARAMS) !== null
                ) {
                    $this->found[] = ['node' => $node, 'context' => self::contextLabel($node)];
                }
                return null;
            }

            public function leaveNode(Node $node): null
            {
                if ($node instanceof ClassLike && $node->getAttribute(XphpSourceParser::ATTR_GENERIC_PARAMS) !== null) {
                    $this->genericClassDepth--;
                }
                return null;
            }

            private static function contextLabel(FunctionLike $node): string
            {
                return match (true) {
                    $node instanceof ClassMethod => 'method `' . $node->name->toString() . '`',
                    $node instanceof Function_ => 'function `' . $node->name->toString() . '`',
                    $node instanceof ArrowFunction => 'arrow function',
                    default => 'closure',
                };
            }
        };

        $traverser = new NodeTraverser();
        $traverser->addVisitor($visitor);
        $traverser->traverse($ast);

        return $visitor->found;
    }

    private static function undeclaredTypeMessage(string $name, string $context): string
    {
        return sprintf(
            'Type `%s` used in %s is not a declared type parameter and does not resolve to a known class, interface, or trait. Declare it as a type parameter, or import (`use`) / fully-qualify it if it names a real type.',
            $name,
            $context,
        );
    }

    private function collect(ClassLike $node): void
    {
        // Walks every member of this generic template, including the signatures of
        // any generic methods nested in it (so a stray name in `Box<T>{ map<K>(U) }`
        // is caught here). Method/function/closure generics declared OUTSIDE a generic
        // template (e.g. on a plain class, or a free function) are validated separately
        // so the two passes never report the same node twice.
        foreach ($node->getProperties() as $property) {
            if ($property->type !== null) {
                $this->checkType($property->type);
            }
        }
        foreach ($node->getMethods() as $method) {
            $this->checkCallable($method);
        }
    }

    /**
     * Check a callable's signature (params + return) and any closures nested in its
     * body. Shared by the class-member walk and the standalone method-level pass.
     */
    private function checkCallable(FunctionLike $callable): void
    {
        foreach ($callable->getParams() as $param) {
            if ($param->type !== null) {
                $this->checkType($param->type);
            }
        }
        $returnType = $callable->getReturnType();
        if ($returnType !== null) {
            $this->checkType($returnType);
        }
        $stmts = $callable->getStmts();
        if ($stmts !== null) {
            $this->walkBodyForNestedClosures($stmts);
        }
    }

    /**
     * Walk a body looking for closure / arrow SIGNATURES (params + return types) —
     * a generic context's stray type can appear there too. Mirrors
     * {@see VariancePositionValidator::walkBodyForNestedClosures}; bodies' other
     * expressions (`new X`, etc.) are intentionally not checked here.
     */
    private function walkBodyForNestedClosures(mixed $node): void
    {
        if ($node instanceof Closure || $node instanceof ArrowFunction) {
            foreach ($node->getParams() as $param) {
                if ($param->type !== null) {
                    $this->checkType($param->type);
                }
            }
            $returnType = $node->getReturnType();
            if ($returnType !== null) {
                $this->checkType($returnType);
            }
            // Don't stop — a closure body may contain further closures.
        }

        if (is_array($node)) {
            foreach ($node as $child) {
                $this->walkBodyForNestedClosures($child);
            }
        } elseif ($node instanceof Node) {
            foreach ($node->getSubNodeNames() as $subName) {
                $this->walkBodyForNestedClosures($node->$subName);
            }
        }
    }

    /**
     * Check the type names used in the declared parameters' bounds and defaults
     * (which are TypeRef trees, not AST type nodes — they carry the suspect flag on
     * the TypeRef itself). All locate at the declaration line; duplicates of the
     * same undeclared name (e.g. `<T: Bad = Bad>` or two params bounded by `Bad`)
     * collapse to one finding.
     *
     * @param list<TypeParam> $params
     */
    private function collectBoundsAndDefaults(array $params, int $declarationLine): void
    {
        $seen = [];
        foreach ($params as $param) {
            if ($param->bound !== null) {
                $this->collectSuspectInBound($param->bound, $declarationLine, $seen);
            }
            if ($param->default !== null) {
                $this->collectSuspectInTypeRef($param->default, $declarationLine, $seen);
            }
        }
    }

    /** @param array<string, true> $seen */
    private function collectSuspectInBound(BoundExpr $bound, int $line, array &$seen): void
    {
        if ($bound instanceof BoundLeaf) {
            $this->collectSuspectInTypeRef($bound->type, $line, $seen);
            return;
        }
        // @infection-ignore-all LogicalOrAllSubExprNegation -- a non-leaf BoundExpr is
        // always an Intersection or a Union, so this assert is a phpstan type-narrowing
        // tautology; negating its operands still holds. Purely a type guard, not behavior.
        assert($bound instanceof BoundIntersection || $bound instanceof BoundUnion);
        foreach ($bound->operands as $operand) {
            $this->collectSuspectInBound($operand, $line, $seen);
        }
    }

    /** @param array<string, true> $seen */
    private function collectSuspectInTypeRef(TypeRef $ref, int $line, array &$seen): void
    {
        if ($ref->suspectUndeclared && !$this->hierarchy->isDeclared($ref->name) && !isset($seen[$ref->name])) {
            // @infection-ignore-all TrueValue -- only the KEY's presence matters (isset above);
            // the stored value is never read, so true vs false is observably identical.
            $seen[$ref->name] = true;
            $this->violations[] = [
                'message' => self::undeclaredTypeMessage(self::shortName($ref->name), $this->context),
                'line' => $line,
            ];
        }
        foreach ($ref->args as $inner) {
            $this->collectSuspectInTypeRef($inner, $line, $seen);
        }
    }

    private static function shortName(string $fqn): string
    {
        $pos = strrpos($fqn, '\\');

        return $pos === false ? $fqn : substr($fqn, $pos + 1);
    }

    private function checkType(Node $type): void
    {
        if ($type instanceof Name) {
            $fqn = $type->getAttribute(XphpSourceParser::ATTR_SUSPECT_UNDECLARED_TYPE);
            if (is_string($fqn) && !$this->hierarchy->isDeclared($fqn)) {
                $this->violations[] = [
                    'message' => self::undeclaredTypeMessage($type->toString(), $this->context),
                    'line' => $type->getStartLine(),
                ];
            }
        } elseif ($type instanceof NullableType) {
            $this->checkType($type->type);
        } elseif ($type instanceof UnionType || $type instanceof IntersectionType) {
            foreach ($type->types as $inner) {
                $this->checkType($inner);
            }
        }
        // Identifier (scalar / pseudo-type) and any other ComplexType: nothing to check.
    }
}
