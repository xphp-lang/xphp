<?php

declare(strict_types=1);

namespace XPHP\Diagnostics\Renderer;

use XPHP\Diagnostics\Diagnostic;

/**
 * Machine-readable JSON output. Stable contract (depended on by CI tooling):
 *
 *     {
 *         "diagnostics": [
 *             {
 *                 "severity": "error",          // "error" | "warning" | "notice"
 *                 "code": "xphp.bound_violation",
 *                 "message": "...",
 *                 "source": "xphp",             // "xphp" | "phpstan"
 *                 "triggeredBy": null,          // string | null
 *                 "file": "/src/Box.xphp",      // string | null
 *                 "line": 7,                    // int | null
 *                 "column": 3                   // int | null
 *             }
 *         ]
 *     }
 */
final class JsonRenderer implements DiagnosticRenderer
{
    public function render(array $diagnostics): string
    {
        $items = [];
        foreach ($diagnostics as $d) {
            $items[] = [
                'severity' => $d->severity->value,
                'code' => $d->code,
                'message' => $d->message,
                'source' => $d->source->value,
                'triggeredBy' => $d->triggeredBy,
                'file' => $d->location?->file,
                'line' => $d->location?->line,
                'column' => $d->location?->column,
            ];
        }

        return json_encode(
            ['diagnostics' => $items],
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        ) . PHP_EOL;
    }
}
