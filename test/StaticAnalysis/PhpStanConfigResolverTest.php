<?php

declare(strict_types=1);

namespace XPHP\StaticAnalysis;

use PHPUnit\Framework\TestCase;
use RuntimeException;

final class PhpStanConfigResolverTest extends TestCase
{
    private string $scratch;

    protected function setUp(): void
    {
        $this->scratch = sys_get_temp_dir() . '/xphp-config-' . uniqid('', true);
        if (!mkdir($this->scratch, 0o755, true) && !is_dir($this->scratch)) {
            throw new RuntimeException("could not create scratch dir: {$this->scratch}");
        }
    }

    protected function tearDown(): void
    {
        $entries = scandir($this->scratch) ?: [];
        foreach ($entries as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                unlink($this->scratch . '/' . $entry);
            }
        }
        rmdir($this->scratch);
    }

    public function testExplicitConfigWins(): void
    {
        $explicit = $this->scratch . '/custom.neon';
        file_put_contents($explicit, "parameters:\n");
        // An auto-detect candidate also present must be ignored in favour of the explicit one.
        file_put_contents($this->scratch . '/phpstan.neon', "parameters:\n");

        $resolver = new PhpStanConfigResolver($this->scratch);

        self::assertSame($explicit, $resolver->resolve($explicit));
    }

    public function testExplicitConfigThatDoesNotExistResolvesToNull(): void
    {
        $resolver = new PhpStanConfigResolver($this->scratch);

        self::assertNull($resolver->resolve($this->scratch . '/missing.neon'));
    }

    public function testAutoDetectsPhpstanNeon(): void
    {
        $config = $this->scratch . '/phpstan.neon';
        file_put_contents($config, "parameters:\n");

        $resolver = new PhpStanConfigResolver($this->scratch);

        self::assertSame($config, $resolver->resolve(null));
    }

    public function testAutoDetectPrefersNeonOverDistVariants(): void
    {
        $neon = $this->scratch . '/phpstan.neon';
        file_put_contents($neon, "parameters:\n");
        file_put_contents($this->scratch . '/phpstan.neon.dist', "parameters:\n");
        file_put_contents($this->scratch . '/phpstan.dist.neon', "parameters:\n");

        $resolver = new PhpStanConfigResolver($this->scratch);

        self::assertSame($neon, $resolver->resolve(null));
    }

    public function testAutoDetectsNeonDistWhenPlainNeonAbsent(): void
    {
        $dist = $this->scratch . '/phpstan.neon.dist';
        file_put_contents($dist, "parameters:\n");

        $resolver = new PhpStanConfigResolver($this->scratch);

        self::assertSame($dist, $resolver->resolve(null));
    }

    public function testReturnsNullWhenNoConfigPresent(): void
    {
        $resolver = new PhpStanConfigResolver($this->scratch);

        self::assertNull($resolver->resolve(null));
    }

    public function testEmptyExplicitStringFallsThroughToAutoDetect(): void
    {
        $config = $this->scratch . '/phpstan.neon';
        file_put_contents($config, "parameters:\n");

        $resolver = new PhpStanConfigResolver($this->scratch);

        self::assertSame($config, $resolver->resolve(''));
    }
}
