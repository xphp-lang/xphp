<?php

declare(strict_types=1);

namespace XPHP\StaticAnalysis;

/**
 * Resolves the consumer's PHPStan config — the "one config" that drives
 * level/rules/extensions — in priority order:
 *   1. an explicit path (the `--phpstan-config` option),
 *   2. auto-detected `phpstan.neon` / `phpstan.neon.dist` / `phpstan.dist.neon`
 *      at the project root.
 *
 * Returns `null` when none resolves; the runner then analyses with a minimal
 * built-in default instead of a consumer config. The resolved path is consumed
 * by `PhpStanRunner`, which `includes:` it by ABSOLUTE path so the consumer's
 * own relative paths (`bootstrapFiles`, `excludePaths`, …) keep resolving
 * against the consumer's directory rather than the ephemeral config's.
 */
final readonly class PhpStanConfigResolver
{
    /** Auto-detected config filenames, in PHPStan's own precedence order. */
    private const array CANDIDATES = ['phpstan.neon', 'phpstan.neon.dist', 'phpstan.dist.neon'];

    public function __construct(private string $workingDir)
    {
    }

    public function resolve(?string $explicit): ?string
    {
        if ($explicit !== null && $explicit !== '') {
            return is_file($explicit) ? $explicit : null;
        }

        foreach (self::CANDIDATES as $name) {
            $candidate = $this->workingDir . '/' . $name;
            if (is_file($candidate)) {
                return $candidate;
            }
        }

        return null;
    }
}
