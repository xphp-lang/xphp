<?php

declare(strict_types=1);

namespace XPHP\Transpiler\Monomorphize;

use PhpParser\Node;
use PhpParser\Node\Expr\ArrowFunction;
use PhpParser\Node\Expr\Closure;
use PhpParser\Node\IntersectionType;
use PhpParser\Node\Name;
use PhpParser\Node\NullableType;
use PhpParser\Node\Param;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\UnionType;
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
        private readonly string $templateFqn,
    ) {
    }

    public static function assert(
        ClassLike $node,
        string $templateFqn,
        TypeHierarchy $hierarchy,
        ?DiagnosticCollector $diagnostics = null,
        ?string $file = null,
    ): void {
        $validator = new self($hierarchy, $templateFqn);
        $validator->collect($node);
        if ($validator->violations === []) {
            return;
        }

        if ($diagnostics === null) {
            // Compile-mode: fail fast on the first finding rather than emit broken PHP.
            throw new RuntimeException($validator->violations[0]['message']);
        }

        foreach ($validator->violations as $violation) {
            $location = $file !== null ? new SourceLocation($file, $violation['line']) : null;
            $diagnostics->add(new Diagnostic(
                Severity::Error,
                self::CODE_UNDECLARED_TYPE,
                $violation['message'],
                $location,
            ));
        }
    }

    private static function undeclaredTypeMessage(string $name, string $templateFqn): string
    {
        return sprintf(
            'Type `%s` used in `%s` is not a declared type parameter and does not resolve to a known class, interface, or trait. Declare it as a type parameter, or import (`use`) / fully-qualify it if it names a real type.',
            $name,
            $templateFqn,
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
            $this->checkMethod($method);
        }
    }

    private function checkMethod(ClassMethod $method): void
    {
        foreach ($method->params as $param) {
            // @phpstan-ignore-next-line instanceof.alwaysTrue — defensive guard against nikic/php-parser PHPDoc-narrowed param collection element.
            if ($param instanceof Param && $param->type !== null) {
                $this->checkType($param->type);
            }
        }
        if ($method->returnType !== null) {
            $this->checkType($method->returnType);
        }
        if ($method->stmts !== null) {
            $this->walkBodyForNestedClosures($method->stmts);
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
            foreach ($node->params as $param) {
                // @phpstan-ignore-next-line instanceof.alwaysTrue — defensive guard against nikic/php-parser PHPDoc-narrowed param collection element.
                if ($param instanceof Param && $param->type !== null) {
                    $this->checkType($param->type);
                }
            }
            if ($node->returnType !== null) {
                $this->checkType($node->returnType);
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

    private function checkType(Node $type): void
    {
        if ($type instanceof Name) {
            $fqn = $type->getAttribute(XphpSourceParser::ATTR_SUSPECT_UNDECLARED_TYPE);
            if (is_string($fqn) && !$this->hierarchy->isDeclared($fqn)) {
                $this->violations[] = [
                    'message' => self::undeclaredTypeMessage($type->toString(), $this->templateFqn),
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
