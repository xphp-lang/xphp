<?php

declare(strict_types=1);

namespace XPHP\Transpiler\Monomorphize;

/**
 * A signature leaf that was scanned and erased but not yet structurally parsed —
 * currently a union (`A|B`) or intersection (`A&B`) member type. The raw source
 * text is retained so a later work item can build the proper `SigUnion` /
 * `SigIntersection` node; until then no conformance rule reads it, so the
 * placeholder only has to survive erasure without losing the bytes.
 */
final readonly class SigRaw extends SigType
{
    public function __construct(
        public string $raw,
    ) {
    }
}
