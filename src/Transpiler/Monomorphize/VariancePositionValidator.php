<?php

declare(strict_types=1);

namespace XPHP\Transpiler\Monomorphize;

use PhpParser\Node;
use PhpParser\Node\ComplexType;
use PhpParser\Node\Expr\ArrowFunction;
use PhpParser\Node\Expr\Closure;
use PhpParser\Node\Identifier;
use PhpParser\Node\IntersectionType;
use PhpParser\Node\Name;
use PhpParser\Node\NullableType;
use PhpParser\Node\Param;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Property;
use PhpParser\Node\UnionType;
use RuntimeException;
use XPHP\Diagnostics\Diagnostic;
use XPHP\Diagnostics\DiagnosticCollector;
use XPHP\Diagnostics\Severity;
use XPHP\Diagnostics\SourceLocation;

/**
 * Declaration-time check that every appearance of a variance-marked type
 * parameter sits in a position the variance permits.
 *
 * Position rules (PHP-compat surface):
 *
 *  - Property type (mutable OR readonly) -> Invariant only
 *  - Promoted constructor parameter      -> Invariant only (it is a property)
 *  - Non-promoted constructor parameter  -> any variance (emitted with real type)
 *  - Method/function parameter type      -> Invariant or Contravariant
 *  - Method/function return type         -> Invariant or Covariant
 *  - Bound expression                    -> Invariant only
 *  - Default expression                  -> Invariant only
 *
 * Why properties are strict-invariant: PHP enforces invariant property types
 * across the `extends` chain regardless of `readonly`. A covariant +T in a
 * subtype property declaration would PHP-fatal at autoload when the variance
 * edge `Producer_Banana extends Producer_Fruit` lands. The semantic
 * argument ("readonly = output-only") doesn't override PHP's static-type
 * rule. Users who need a covariant getter use a `mixed`-typed (or
 * bound-typed) backing field + a method `get(): T`.
 *
 * Why a non-promoted constructor parameter may carry any variance: a
 * constructor isn't part of the externally-visible variance surface (it's
 * never reached through an upcast reference, the same reason Kotlin exempts
 * constructor parameters), and PHP exempts `__construct` from LSP signature
 * checks, so each specialization's constructor may legitimately differ. The
 * Specializer emits the real substituted type there -- nothing is erased. A
 * *promoted* constructor parameter is a property, so it falls under the
 * strict-invariant property rule above.
 *
 * F-bounded variance (`class Sortable<+T : Comparable<T>>`) is rejected
 * because `+T` appears inside its own bound (an invariant position).
 *
 * Errors include the param name, variance marker, and the position class
 * so the user sees what's wrong without reading the implementation.
 *
 * Runs over collected definitions (`Registry::validateVariancePositions`), not
 * in the parser. With a `DiagnosticCollector` it gathers every violation in the
 * class (each located at the offending member) and continues; without one it
 * throws the first violation — byte-identical to the previous parse-time check.
 */
final class VariancePositionValidator
{
    /** Stable diagnostic code for a variance-position violation. */
    public const CODE_VARIANCE_POSITION = 'xphp.variance_position';

    /** @var array<string, Variance> */
    private array $varianceByName;

    /** @var list<array{message: string, line: ?int}> */
    private array $violations = [];

    /**
     * @param array<string, Variance> $varianceByName
     */
    private function __construct(array $varianceByName)
    {
        $this->varianceByName = $varianceByName;
    }

