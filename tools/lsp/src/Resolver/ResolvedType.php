<?php

declare(strict_types=1);

namespace XPHP\Lsp\Resolver;

use XPHP\Transpiler\Monomorphize\TypeRef;

/**
 * Small carrier for the LSP's per-variable type-resolution result.
 *
 * `nullable` is tracked outside the `TypeRef` itself because `TypeRef`
 * (compile-pipeline value type) deliberately doesn't encode nullability --
 * monomorphization produces `?Foo` at type-hint emission time, not in the
 * intermediate ref.  Keeping nullable here lets us substitute through
 * `Specializer::substituteTypeRef` without the `?` polluting the
 * substitution key.
 */
final readonly class ResolvedType
{
    public function __construct(
        public TypeRef $ref,
        public bool $nullable,
    ) {
    }

    public function render(): string
    {
        return ($this->nullable ? '?' : '') . $this->ref->toDisplayString();
    }
}
