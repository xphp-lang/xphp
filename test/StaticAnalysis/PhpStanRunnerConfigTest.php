<?php

declare(strict_types=1);

namespace XPHP\StaticAnalysis;

use PHPUnit\Framework\TestCase;

/**
 * Pure tests for the ephemeral NEON {@see PhpStanRunner::buildConfig} writes
 * (no PHPStan invocation, so these always run).
 */
final class PhpStanRunnerConfigTest extends TestCase
{
    public function testWithConsumerConfigIncludesItAndOmitsLevel(): void
    {
        $config = PhpStanRunner::buildConfig(
            ['/abs/dist', '/abs/Generated'],
            '/project/phpstan.neon',
        );

        // Level is inherited from the consumer config, so it must NOT be set here.
        self::assertSame(
            "includes:\n"
            . "    - \"/project/phpstan.neon\"\n"
            . "parameters:\n"
            . "    scanDirectories:\n"
            . "        - \"/abs/dist\"\n"
            . "        - \"/abs/Generated\"\n",
            $config,
        );
    }

    public function testWithoutConsumerConfigSetsDefaultLevelAndNoIncludes(): void
    {
        $config = PhpStanRunner::buildConfig(['/abs/dist'], null);

        // No consumer config, so a default level is set and there are no includes.
        self::assertSame(
            "parameters:\n"
            . "    level: 5\n"
            . "    scanDirectories:\n"
            . "        - \"/abs/dist\"\n",
            $config,
        );
    }

    public function testPathsAreNeonQuotedAndEscaped(): void
    {
        $config = PhpStanRunner::buildConfig(['/weird/pa"th\\x'], null);

        // Double quote and backslash must be escaped inside the NEON double-quoted string.
        self::assertSame(
            "parameters:\n"
            . "    level: 5\n"
            . "    scanDirectories:\n"
            . "        - \"/weird/pa\\\"th\\\\x\"\n",
            $config,
        );
    }

    public function testConfigEndsWithNewline(): void
    {
        $config = PhpStanRunner::buildConfig(['/abs/dist'], null);

        self::assertStringEndsWith("\n", $config);
    }

    public function testBuildCommandIsPhpThenBinThenFlagsThenAllAnalysePaths(): void
    {
        $command = PhpStanRunner::buildCommand('/bin/phpstan', '/tmp/ephemeral.neon', ['/a/One.php', '/a/Two.php']);

        self::assertSame([
            PHP_BINARY,
            '/bin/phpstan',
            'analyse',
            '--no-progress',
            '--error-format=json',
            '--memory-limit=-1',
            '--configuration=/tmp/ephemeral.neon',
            '/a/One.php',
            '/a/Two.php',
        ], $command);
    }

    public function testPathsWithSpacesAreNeonQuoted(): void
    {
        $config = PhpStanRunner::buildConfig(['/has a space/dist'], null);

        self::assertSame(
            "parameters:\n"
            . "    level: 5\n"
            . "    scanDirectories:\n"
            . "        - \"/has a space/dist\"\n",
            $config,
        );
    }
}
