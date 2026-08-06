<?php

declare(strict_types=1);

namespace XPHP\Transpiler\Monomorphize;

use RuntimeException;

/**
 * A source-level rejection raised while scanning .xphp generic syntax, carrying
 * the original-source line the offending token sits on.
 *
 * It extends {@see RuntimeException} so compile-mode callers that already catch
 * `RuntimeException` keep catching it unchanged — only the line is added. Check
 * mode catches it specifically to report the real line in its diagnostic instead
 * of the line-1 fallback used for position-less parse failures.
 *
 * An optional stable diagnostic `code` (e.g. `xphp.alias_cycle`) lets check mode
 * report a specific code instead of the generic parse-error code; throw sites that
 * omit it keep the generic code.
 */
final class XphpParseException extends RuntimeException
{
    public function __construct(
        string $message,
        private readonly int $sourceLine,
        private readonly ?string $diagnosticCode = null,
    ) {
        parent::__construct($message);
    }

    /**
     * The 1-based original-source line of the offending token, or 0 when no
     * token position was available at the throw site.
     */
    public function sourceLine(): int
    {
        return $this->sourceLine;
    }

    /** The stable diagnostic code for this rejection, or null to use the generic parse-error code. */
    public function diagnosticCode(): ?string
    {
        return $this->diagnosticCode;
    }
}
