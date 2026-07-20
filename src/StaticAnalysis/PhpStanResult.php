<?php

declare(strict_types=1);

namespace XPHP\StaticAnalysis;

/**
 * Outcome of one PHPStan invocation. `ranOk` distinguishes "PHPStan ran and
 * these are its findings" (possibly empty) from "PHPStan could not run" — a
 * config/fatal error that produces no parseable JSON. The caller turns a failed
 * run into a non-fatal Warning rather than a false clean pass.
 */
final readonly class PhpStanResult
{
    /** @param list<PhpStanFinding> $findings */
    private function __construct(
        public bool $ranOk,
        public array $findings,
        public ?string $errorOutput,
    ) {
    }

    /** @param list<PhpStanFinding> $findings */
    public static function ok(array $findings): self
    {
        return new self(true, $findings, null);
    }

    public static function failed(string $errorOutput): self
    {
        return new self(false, [], $errorOutput);
    }
}
