<?php

declare(strict_types=1);

namespace XPHP\StaticAnalysis;

use PHPUnit\Framework\TestCase;

/**
 * Pure (no-PHPStan) tests for {@see StaticAnalysisGate}'s static helpers, so they
 * run regardless of whether a phpstan binary is installed.
 */
final class StaticAnalysisGatePureTest extends TestCase
{
    public function testBuildScanDirectoriesIncludesVendorWhenPresent(): void
    {
        $work = sys_get_temp_dir() . '/xphp-scan-' . uniqid('', true);
        mkdir($work . '/vendor', 0o755, true);
        try {
            self::assertSame(
                ['/ws/dist', '/ws/Generated', $work . '/vendor'],
                StaticAnalysisGate::buildScanDirectories('/ws/dist', '/ws/Generated', $work),
            );
        } finally {
            rmdir($work . '/vendor');
            rmdir($work);
        }
    }

    public function testBuildScanDirectoriesOmitsVendorWhenAbsent(): void
    {
        $work = sys_get_temp_dir() . '/xphp-novendor-' . uniqid('', true);

        self::assertSame(
            ['/ws/dist', '/ws/Generated'],
            StaticAnalysisGate::buildScanDirectories('/ws/dist', '/ws/Generated', $work),
        );
    }

    public function testSummarizeReturnsShortMessageUnchanged(): void
    {
        self::assertSame('boom', StaticAnalysisGate::summarize('boom'));
    }

    public function testSummarizeTruncatesLongMessageWithEllipsis(): void
    {
        $long = str_repeat('x', 1000);

        $summary = StaticAnalysisGate::summarize($long, 500);

        self::assertSame(str_repeat('x', 500) . '…', $summary);
    }

    public function testSummarizeMapsNullAndEmptyToUnknownError(): void
    {
        self::assertSame('unknown error', StaticAnalysisGate::summarize(null));
        self::assertSame('unknown error', StaticAnalysisGate::summarize(''));
    }
}
