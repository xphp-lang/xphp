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

    /**
     * Commit another (per-file) collector's obligations into this one. Used so a file's obligations
     * are absorbed only after that file has parsed successfully: a file that aborts mid-parse has its
     * AST dropped from the hierarchy, so its obligations — which may reference now-absent types —
     * must be dropped with it rather than checked against a hierarchy that no longer contains them.
     */
    public function absorb(self $other): void
    {
        foreach ($other->obligations as $obligation) {
            $this->obligations[] = $obligation;
        }
    }

    /** @return list<AliasBoundObligation> */
    public function all(): array
    {
        return $this->obligations;
    }
}
