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
 * `default` is the optional default type used when the call site omits the
 * corresponding argument. Defaulted params must be trailing (`class Bad<T = int, U>`
 * is rejected at parse time). A default may reference *strictly earlier* type
 * params in the same list (`class Pair<A, B = A>` is fine; `class Bad<T = U, U>`
 * is rejected). At instantiation, `Registry::recordInstantiation` pads the
 * supplied args with these defaults, substituting earlier args into any
 * type-param references in the default.
 *
 * Both expressions are built by `XphpSourceParser::resolveAndAttach` after
 * resolving each leaf class name against the file's namespace + use map.
 */
final readonly class TypeParam
{
    public function __construct(
        public string $name,
        public ?BoundExpr $bound = null,
        public ?TypeRef $default = null,
    ) {
    }
}
