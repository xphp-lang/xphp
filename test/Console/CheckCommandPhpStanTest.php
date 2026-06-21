<?php

declare(strict_types=1);

namespace XPHP\Console\Command;

use PhpParser\ParserFactory;
use PhpParser\PrettyPrinter\Standard as StandardPrinter;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Console\Tester\CommandTester;
use XPHP\FileSystem\FileFinder\NativeFileFinder;
use XPHP\FileSystem\FileReader\NativeFileReader;
use XPHP\FileSystem\FileWriter\NativeFileWriter;
use XPHP\StaticAnalysis\StaticAnalysisGate;
use XPHP\Transpiler\Monomorphize\Compiler;
use XPHP\Transpiler\Monomorphize\SpecializedClassGenerator;
use XPHP\Transpiler\Monomorphize\Specializer;
use XPHP\Transpiler\Monomorphize\XphpSourceParser;

/**
 * End-to-end coverage of the PHPStan pass wired into `xphp check`. Skips when no
 * phpstan binary is installed (mirrors the @group php85 self-skip convention).
 */
#[Group('phpstan')]
final class CheckCommandPhpStanTest extends TestCase
{
    private string $bin;

    protected function setUp(): void
    {
        $bin = realpath(__DIR__ . '/../../vendor/bin/phpstan');
        if ($bin === false) {
            self::markTestSkipped('phpstan binary not installed (vendor/bin/phpstan)');
        }
        $this->bin = $bin;
    }

    public function testReportsBodyTypeErrorAtTheTemplateDeclaration(): void
    {
        $tester = $this->tester();
        $exit = $tester->execute([
            'source' => $this->fixtureDir('body_type_error'),
            '--phpstan-bin' => $this->bin,
            '--phpstan-config' => $this->level5Config(),
            '--format' => 'json',
        ]);

        self::assertSame(1, $exit);

        /** @var array{diagnostics: list<array{code: string, message: string, source: string, file: ?string, line: ?int}>} $decoded */
        $decoded = json_decode($tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);

        $phpstan = array_values(array_filter(
            $decoded['diagnostics'],
            static fn (array $d): bool => $d['source'] === 'phpstan',
        ));
        self::assertNotEmpty($phpstan, 'expected at least one phpstan diagnostic');

        $boxError = array_values(array_filter(
            $phpstan,
            static fn (array $d): bool => str_contains($d['message'], 'should return int but returns string'),
        ));
        self::assertCount(1, $boxError);
        self::assertStringEndsWith('Box.xphp', $boxError[0]['file'] ?? '');
        self::assertSame(7, $boxError[0]['line']);
        self::assertStringStartsWith('phpstan.', $boxError[0]['code']);
    }

    public function testCovariantPrivatePropertyGetterIsPhpStanClean(): void
    {
        // A covariant single-value container that stores its element in a real-typed
        // `private T` property emits a `get(): Banana` over a `private Banana $item`
        // field — which PHPStan can prove. Unlike an `array`-backed collection (whose
        // getter returns `mixed` and trips the pass), this shape is fully clean. Run
        // with the same level-5 config that catches the analogous `returns mixed`
        // error, so a clean result is a real proof, not a too-lax level.
        $tester = $this->tester();
        $exit = $tester->execute([
            'source' => $this->privatePropertyFixtureDir(),
            '--phpstan-bin' => $this->bin,
            '--phpstan-config' => $this->level5Config(),
            '--format' => 'json',
        ]);

        /** @var array{diagnostics: list<array{source: string}>} $decoded */
        $decoded = json_decode($tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);
        $phpstan = array_values(array_filter(
            $decoded['diagnostics'],
            static fn (array $d): bool => $d['source'] === 'phpstan',
        ));

        self::assertSame([], $phpstan, 'private-T covariant getter must produce no PHPStan diagnostics');
        self::assertSame(0, $exit);
    }

    public function testNoPhpstanFlagSkipsThePassAndStaysClean(): void
    {
        $tester = $this->tester();
        $exit = $tester->execute([
            'source' => $this->fixtureDir('body_type_error'),
            '--no-phpstan' => true,
        ]);

        self::assertSame(0, $exit);
        self::assertStringContainsString('No problems found', $tester->getDisplay());
    }

    public function testMissingBinaryWarnsButDoesNotFailTheGate(): void
    {
        $tester = $this->tester();
        $exit = $tester->execute([
            'source' => $this->fixtureDir('body_type_error'),
            '--phpstan-bin' => '/definitely/not/a/real/phpstan',
        ]);

        self::assertSame(0, $exit); // a missing optional tool is a Warning, not a failure
        self::assertStringContainsString('PHPStan was not found', $tester->getDisplay());
    }

    public function testExplicitConfigIsPassedThroughToPhpStan(): void
    {
        // A config that includes a non-existent file fatals PHPStan → a run-failure
        // Warning (exit 0), proving --phpstan-config is honoured rather than ignored.
        $badConfig = sys_get_temp_dir() . '/xphp-cmd-badcfg-' . uniqid('', true) . '.neon';
        file_put_contents($badConfig, "includes:\n    - /no/such/file.neon\n");

        try {
            $tester = $this->tester();
            $exit = $tester->execute([
                'source' => $this->fixtureDir('body_type_error'),
                '--phpstan-bin' => $this->bin,
                '--phpstan-config' => $badConfig,
            ]);

            self::assertSame(0, $exit);
            self::assertStringContainsString('PHPStan could not complete', $tester->getDisplay());
        } finally {
            unlink($badConfig);
        }
    }

    private function tester(): CommandTester
    {
        $phpParser = (new ParserFactory())->createForHostVersion();
        $printer = new StandardPrinter();
        $writer = new NativeFileWriter();
        $compiler = new Compiler(
            new NativeFileReader(),
            $writer,
            new XphpSourceParser($phpParser),
            new Specializer(),
            new SpecializedClassGenerator($printer, $writer),
            $printer,
        );

        return new CommandTester(
            new CheckCommand(new NativeFileFinder(), $compiler, new StaticAnalysisGate($compiler)),
        );
    }

    private function fixtureDir(string $fixture): string
    {
        return realpath(__DIR__ . '/../fixture/check/' . $fixture . '/source')
            ?: throw new RuntimeException("Fixture missing: {$fixture}");
    }

    private function privatePropertyFixtureDir(): string
    {
        return realpath(__DIR__ . '/../fixture/compile/generic_covariant_private_property/source')
            ?: throw new RuntimeException('private-property fixture missing');
    }

    private function level5Config(): string
    {
        return realpath(__DIR__ . '/../fixture/check/phpstan-level5.neon')
            ?: throw new RuntimeException('level5 config fixture missing');
    }
}
