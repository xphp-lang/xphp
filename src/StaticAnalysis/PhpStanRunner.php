<?php

declare(strict_types=1);

namespace XPHP\StaticAnalysis;

use Symfony\Component\Process\Process;

/**
 * Runs the consumer's PHPStan over the representative specialized classes.
 *
 * The mechanism (validated by spike): write an ephemeral config that
 * `includes:` the consumer's own config by ABSOLUTE path — so the consumer's
 * relative `bootstrapFiles`/`excludePaths` keep resolving against THEIR
 * directory — and adds `scanDirectories` so the generated/dist code can resolve
 * its user + vendor symbols (without that, PHPStan floods "unknown class"). The
 * files to analyse are passed on the CLI, which overrides the consumer's
 * `paths`. Level/rules/extensions are inherited from the consumer config; when
 * there is none, a modest built-in default level is used.
 *
 * `php <phpstan-bin>` is invoked (rather than the bin directly) so a resolved
 * path needs no execute bit and a `.phar`/proxy works the same way.
 */
final readonly class PhpStanRunner
{
    private const int TIMEOUT_SECONDS = 300;
    private const int DEFAULT_LEVEL = 5;

    public function __construct(private string $phpstanBin)
    {
    }

    /**
     * @param list<string> $analysePaths    absolute representative file paths to analyse
     * @param list<string> $scanDirectories absolute dirs scanned for symbol resolution (dist, Generated, vendor)
     * @param ?string       $consumerConfig absolute path to the consumer's neon, or null
     * @param string        $ephemeralConfigPath where to write the generated config (inside the workspace)
     */
    public function run(
        array $analysePaths,
        array $scanDirectories,
        ?string $consumerConfig,
        string $ephemeralConfigPath,
    ): PhpStanResult {
        file_put_contents($ephemeralConfigPath, self::buildConfig($scanDirectories, $consumerConfig));

        $process = new Process(self::buildCommand($this->phpstanBin, $ephemeralConfigPath, $analysePaths));
        // @infection-ignore-all MethodCallRemoval -- the timeout is a runaway guard;
        // dropping it leaves Symfony's 60s default, which produces an identical result
        // for every analysable input. Not observable without a >60s-hanging fixture.
        $process->setTimeout(self::TIMEOUT_SECONDS);
        $process->run();

        return PhpStanOutputParser::parse($process->getOutput(), $process->getErrorOutput());
    }

    /**
     * The argv used to invoke PHPStan. `php <bin>` (not the bin directly) so a
     * resolved path needs no execute bit and a `.phar`/proxy works the same way.
     * Exposed (static, pure) so the exact command is unit-testable.
     *
     * @param list<string> $analysePaths
     * @return list<string>
     */
    public static function buildCommand(string $phpstanBin, string $configPath, array $analysePaths): array
    {
        return [
            PHP_BINARY,
            $phpstanBin,
            'analyse',
            '--no-progress',
            '--error-format=json',
            // Lift the default 128M ceiling: scanning the consumer's vendor dir for
            // symbol resolution routinely exceeds it, which would fatal (no JSON) and
            // degrade the gate to a Warning. -1 defers to the OS rather than imposing
            // an arbitrary cap that's wrong for large projects.
            '--memory-limit=-1',
            '--configuration=' . $configPath,
            ...$analysePaths,
        ];
    }

    /**
     * The ephemeral NEON the run writes. Exposed (static, pure) so its exact
     * shape is unit-testable without invoking PHPStan.
     *
     * @param list<string> $scanDirectories
     */
    public static function buildConfig(array $scanDirectories, ?string $consumerConfig): string
    {
        $lines = [];

        if ($consumerConfig !== null) {
            $lines[] = 'includes:';
            $lines[] = '    - ' . self::neonString($consumerConfig);
        }

        $lines[] = 'parameters:';
        if ($consumerConfig === null) {
            // No consumer config to inherit a level from — pick a modest default
            // so PHPStan still does something useful.
            $lines[] = '    level: ' . self::DEFAULT_LEVEL;
        }
        $lines[] = '    scanDirectories:';
        foreach ($scanDirectories as $dir) {
            $lines[] = '        - ' . self::neonString($dir);
        }

        return implode("\n", $lines) . "\n";
    }

    private static function neonString(string $value): string
    {
        return '"' . addcslashes($value, '"\\') . '"';
    }
}