    /**
     * @param list<TypeParam> $params
     * @return bool True iff at least one violation was found (in check-mode; compile-mode
     *   throws before returning). Lets the caller skip the inner-variance pass for a
     *   definition already flagged here, avoiding a double report of the same issue.
     */
    public static function assertPositions(
        ClassLike $node,
        array $params,
        ?DiagnosticCollector $diagnostics = null,
        ?string $file = null,
    ): bool {
        $varianceByName = [];
        foreach ($params as $param) {
            if ($param->variance !== Variance::Invariant) {
                $varianceByName[$param->name] = $param->variance;
            }
        }
        // @infection-ignore-all FalseValue -- returning true here (no variance markers) would
        // only add this definition to the caller's "flagged" set, which merely skips the
        // inner-variance pass for it; but a no-variance definition is already a no-op in
        // inner-variance (empty variance map), so flagging it changes nothing observable.
        if ($varianceByName === []) {
            return false;
        }

        $validator = new self($varianceByName);
        $validator->collect($node, $params);
        if ($validator->violations === []) {
            return false;
        }

        if ($diagnostics === null) {
            // Compile-mode: fail fast on the first violation (byte-identical message).
            throw new RuntimeException($validator->violations[0]['message']);
        }

        foreach ($validator->violations as $violation) {
            $location = ($violation['line'] !== null && $file !== null)
                ? new SourceLocation($file, $violation['line'])
                : null;
            $diagnostics->add(new Diagnostic(
                Severity::Error,
                self::CODE_VARIANCE_POSITION,
                $violation['message'],
                $location,
            ));
        }

        return true;
    }

    /**
     * @param list<TypeParam> $params
     */
    private function collect(ClassLike $node, array $params): void
    {
        $declarationLine = $node->getStartLine();

        // 0. A variant class cannot be `final`. Its specializations participate
        // in real `extends` subtype edges, which a `final` class cannot anchor.
        // Rejecting it (rather than silently stripping `final` from the generated
        // class — which would make ReflectionClass::isFinal lie) keeps source and
        // emitted output honest. Only reached for variant definitions, since
        // assertPositions early-returns when there are no variance markers.
        if ($node instanceof Class_ && $node->isFinal()) {
            // One string literal (not concatenated) keeps the message stable.
            $this->record(
                'A variant class cannot be declared `final`: its specializations participate in `extends` subtype edges that a `final` class cannot anchor. Remove `final`.',
                $declarationLine,
            );
        }

        // 1. Bound and default positions are invariant by RFC.
        foreach ($params as $param) {
            if ($param->bound !== null) {
                $this->checkBoundExpr($param->bound, $param->name, 'bound', $declarationLine);
            }
            if ($param->default !== null) {
                $this->checkTypeRef($param->default, $param->name, 'default', $declarationLine);
            }
        }

        // 2. Class-body positions: properties and methods.
        foreach ($node->getProperties() as $property) {
            $this->checkProperty($property);
        }
        foreach ($node->getMethods() as $method) {
            $this->checkMethod($method);
        }
    }

    private function checkBoundExpr(BoundExpr $bound, string $hostParam, string $hostPosition, int $line): void
    {
        if ($bound instanceof BoundLeaf) {
            $this->checkTypeRef($bound->type, $hostParam, $hostPosition, $line);
            return;
        }
        assert($bound instanceof BoundIntersection || $bound instanceof BoundUnion);
        foreach ($bound->operands as $operand) {
            $this->checkBoundExpr($operand, $hostParam, $hostPosition, $line);
        }
    }

    private function checkTypeRef(TypeRef $ref, string $hostParam, string $hostPosition, int $line): void
    {
        if ($ref->isTypeParam && isset($this->varianceByName[$ref->name])) {
            $this->record(
                self::violationMessage($ref->name, $this->varianceByName[$ref->name], $hostPosition, $hostParam),
                $line,
            );
        }
        foreach ($ref->args as $inner) {
            $this->checkTypeRef($inner, $hostParam, $hostPosition, $line);
        }
    }

    private function checkProperty(Property $property): void
    {
        $type = $property->type;
        if ($type === null) {
            return;
        }
        // PHP enforces invariant property types across `extends` chains
        // regardless of `readonly`. Even +T on a readonly property would
        // PHP-fatal at autoload when the variance edge lands.
        $position = $property->isReadonly() ? 'readonly property' : 'mutable property';
        $this->checkPhpType($type, [Variance::Invariant], $position);
    }

