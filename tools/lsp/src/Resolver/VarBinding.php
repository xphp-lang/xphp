<?php

declare(strict_types=1);

namespace XPHP\Lsp\Resolver;

use XPHP\Transpiler\Monomorphize\TypeRef;

/**
 * Value object: the result of a `$var = new Generic<TypeArgs>(...)`
 * assignment that the LSP-side monomorphization tracker observed.
 *
 *  - `classFqn`  -- fully-qualified template name, e.g. `App\Containers\Collection`.
 *  - `paramMap`  -- `paramName => bound TypeRef`, e.g. `['T' => TypeRef('App\Models\User')]`.
 *
 * Consumed by `GenericResolver::resolveMethodCall` to substitute type-param
 * leaves in a method's return type via `Specializer::substituteTypeRef`.
 */
final readonly class VarBinding
{
    /**
     * @param array<string, TypeRef> $paramMap
     */
    public function __construct(
        public string $classFqn,
        public array $paramMap,
    ) {
    }
}
