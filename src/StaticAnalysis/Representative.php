<?php

declare(strict_types=1);

namespace XPHP\StaticAnalysis;

/**
 * One specialized class chosen to stand in for a whole template when running
 * PHPStan. Body-level type errors are erased to nominal types during
 * specialization, so they manifest identically in every specialization of a
 * template — analysing a single representative surfaces the bug once instead of
 * once per concrete instantiation (which would be N duplicate findings).
 *
 * Carries everything both halves of the pipeline need: `filePath` is what
 * {@see PhpStanRunner} analyses; the rest is how {@see PhpStanResultMapper} maps
 * a finding in that file back to the originating `.xphp` template declaration.
 */
final readonly class Representative
{
    public function __construct(
        public string $generatedFqn,
        public string $filePath,
        public string $templateFqn,
        public string $label,
        public string $declFile,
        public int $declLine,
    ) {
    }
}
