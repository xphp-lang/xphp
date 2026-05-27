<?php

declare(strict_types=1);

namespace XPHP\Lsp;

/**
 * Single chokepoint for the LSP server's diagnostic `[xphp-lsp …]`
 * stderr lines.
 *
 * Why a helper instead of direct `fwrite(STDERR, …)`: Infection's
 * `InitialTestsRunner` calls `$process->stop()` the moment the test
 * subprocess writes anything to stderr (see
 * src/Process/Runner/InitialTestsRunner.php inside infection.phar) —
 * a single stray fwrite during the initial-tests phase aborts the
 * mutation run with `exit code 143` (SIGTERM) before any mutant is
 * scored.  Setting `XPHP_LSP_QUIET=1` (phpunit.xml.dist sets it
 * globally) makes this helper a no-op so tests can exercise code
 * paths that would otherwise log without tripping that guard.
 *
 * Production callers see no behaviour change: the same
 * `[xphp-lsp …]` lines flow to fd-2 for editor hosts (PhpStorm,
 * VS Code) to capture.
 */
final class Stderr
{
    public static function write(string $message): void
    {
        if (getenv('XPHP_LSP_QUIET') === '1') {
            return;
        }
        @fwrite(STDERR, $message);
    }
}
