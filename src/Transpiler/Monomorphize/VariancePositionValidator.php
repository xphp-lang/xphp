<?php

declare(strict_types=1);

namespace XPHP\Transpiler\Monomorphize;

use PhpParser\Node;
use PhpParser\Node\ComplexType;
use PhpParser\Node\Identifier;
use PhpParser\Node\IntersectionType;
use PhpParser\Node\Name;
use PhpParser\Node\NullableType;
use PhpParser\Node\Param;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Property;
use PhpParser\Node\UnionType;
use RuntimeException;

/**
 * Declaration-time check that every appearance of a variance-marked type
 * parameter sits in a position the variance permits.
 *
 * Position rules (PHP-compat surface):
 *
 *  - Property type (mutable OR readonly) -> Invariant only
 *  - Constructor parameter type          -> Invariant only
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
 * Why constructors are strict-invariant: PHP applies LSP signature
 * compatibility to `__construct` at autoload time on `extends` chains.
 * A covariant param would PHP-fatal -- same shape as the property case.
 *
 * F-bounded variance (`class Sortable<+T : Comparable<T>>`) is rejected
 * because `+T` appears inside its own bound (an invariant position).
 *
 * Errors include the param name, variance marker, and the position class
 * so the user sees what's wrong without reading the implementation.
 */
final class VariancePositionValidator
{
    /**
     * @param list<TypeParam> $params
     */
    public static function assertPositions(ClassLike $node, array $params): void
    {
        $varianceByName = [];
        foreach ($params as $param) {
            if ($param->variance !== Variance::Invariant) {
                $varianceByName[$param->name] = $param->variance;
            }
        }
        if ($varianceByName === []) {
            return;
        }

        // 1. Bound and default positions are invariant by RFC. Walk each
        // param's bound expression + default TypeRef tree; reject if any
        // referenced leaf carries a name whose variance isn't Invariant.
        foreach ($params as $param) {
            if ($param->bound !== null) {
                self::checkBoundExpr($param->bound, $varianceByName, $param->name, 'bound');
            }
            if ($param->default !== null) {
                self::checkTypeRef($param->default, $varianceByName, $param->name, 'default');
            }
        }

        // 2. Class-body positions: properties and methods.
        foreach ($node->getProperties() as $property) {
            self::checkProperty($property, $varianceByName);
        }
        foreach ($node->getMethods() as $method) {
            self::checkMethod($method, $varianceByName);
        }
    }

    /**
     * @param array<string, Variance> $varianceByName
     */
    private static function checkBoundExpr(
        BoundExpr $bound,
        array $varianceByName,
        string $hostParam,
        string $hostPosition,
    ): void {
        if ($bound instanceof BoundLeaf) {
            self::checkTypeRef($bound->type, $varianceByName, $hostParam, $hostPosition);
            return;
        }
        foreach ($bound->operands as $operand) {
            self::checkBoundExpr($operand, $varianceByName, $hostParam, $hostPosition);
        }
    }

    /**
     * @param array<string, Variance> $varianceByName
     */
    private static function checkTypeRef(
        TypeRef $ref,
        array $varianceByName,
        string $hostParam,
        string $hostPosition,
    ): void {
        if ($ref->isTypeParam && isset($varianceByName[$ref->name])) {
            $variance = $varianceByName[$ref->name];
            throw self::violationError(
                paramName: $ref->name,
                variance: $variance,
                position: $hostPosition,
                hostParam: $hostParam,
            );
        }
        foreach ($ref->args as $inner) {
            self::checkTypeRef($inner, $varianceByName, $hostParam, $hostPosition);
        }
    }

    /**
     * @param array<string, Variance> $varianceByName
     */
    private static function checkProperty(Property $property, array $varianceByName): void
    {
        $type = $property->type;
        if ($type === null) {
            return;
        }
        // PHP enforces invariant property types across `extends` chains
        // regardless of `readonly`. Even +T on a readonly property would
        // PHP-fatal at autoload when the variance edge lands.
        $position = $property->isReadonly() ? 'readonly property' : 'mutable property';
        self::checkPhpType($type, $varianceByName, [Variance::Invariant], $position);
    }

