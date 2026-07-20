<?php

declare(strict_types=1);

namespace XPHP\Diagnostics;

/**
 * Severity of a {@see Diagnostic}. `Error` fails the `xphp check` gate (non-zero
 * exit); `Warning` and `Notice` are reported but do not fail it.
 *
 * String-backed so renderers (text/json/github) can emit the label directly.
 */
enum Severity: string
{
    case Error = 'error';
    case Warning = 'warning';
    case Notice = 'notice';

    /**
     * True iff a diagnostic of this severity should fail the check gate.
     */
    public function isFailing(): bool
    {
        return $this === self::Error;
    }
}
