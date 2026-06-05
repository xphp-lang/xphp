<?php

declare(strict_types=1);

namespace XPHP\Transpiler\Monomorphize;

/**
 * Single class / interface reference in a bound position. Carries a `TypeRef`
 * (not a bare FQN) so F-bounded shapes like `T : Comparable<T>` work without a
 * second representation.
 *
 * Subtype check at instantiation time: `$hierarchy->isSubtype($concrete->name, $leaf->type->name)`
 * -- erased to nominal class names since the hierarchy doesn't model generic args.
 */
final readonly class BoundLeaf extends BoundExpr
{
    public function __construct(
        public TypeRef $type,
    ) {
    }
}
