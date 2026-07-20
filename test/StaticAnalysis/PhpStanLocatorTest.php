<?php

declare(strict_types=1);

namespace XPHP\StaticAnalysis;

use PHPUnit\Framework\TestCase;
use RuntimeException;

final class PhpStanLocatorTest extends TestCase
{
    private string $scratch;

    protected function setUp(): void
    {
        $this->scratch = sys_get_temp_dir() . '/xphp-locator-' . uniqid('', true);
        if (!mkdir($this->scratch, 0o755, true) && !is_dir($this->scratch)) {
            throw new RuntimeException("could not create scratch dir: {$this->scratch}");
        }
    }

    protected function tearDown(): void
    {
        $this->rrmdir($this->scratch);
    }

    public function testExplicitPathWins(): void
    {
        $bin = $this->touch($this->scratch . '/custom-phpstan');
        $locator = new PhpStanLocator($this->scratch, []);

        self::assertSame($bin, $locator->locate($bin));
    }

    public function testExplicitPathThatDoesNotExistResolvesToNull(): void
    {
        $locator = new PhpStanLocator($this->scratch, []);

        self::assertNull($locator->locate($this->scratch . '/nope'));
    }

    public function testFallsBackToConsumerVendorBin(): void
    {
        $vendorBin = $this->touch($this->scratch . '/vendor/bin/phpstan');
        $locator = new PhpStanLocator($this->scratch, []);

        self::assertSame($vendorBin, $locator->locate(null));
    }

    public function testFallsBackToPath(): void
    {
        $pathDir = $this->scratch . '/opt/bin';
        $onPath = $this->touch($pathDir . '/phpstan');
        // No vendor/bin/phpstan here, so PATH is the only resolution.
        $locator = new PhpStanLocator($this->scratch, [$pathDir]);

        self::assertSame($onPath, $locator->locate(null));
    }

    public function testVendorBinTakesPrecedenceOverPath(): void
    {
        $vendorBin = $this->touch($this->scratch . '/vendor/bin/phpstan');
        $pathDir = $this->scratch . '/opt/bin';
        $this->touch($pathDir . '/phpstan');
        $locator = new PhpStanLocator($this->scratch, [$pathDir]);

        self::assertSame($vendorBin, $locator->locate(null));
    }

    public function testPathDirWithTrailingSlashStillResolves(): void
    {
        $pathDir = $this->scratch . '/opt/bin';
        $this->touch($pathDir . '/phpstan');
        // Trailing slash must be normalised (rtrim) so the candidate isn't `…/bin//phpstan`.
        $locator = new PhpStanLocator($this->scratch, [$pathDir . '/']);

        self::assertSame($pathDir . '/phpstan', $locator->locate(null));
    }

    public function testReturnsNullWhenNothingResolves(): void
    {
        $locator = new PhpStanLocator($this->scratch, [$this->scratch . '/empty']);

        self::assertNull($locator->locate(null));
    }

    public function testEmptyExplicitStringIsTreatedAsAbsent(): void
    {
        $vendorBin = $this->touch($this->scratch . '/vendor/bin/phpstan');
        $locator = new PhpStanLocator($this->scratch, []);

        // '' must NOT short-circuit to "explicit"; it falls through to vendor/bin.
        self::assertSame($vendorBin, $locator->locate(''));
    }

    public function testFromEnvironmentSplitsPath(): void
    {
        $pathDir = $this->scratch . '/env/bin';
        $onPath = $this->touch($pathDir . '/phpstan');
        $original = getenv('PATH');
        putenv('PATH=' . $pathDir);
        try {
            $locator = PhpStanLocator::fromEnvironment($this->scratch);
            self::assertSame($onPath, $locator->locate(null));
        } finally {
            putenv($original === false ? 'PATH' : 'PATH=' . $original);
        }
    }

    private function touch(string $path): string
    {
        $dir = dirname($path);
        if (!is_dir($dir) && !mkdir($dir, 0o755, true) && !is_dir($dir)) {
            throw new RuntimeException("could not create dir: {$dir}");
        }
        file_put_contents($path, "#!/usr/bin/env php\n");

        return $path;
    }

    private function rrmdir(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }
        $entries = scandir($path) ?: [];
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $full = $path . '/' . $entry;
            is_dir($full) ? $this->rrmdir($full) : unlink($full);
        }
        rmdir($path);
    }
}
