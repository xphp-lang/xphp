<?php

declare(strict_types=1);

namespace XPHP\Diagnostics\Renderer;

use XPHP\Diagnostics\Diagnostic;

/**
 * Renders a list of diagnostics into a string for a chosen output format
 * (`xphp check --format=text|json|github`).
 */
interface DiagnosticRenderer
{
    /**
     * @param list<Diagnostic> $diagnostics
     */
    public function render(array $diagnostics): string;
}
