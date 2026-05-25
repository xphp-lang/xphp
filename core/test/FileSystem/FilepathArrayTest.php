<?php

declare(strict_types=1);

namespace XPHP\FileSystem;

use PHPUnit\Framework\TestCase;

final class FilepathArrayTest extends TestCase
{
    public function testFilterActuallyFilters(): void
    {
        // Kills `UnwrapArrayFilter` on FilepathArray::filter:21 — without array_filter,
        // every path would survive regardless of the callback's return value.
        $paths = new FilepathArray('a.php', 'b.xphp', 'c.php', 'd.xphp');

        $filtered = $paths->filter(static fn (string $p): bool => str_ends_with($p, '.xphp'));

        self::assertSame(['b.xphp', 'd.xphp'], $filtered->filepaths);
    }

    public function testFilterPreservesSequentialIndexing(): void
    {
        // Kills `UnwrapArrayValues` on FilepathArray::filter:21 — without array_values,
        // the gaps left by array_filter would survive as non-sequential keys.
        $paths = new FilepathArray('a.xphp', 'b.php', 'c.xphp', 'd.php');

        $filtered = $paths->filter(static fn (string $p): bool => str_ends_with($p, '.xphp'));

        // Direct index access proves the keys are 0,1 — not 0,2 (which a foreach iteration
        // would never reveal but a $filepaths[1] lookup would).
        self::assertSame('a.xphp', $filtered->filepaths[0]);
        self::assertSame('c.xphp', $filtered->filepaths[1]);
        self::assertCount(2, $filtered->filepaths);
    }
}
