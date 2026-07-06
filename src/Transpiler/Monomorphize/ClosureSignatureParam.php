<?php

declare(strict_types=1);

namespace XPHP\Transpiler\Monomorphize;

/**
 * One parameter of a closure signature (see {@see ClosureSignature}).
 *
 * The parameter NAME is deliberately not stored: names are insignificant to
 * conformance and may be omitted; only the structural `&` (by-reference) and
 * `...` (variadic) markers matter, so those are captured as flags while the name
 * — if any — is discarded at parse time.
 */
final readonly class ClosureSignatureParam
{
    public function __construct(
        public SigType $type,
        public bool $byRef = false,
        public bool $variadic = false,
    ) {
    }
}
