<?php

declare(strict_types=1);

namespace XPHP\StaticAnalysis;

/**
 * One file-level PHPStan message, parsed from `--error-format=json` output.
 * `file` is whatever absolute path PHPStan reported (a generated specialized
 * class, or a rewritten user file under dist/); `identifier` is PHPStan's
 * stable rule id (e.g. `return.type`) when present.
 */
final readonly class PhpStanFinding
{
    public function __construct(
        public string $file,
        public int $line,
        public string $message,
        public ?string $identifier = null,
    ) {
    }
}