    private function checkMethod(ClassMethod $method): void
    {
        $name = $method->name->toLowerString();
        $isConstructor = $name === '__construct';

        // Parameter types. Constructors: invariant only (deviation). Other
        // methods: invariant or contravariant.
        $paramAllowed = $isConstructor
            ? [Variance::Invariant]
            : [Variance::Invariant, Variance::Contravariant];
        $paramPosition = $isConstructor ? 'constructor parameter' : 'method parameter';
        // A variant class (≥1 covariant/contravariant type-param) may carry its
        // type-param in a NON-promoted constructor parameter at any variance: a
        // constructor parameter is not part of the externally-visible variance
        // surface (you can't call a constructor through an upcast reference), and
        // PHP exempts `__construct` from LSP, so the specializer emits the real
        // substituted type there with no soundness or autoload hazard. A *promoted*
        // constructor param is also a property, which stays strictly invariant (a `T`-typed
        // property would PHP-fatal across the chain regardless).
        $classIsVariant = $this->varianceByName !== [];
        foreach ($method->params as $param) {
            // @phpstan-ignore-next-line instanceof.alwaysTrue — defensive guard against nikic/php-parser PHPDoc-narrowed param collection element.
            if (!$param instanceof Param) {
                continue;
            }
            if ($param->type === null) {
                continue;
            }
            // A by-reference parameter is read AND written back through the
            // caller's variable, so it's an invariant position regardless of
            // method vs constructor — neither +T nor -T is sound there. Checked
            // before the variant-constructor any-variance branch so `-T &$x` in
            // a constructor is rejected too.
            if ($param->byRef) {
                $this->checkPhpType($param->type, [Variance::Invariant], 'by-reference parameter');
                continue;
            }
            $isPromoted = $param->flags !== 0;
            $allowed = ($isConstructor && !$isPromoted && $classIsVariant)
                ? [Variance::Invariant, Variance::Covariant, Variance::Contravariant]
                : $paramAllowed;
            $this->checkPhpType($param->type, $allowed, $paramPosition);
        }

        // Return type. Constructors don't have one; for the rest, invariant
        // or covariant.
        if (!$isConstructor && $method->returnType !== null) {
            $this->checkPhpType(
                $method->returnType,
                [Variance::Invariant, Variance::Covariant],
                'method return',
            );
        }

        // Recurse into the method body for nested closures / arrow functions.
        // A closure that captures the OUTER class's T (via implicit capture or
        // a `use ($x)` clause) and uses it in a method-param or return position
        // counts as outer-T input/output. The position rules apply to the
        // OUTER class's variance markers; the inner closure's own type-params
        // (when item 16 lands) will shadow same-named outer T's, but until
        // then nested closures have no type-params and every name in their
        // signature is an outer reference.
        if ($method->stmts !== null) {
            $this->walkBodyForNestedClosures($method->stmts);
        }
    }

    /**
     * Recursively walk a list of statements (or an expression tree), looking
     * for Closure / ArrowFunction nodes whose params or return types reference
     * an outer-variance-marked T.
     *
     * Cheap hand-rolled recursive walk -- avoids spinning up a NodeTraverser
     * inside the per-class validator hot path.
     */
    private function walkBodyForNestedClosures(mixed $node): void
    {
        if ($node instanceof Closure || $node instanceof ArrowFunction) {
            foreach ($node->params as $param) {
                // @phpstan-ignore-next-line instanceof.alwaysTrue — defensive guard against nikic/php-parser PHPDoc-narrowed param collection element.
                if ($param instanceof Param && $param->type !== null) {
                    // A by-reference param is an invariant position (read + written
                    // back), even inside a nested closure/arrow.
                    $allowed = $param->byRef
                        ? [Variance::Invariant]
                        : [Variance::Invariant, Variance::Contravariant];
                    $this->checkPhpType(
                        $param->type,
                        $allowed,
                        $param->byRef ? 'by-reference parameter' : 'nested closure/arrow parameter',
                    );
                }
            }
            if ($node->returnType !== null) {
                $this->checkPhpType(
                    $node->returnType,
                    [Variance::Invariant, Variance::Covariant],
                    'nested closure/arrow return',
                );
            }
            // Don't stop -- a closure body may contain further closures.
        }

        if (is_array($node)) {
            foreach ($node as $child) {
                $this->walkBodyForNestedClosures($child);
            }
            return;
        }
        if ($node instanceof Node) {
            foreach ($node->getSubNodeNames() as $subName) {
                $this->walkBodyForNestedClosures($node->$subName);
            }
        }
    }

