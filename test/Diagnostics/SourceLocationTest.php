<?php

declare(strict_types=1);

namespace XPHP\Diagnostics;

use PHPUnit\Framework\TestCase;

final class SourceLocationTest extends TestCase
{
    public function testColumnDefaultsToNull(): void
    {
        $loc = new SourceLocation('/src/Box.xphp', 42);

        self::assertSame('/src/Box.xphp', $loc->file);
        self::assertSame(42, $loc->line);
        self::assertNull($loc->column);
    }

    public function testColumnIsPreservedWhenGiven(): void
    {
        $loc = new SourceLocation('/src/Box.xphp', 42, 7);

        self::assertSame(7, $loc->column);
    }
}
