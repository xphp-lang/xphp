<?php

declare(strict_types=1);

namespace XPHP\Diagnostics;

/**
 * Accumulates {@see Diagnostic}s during a check run.
 *
 * Mutable by design — the single sink threaded through the validation phase.
 * `xphp compile` does NOT use a collector (validators throw on the first error,
 * as before); `xphp check` passes one so every diagnostic of a validation phase
 * is gathered instead of aborting on the first.
 */
final class DiagnosticCollector
{
    /** @var list<Diagnostic> */
    private array $diagnostics = [];

    public function add(Diagnostic $diagnostic): void
    {
        $this->diagnostics[] = $diagnostic;
    }

    /**
     * @return list<Diagnostic> In insertion order.
     */
    public function all(): array
    {
        return $this->diagnostics;
    }

    /**
     * True iff any collected diagnostic fails the gate (Error severity).
     */
    public function hasErrors(): bool
    {
        foreach ($this->diagnostics as $diagnostic) {
            if ($diagnostic->severity->isFailing()) {
                return true;
            }
        }

        return false;
    }

    public function count(): int
    {
        return count($this->diagnostics);
    }
}
