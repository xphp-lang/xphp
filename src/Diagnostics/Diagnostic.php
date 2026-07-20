<?php

declare(strict_types=1);

namespace XPHP\Diagnostics;

/**
 * A single diagnostic emitted by `xphp check`.
 *
 *  - `code` is a stable machine identifier (e.g. `xphp.bound_violation`,
 *    `xphp.variance_position`) that tooling can match on.
 *  - `location` is the originating `.xphp` position, or `null` when a finding
 *    has no single source location.
 *  - `triggeredBy` names the concrete instantiation (e.g. `App\Box<int>`) that
 *    surfaced a finding inside a template body — only set for Phase-2 (PHPStan)
 *    diagnostics mapped back to a declaration.
 *
 * Immutable.
 */
final readonly class Diagnostic
{
    public function __construct(
        public Severity $severity,
        public string $code,
        public string $message,
        public ?SourceLocation $location = null,
        public ?string $triggeredBy = null,
        public DiagnosticSource $source = DiagnosticSource::Xphp,
    ) {
    }
}
