<?php

declare(strict_types=1);

namespace XPHP\Transpiler\Monomorphize;

/**
 * A closure-signature leaf that is itself a closure signature — the nested case,
 * e.g. the parameter type in `Closure(Closure(int): int): int` or the return type
 * in `Closure(): Closure(int): int`. Conformance recurses through it (inner
 * parameters contravariant, inner return covariant) in a later work item.
 */
final readonly class SigClosure extends SigType
{
    public function __construct(
        public ClosureSignature $signature,
    ) {
    }
}
