<?php

declare(strict_types=1);

namespace XPHP\Diagnostics;

use PHPUnit\Framework\TestCase;

final class SeverityTest extends TestCase
{
    public function testErrorIsFailing(): void
    {
        self::assertTrue(Severity::Error->isFailing());
    }

    public function testWarningIsNotFailing(): void
    {
        self::assertFalse(Severity::Warning->isFailing());
    }

    public function testNoticeIsNotFailing(): void
    {
        self::assertFalse(Severity::Notice->isFailing());
    }

    public function testBackingValues(): void
    {
        self::assertSame('error', Severity::Error->value);
        self::assertSame('warning', Severity::Warning->value);
        self::assertSame('notice', Severity::Notice->value);
    }
}
