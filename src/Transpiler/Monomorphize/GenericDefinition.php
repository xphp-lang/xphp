<?php

declare(strict_types=1);

namespace XPHP\Transpiler\Monomorphize;

use PhpParser\Node\Stmt\Class_;

final readonly class GenericDefinition
{
    /**
     * @param list<string> $typeParams Names of the generic type parameters, e.g. ['T'] or ['K', 'V'].
     */
    public function __construct(
        public string $templateFqn,
        public string $templateShortName,
        public array $typeParams,
        public Class_ $templateAst,
        public string $sourceFile,
    ) {
    }
}
