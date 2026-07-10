<?php

declare(strict_types=1);

namespace XPHP\Console;

use PHPUnit\Framework\TestCase;

/**
 * Guards the autoloader bootstrap in `bin/xphp`. The CLI must locate Composer's
 * autoloader whether xphp is a standalone checkout (its own `vendor/`) or
 * installed as a dependency (the consuming project's `vendor/`, three levels up
 * from `bin/`), and must fail with a clear, non-fatal message when no autoloader
 * exists. Regression coverage for the hardcoded `dirname(__DIR__) . '/vendor/...'`
 * that broke every downstream consumer.
 */
final class BinAutoloadBootstrapTest extends TestCase
{
    private string $projectRoot;
    private string $workDir;

    protected function setUp(): void
    {
        $this->projectRoot = dirname(__DIR__, 2);
        $this->workDir = sys_get_temp_dir() . '/xphp-bin-bootstrap-' . uniqid('', true);
        mkdir($this->workDir, 0o755, true);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->workDir)) {
            self::rrmdir($this->workDir);
        }
    }

    public function testRunsInStandaloneCheckout(): void
    {
        // The real checkout has its own vendor/ — candidate #3 of the bootstrap.
        $result = $this->runBin($this->projectRoot . '/bin/xphp', ['--version']);

        self::assertSame(0, $result['exit'], $result['stderr']);
        self::assertStringContainsString('xphp', $result['stdout']);
    }

    public function testRunsWhenInstalledAsDependency(): void
    {
        // Reproduce the consumer layout: vendor/xphp-lang/xphp/bin/xphp with the
        // real autoloader three levels up at vendor/autoload.php, and crucially NO
        // vendor/ inside the package (the path the old hardcoded require assumed).
        $pkgBinDir = $this->workDir . '/vendor/xphp-lang/xphp/bin';
        mkdir($pkgBinDir, 0o755, true);
        self::assertTrue(copy($this->projectRoot . '/bin/xphp', $pkgBinDir . '/xphp'));

        // A stub that just pulls in the real autoloader, so XPHP + symfony/console
        // classes resolve without a network `composer install`.
        file_put_contents(
            $this->workDir . '/vendor/autoload.php',
            '<?php require ' . var_export($this->projectRoot . '/vendor/autoload.php', true) . ';' . PHP_EOL,
        );

        $result = $this->runBin($pkgBinDir . '/xphp', ['--version']);

        self::assertSame(0, $result['exit'], $result['stderr']);
        self::assertStringContainsString('xphp', $result['stdout']);
    }

    public function testFailsGracefullyWhenNoAutoloaderFound(): void
    {
        // Same package layout but with no reachable autoload.php anywhere.
        $pkgBinDir = $this->workDir . '/vendor/xphp-lang/xphp/bin';
        mkdir($pkgBinDir, 0o755, true);
        self::assertTrue(copy($this->projectRoot . '/bin/xphp', $pkgBinDir . '/xphp'));

        $result = $this->runBin($pkgBinDir . '/xphp', ['--version']);

        self::assertSame(1, $result['exit']);
        // The diagnostic must go to STDERR (separate pipe), not STDOUT.
        self::assertSame(
            "xphp: could not locate Composer's autoloader. Run `composer install`." . PHP_EOL,
            $result['stderr'],
        );
    }

    /**
     * Run `PHP_BINARY <binPath> <args...>` with separate STDOUT/STDERR pipes.
     *
     * @param list<string> $args
     * @return array{exit:int, stdout:string, stderr:string}
     */
    private function runBin(string $binPath, array $args): array
    {
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $proc = proc_open(
            array_merge([PHP_BINARY, $binPath], $args),
            $descriptors,
            $pipes,
        );
        self::assertIsResource($proc);

        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]) ?: '';
        $stderr = stream_get_contents($pipes[2]) ?: '';
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($proc);

        return ['exit' => $exit, 'stdout' => $stdout, 'stderr' => $stderr];
    }

    private static function rrmdir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            is_dir($path) ? self::rrmdir($path) : unlink($path);
        }
        rmdir($dir);
    }
}
