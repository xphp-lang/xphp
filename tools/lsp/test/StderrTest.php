<?php

declare(strict_types=1);

namespace XPHP\Lsp\Test;

use PHPUnit\Framework\TestCase;
use XPHP\Lsp\Stderr;

/**
 * Behavioural tests for the {@see Stderr} write chokepoint.  The
 * production `write()` writes to fd-2 directly, which isn't capturable
 * from inside PHPUnit -- so we exercise `writeTo()` with a
 * `php://memory` stream and assert on its contents.  Both variants
 * share the same env-mute branch, so testing one covers both.
 */
final class StderrTest extends TestCase
{
    /** @var string|false */
    private string|false $originalEnv;

    protected function setUp(): void
    {
        $this->originalEnv = getenv('XPHP_LSP_QUIET');
    }

    protected function tearDown(): void
    {
        if ($this->originalEnv === false) {
            putenv('XPHP_LSP_QUIET');
        } else {
            putenv('XPHP_LSP_QUIET=' . $this->originalEnv);
        }
    }

    public function testMutesWhenQuietEnvIsOne(): void
    {
        putenv('XPHP_LSP_QUIET=1');
        $stream = self::memoryStream();

        Stderr::writeTo("[xphp-lsp …] should-be-muted\n", $stream);

        self::assertSame('', self::readStream($stream));
    }

    public function testWritesWhenQuietEnvIsUnset(): void
    {
        putenv('XPHP_LSP_QUIET');
        $stream = self::memoryStream();

        Stderr::writeTo('hello stderr', $stream);

        self::assertSame('hello stderr', self::readStream($stream));
    }

    public function testWritesWhenQuietEnvIsNotExactlyOne(): void
    {
        // The mute condition is `=== '1'` -- any other truthy-looking
        // value (e.g. `'0'`, `'true'`, `'yes'`) MUST still write.
        // This pins the `Identical` mutator: flipping `===` to `!==`
        // would invert the branch and silently mute when the value
        // isn't `'1'`.
        foreach (['0', 'true', 'yes', '', '2'] as $value) {
            putenv('XPHP_LSP_QUIET=' . $value);
            $stream = self::memoryStream();
            Stderr::writeTo("value=$value\n", $stream);
            self::assertSame(
                "value=$value\n",
                self::readStream($stream),
                "expected stderr write when XPHP_LSP_QUIET={$value}",
            );
        }
    }

    public function testWriteDelegatesToWriteToWithStderr(): void
    {
        // `write()` is a thin shim over `writeTo($message, STDERR)`.
        // Its body has no observable state we can probe directly from
        // PHPUnit (fd-2 isn't captured), so we use the `XPHP_LSP_QUIET`
        // env to assert the delegation lands inside the same gated
        // path -- if `write()` somehow bypassed `writeTo` and wrote
        // unconditionally, PHPUnit's error output would carry the
        // string and `Infection`'s initial-tests phase would SIGTERM
        // (which is exactly what the helper is designed to prevent).
        // Calling `write()` here is the assertion: the run survives.
        putenv('XPHP_LSP_QUIET=1');
        Stderr::write('this must not surface from the test process');
        $this->expectNotToPerformAssertions();
    }

    /**
     * @return resource
     */
    private static function memoryStream()
    {
        $s = fopen('php://memory', 'w+');
        if ($s === false) {
            self::fail('fopen php://memory failed');
        }
        return $s;
    }

    /**
     * @param resource $stream
     */
    private static function readStream($stream): string
    {
        rewind($stream);
        $contents = stream_get_contents($stream);
        return $contents === false ? '' : $contents;
    }
}
