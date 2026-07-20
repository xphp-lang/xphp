<?php

declare(strict_types=1);

namespace XPHP\Transpiler\Monomorphize;

/**
 * A single, provable way a candidate closure signature fails to conform to a
 * target `Closure(...)` type — the result of {@see ClosureSignatureConformance}.
 *
 * The engine returns one of these (or null, meaning "conforms, or the mismatch
 * is not provable and is therefore gradually accepted") purely as data; the
 * {@see ClosureConformanceValidator} turns it into a diagnostic message with the
 * source-site context prepended. `$detail` is the reason clause only.
 */
final readonly class ClosureConformanceViolation
{
    public const KIND_ARITY_TOO_FEW = 'arity_too_few';
    public const KIND_ARITY_REQUIRES_MORE = 'arity_requires_more';
    public const KIND_VARIADIC_REQUIRED = 'variadic_required';
    public const KIND_BYREF = 'byref';
    public const KIND_PARAM_TYPE = 'param_type';
    public const KIND_RETURN_TYPE = 'return_type';

    public function __construct(
        public string $kind,
        public string $detail,
    ) {
    }
}
