<?php

declare(strict_types=1);

namespace XPHP\Transpiler\Monomorphize;

/**
 * A single type parameter on a generic template definition.
 *
 * `bound` is the optional upper bound expression. When present, every concrete
 * instantiation must satisfy it -- the verdict combines via
 * `Registry::checkBounds` walking the `BoundExpr` tree against the concrete
 * `TypeRef` for each operand. Composite bounds (intersection, union, DNF) are
 * supported by the BoundIntersection / BoundUnion sub-types; a simple
 * `class Box<T : Stringable>` is stored as `BoundLeaf(TypeRef('Stringable'))`.
 *
 * The bound expression is built by `XphpSourceParser::resolveAndAttach` after
 * resolving each leaf class name against the file's namespace + use map.
 */
final readonly class TypeParam
{
    public function __construct(
        public string $name,
        public ?BoundExpr $bound = null,
    ) {
    }
}
