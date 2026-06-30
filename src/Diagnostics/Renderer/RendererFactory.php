<?php

declare(strict_types=1);

namespace XPHP\Diagnostics\Renderer;

/**
 * Maps a `--format` option to its {@see DiagnosticRenderer}, shared by `check` and `compile` so the two
 * commands render diagnostics identically. Returns null for an unknown format (the caller reports it).
 */
final readonly class RendererFactory
{
    public static function for(string $format): ?DiagnosticRenderer
    {
        return match ($format) {
            'text' => new TextRenderer(),
            'json' => new JsonRenderer(),
            'github' => new GithubRenderer(),
            default => null,
        };
    }
}
