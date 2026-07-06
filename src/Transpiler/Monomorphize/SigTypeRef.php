<?php

declare(strict_types=1);

namespace XPHP\Transpiler\Monomorphize;

/**
 * A closure-signature leaf that is an ordinary type — scalar, class, interface,
 * type parameter, or pseudo-type. Carries a `TypeRef` (not a bare FQN) so the
 * generic-substitution and `TypeHierarchy` machinery is shared: a `Closure(T): U`
 * leaf substitutes `T`/`U` through specialization exactly like any other
 * `TypeRef`.
 */
final readonly class SigTypeRef extends SigType
{
    public function __construct(
        public TypeRef $type,
    ) {
    }
}
