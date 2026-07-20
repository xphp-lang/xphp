<?php

declare(strict_types=1);

namespace XPHP\StaticAnalysis;

/**
 * Resolves the PHPStan executable to shell out to, in priority order:
 *   1. an explicit path (the `--phpstan-bin` option),
 *   2. the consumer project's `vendor/bin/phpstan`,
 *   3. a `phpstan` binary found on `$PATH`.
 *
 * Returns `null` when none resolves — the caller turns that into a non-fatal
 * Warning (a missing optional tool never fails the gate; see CheckCommand).
 *
 * PHPStan itself stays `require-dev` for xphp and is never bundled in the PHAR;
 * this locator finds the CONSUMER's install at runtime.
 */
final readonly class PhpStanLocator
{
    /** @param list<string> $pathDirs directories from `$PATH`, already split */
    public function __construct(
        private string $workingDir,
        private array $pathDirs,
    ) {
    }

    public static function fromEnvironment(string $workingDir): self
    {
        // getenv returns string|false; an empty/blank PATH explodes to harmless
        // empty-string dirs (no real directory is named ''), so the only case worth
        // guarding is the false (unset) one.
        $path = getenv('PATH');
        $dirs = is_string($path) ? explode(PATH_SEPARATOR, $path) : [];

        return new self($workingDir, $dirs);
    }

    public function locate(?string $explicit): ?string
    {
        if ($explicit !== null && $explicit !== '') {
            return self::usable($explicit) ? $explicit : null;
        }

        $vendorBin = $this->workingDir . '/vendor/bin/phpstan';
        if (self::usable($vendorBin)) {
            return $vendorBin;
        }

        foreach ($this->pathDirs as $dir) {
            $candidate = rtrim($dir, '/') . '/phpstan';
            if (self::usable($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    private static function usable(string $path): bool
    {
        return is_file($path);
    }
}
