<?php

declare(strict_types=1);

namespace XPHP\Transpiler\Monomorphize;

final readonly class CompileResult
{
    public function __construct(
        public int $sourceCount,
        public int $generatedCount,
        public Registry $registry,
    ) {
    }
}
