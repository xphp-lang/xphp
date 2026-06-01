<?php

declare(strict_types=1);

namespace XPHP\Transpiler\Monomorphize;

final readonly class GenericInstantiation
{
    /**
     * @param list<TypeRef> $concreteTypes Resolved TypeRefs (may themselves be generic for nested cases).
     * @param string        $generatedFqn  Full target FQCN, e.g. `XPHP\Generated\App\Containers\Box\T_5a8eb3f2c7d40918`.
     */
    public function __construct(
        public string $templateFqn,
        public array $concreteTypes,
        public string $generatedFqn,
    ) {
    }
}
