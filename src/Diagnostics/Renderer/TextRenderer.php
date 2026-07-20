<?php

declare(strict_types=1);

namespace XPHP\Diagnostics\Renderer;

use XPHP\Diagnostics\Diagnostic;

/**
 * Human-readable text output: one block per diagnostic, e.g.
 *
 *     error: "int" does not extend/implement "Stringable"
 *       at /src/Box.xphp:7:3 [xphp.bound_violation]
 *       triggered by App\Box<int>
 *
 * The message may be multi-line; the location/code sit on their own line so
 * multi-line messages stay readable.
 */
final class TextRenderer implements DiagnosticRenderer
{
    public function render(array $diagnostics): string
    {
        if ($diagnostics === []) {
            return 'No problems found.' . PHP_EOL;
        }

        $blocks = [];
        foreach ($diagnostics as $d) {
            $lines = [sprintf('%s: %s', $d->severity->value, $d->message)];
            $lines[] = '  ' . $this->locationSuffix($d);
            if ($d->triggeredBy !== null) {
                $lines[] = '  triggered by ' . $d->triggeredBy;
            }
            $blocks[] = implode(PHP_EOL, $lines);
        }

        return implode(PHP_EOL . PHP_EOL, $blocks) . PHP_EOL;
    }

    private function locationSuffix(Diagnostic $d): string
    {
        $code = '[' . $d->code . ']';
        if ($d->location === null) {
            return $code;
        }
        $at = $d->location->file . ':' . $d->location->line;
        if ($d->location->column !== null) {
            $at .= ':' . $d->location->column;
        }

        return 'at ' . $at . ' ' . $code;
    }
}