    /**
     * @param array<string, Variance> $varianceByName
     */
    private static function checkMethod(ClassMethod $method, array $varianceByName): void
    {
        $name = $method->name->toLowerString();
        $isConstructor = $name === '__construct';

        // Parameter types. Constructors: invariant only (deviation). Other
        // methods: invariant or contravariant.
        $paramAllowed = $isConstructor
            ? [Variance::Invariant]
            : [Variance::Invariant, Variance::Contravariant];
        $paramPosition = $isConstructor ? 'constructor parameter' : 'method parameter';
        foreach ($method->params as $param) {
            if (!$param instanceof Param) {
                continue;
            }
            if ($param->type !== null) {
                self::checkPhpType($param->type, $varianceByName, $paramAllowed, $paramPosition);
            }
        }

        // Return type. Constructors don't have one; for the rest, invariant
        // or covariant.
        if (!$isConstructor && $method->returnType !== null) {
            self::checkPhpType(
                $method->returnType,
                $varianceByName,
                [Variance::Invariant, Variance::Covariant],
                'method return',
            );
        }
    }

    /**
     * Recursively walk a PHP type AST (Name / Identifier / NullableType /
     * UnionType / IntersectionType), checking every Name's parts against
     * the variance map.
     *
     * @param array<string, Variance> $varianceByName
     * @param list<Variance> $allowed
     */
    private static function checkPhpType(
        Node $type,
        array $varianceByName,
        array $allowed,
        string $position,
    ): void {
        if ($type instanceof Identifier) {
            return; // scalar / pseudo type; never a type-param ref.
        }
        if ($type instanceof Name) {
            $parts = $type->getParts();
            if (count($parts) === 1) {
                $name = $parts[0];
                if (isset($varianceByName[$name])) {
                    $variance = $varianceByName[$name];
                    if (!in_array($variance, $allowed, true)) {
                        throw self::violationError(
                            paramName: $name,
                            variance: $variance,
                            position: $position,
                            hostParam: null,
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
                        self::checkInnerTypeRef($arg, $varianceByName, $allowed, $position);
                    }
                }
            }
            return;
        }
        if ($type instanceof NullableType) {
            self::checkPhpType($type->type, $varianceByName, $allowed, $position);
            return;
        }
        if ($type instanceof UnionType || $type instanceof IntersectionType) {
            foreach ($type->types as $inner) {
                self::checkPhpType($inner, $varianceByName, $allowed, $position);
            }
            return;
        }
        if ($type instanceof ComplexType) {
            return;
        }
    }

    /**
     * @param array<string, Variance> $varianceByName
     * @param list<Variance> $allowed
     */
    private static function checkInnerTypeRef(
        TypeRef $ref,
        array $varianceByName,
        array $allowed,
        string $position,
    ): void {
        if ($ref->isTypeParam && isset($varianceByName[$ref->name])) {
            $variance = $varianceByName[$ref->name];
            if (!in_array($variance, $allowed, true)) {
                throw self::violationError(
                    paramName: $ref->name,
                    variance: $variance,
                    position: $position,
                    hostParam: null,
                );
            }
        }
        foreach ($ref->args as $inner) {
            self::checkInnerTypeRef($inner, $varianceByName, $allowed, $position);
        }
    }

    private static function violationError(
        string $paramName,
        Variance $variance,
        string $position,
        ?string $hostParam,
    ): RuntimeException {
        $marker = match ($variance) {
            Variance::Covariant => '+',
            Variance::Contravariant => '-',
            Variance::Invariant => '',
        };
        $context = $hostParam !== null
            ? sprintf(' inside the %s of generic parameter `%s`', $position, $hostParam)
            : sprintf(' in %s position', $position);
        return new RuntimeException(sprintf(
            'Generic parameter `%s%s` appears%s, which is not allowed for %s variance.',
            $marker,
            $paramName,
            $context,
            $variance->value,
        ));
    }
}
