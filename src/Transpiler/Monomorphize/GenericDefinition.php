<?php

declare(strict_types=1);

namespace XPHP\Transpiler\Monomorphize;

use PhpParser\Node\Stmt\ClassLike;

final readonly class GenericDefinition
{
    /**
     * @param list<TypeParam> $typeParams Generic type parameters in declaration order, e.g.
     *     [TypeParam('T'), TypeParam('K', 'App\\Comparable')]. Each may carry an optional
     *     bound — the upper-type the concrete arg must satisfy at instantiation time.
     */
    public function __construct(
        public string $templateFqn,
        public string $templateShortName,
        public array $typeParams,
        public ClassLike $templateAst,
        public string $sourceFile,
    ) {
    }

    /**
     * Helper for callers that only care about the parameter NAMES (most of the pipeline:
     * substitution, registry-serialization, etc).
     *
     * @return list<string>
     */
    public function typeParamNames(): array
    {
        return array_map(static fn (TypeParam $p): string => $p->name, $this->typeParams);
    }
}