    /**
     * Recursively walk a PHP type AST (Name / Identifier / NullableType /
     * UnionType / IntersectionType), checking every Name's parts against
     * the variance map.
     *
     * @param list<Variance> $allowed
     */
    private function checkPhpType(Node $type, array $allowed, string $position): void
    {
        if ($type instanceof Identifier) {
            return; // scalar / pseudo type; never a type-param ref.
        }
        if ($type instanceof Name) {
            $parts = $type->getParts();
            if (count($parts) === 1) {
                $name = $parts[0];
                if (isset($this->varianceByName[$name])) {
                    $variance = $this->varianceByName[$name];
                    if (!in_array($variance, $allowed, true)) {
                        $this->record(
                            self::violationMessage($name, $variance, $position, null),
                            $type->getStartLine(),
                        );
                    }
                }
            }
            // Generic args attached via xphp:genericArgs are TypeRef trees;
            // recurse into them so `Box<T>` in a parameter position is
            // checked too.
            $args = $type->getAttribute(XphpSourceParser::ATTR_GENERIC_ARGS);
            if (is_array($args)) {
                foreach ($args as $arg) {
                    if ($arg instanceof TypeRef) {
                        $this->checkInnerTypeRef($arg, $allowed, $position, $type->getStartLine());
                    }
                }
            }
            return;
        }
        if ($type instanceof NullableType) {
            $this->checkPhpType($type->type, $allowed, $position);
            return;
        }
        if ($type instanceof UnionType || $type instanceof IntersectionType) {
            foreach ($type->types as $inner) {
                $this->checkPhpType($inner, $allowed, $position);
            }
            return;
        }
        if ($type instanceof ComplexType) {
            return;
        }
    }

    /**
     * @param list<Variance> $allowed
     */
    private function checkInnerTypeRef(TypeRef $ref, array $allowed, string $position, int $line): void
    {
        if ($ref->isTypeParam && isset($this->varianceByName[$ref->name])) {
            $variance = $this->varianceByName[$ref->name];
            if (!in_array($variance, $allowed, true)) {
                $this->record(self::violationMessage($ref->name, $variance, $position, null), $line);
            }
        }
        foreach ($ref->args as $inner) {
            $this->checkInnerTypeRef($inner, $allowed, $position, $line);
        }
    }

    private function record(string $message, ?int $line): void
    {
        $this->violations[] = ['message' => $message, 'line' => $line];
    }

    private static function violationMessage(
        string $paramName,
        Variance $variance,
        string $position,
        ?string $hostParam,
    ): string {
        $marker = match ($variance) {
            Variance::Covariant => '+',
            Variance::Contravariant => '-',
            Variance::Invariant => '',
        };
        $context = $hostParam !== null
            ? sprintf(' inside the %s of generic parameter `%s`', $position, $hostParam)
            : sprintf(' in %s position', $position);
        return sprintf(
            'Generic parameter `%s%s` appears%s, which is not allowed for %s variance.',
            $marker,
            $paramName,
            $context,
            $variance->value,
        );
    }
}
