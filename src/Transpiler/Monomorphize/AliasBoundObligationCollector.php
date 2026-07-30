<?php

declare(strict_types=1);

namespace XPHP\Transpiler\Monomorphize;

use XPHP\Diagnostics\SourceLocation;

/**
 * Accumulates {@see AliasBoundObligation}s captured across every parsed file, for a single
 * post-hierarchy verification pass ({@see AliasBoundValidator}). A collector is threaded into the
 * parser only on the compile/check path; the standalone parse / LSP path passes none, so capture is
 * inert there.
 */
final class AliasBoundObligationCollector
{
    /** @var list<AliasBoundObligation> */
    private array $obligations = [];

    /**
     * @param list<TypeParam> $typeParams
     * @param list<TypeRef>   $args
     */
    public function add(array $typeParams, array $args, string $label, SourceLocation $location): void
    {
        $this->obligations[] = new AliasBoundObligation($typeParams, $args, $label, $location);
    }

    /** @return list<AliasBoundObligation> */
    public function all(): array
    {
        return $this->obligations;
    }
}
