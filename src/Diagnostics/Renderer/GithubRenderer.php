<?php

declare(strict_types=1);

namespace XPHP\Diagnostics\Renderer;

use XPHP\Diagnostics\Diagnostic;
use XPHP\Diagnostics\Severity;

/**
 * GitHub Actions workflow-command output, so findings appear as inline
 * annotations on a PR:
 *
 *     ::error file=src/Box.xphp,line=7,col=3::"int" does not implement "Stringable"
 *
 * `Warning` -> `::warning`, `Notice` -> `::notice`. Message and property values
 * are escaped per GitHub's workflow-command rules (newlines/commas/colons).
 */
final class GithubRenderer implements DiagnosticRenderer
{
    public function render(array $diagnostics): string
    {
        $lines = [];
        foreach ($diagnostics as $d) {
            $command = match ($d->severity) {
                Severity::Error => 'error',
                Severity::Warning => 'warning',
                Severity::Notice => 'notice',
            };

            $props = [];
            if ($d->location !== null) {
                $props[] = 'file=' . self::escapeProperty($d->location->file);
                $props[] = 'line=' . $d->location->line;
                if ($d->location->column !== null) {
                    $props[] = 'col=' . $d->location->column;
                }
            }

            $prefix = $props === [] ? '::' . $command : '::' . $command . ' ' . implode(',', $props);
            $lines[] = $prefix . '::' . self::escapeData($d->message);
        }

        return $lines === [] ? '' : implode(PHP_EOL, $lines) . PHP_EOL;
    }

    private static function escapeData(string $value): string
    {
        return str_replace(['%', "\r", "\n"], ['%25', '%0D', '%0A'], $value);
    }

    private static function escapeProperty(string $value): string
    {
        return str_replace(['%', "\r", "\n", ':', ','], ['%25', '%0D', '%0A', '%3A', '%2C'], $value);
    }
}
