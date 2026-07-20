<?php

declare(strict_types=1);

namespace XPHP\Diagnostics;

/**
 * Origin of a {@see Diagnostic}: xphp's own generic checks, or (Phase 2) a
 * PHPStan finding mapped back onto `.xphp` source. String-backed for renderer
 * output and so the JSON contract carries a stable label.
 */
enum DiagnosticSource: string
{
    case Xphp = 'xphp';
    case PhpStan = 'phpstan';
}
