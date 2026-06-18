<?php

declare(strict_types=1);

namespace XPHP\StaticAnalysis;

use PHPUnit\Framework\TestCase;
use XPHP\Diagnostics\DiagnosticSource;
use XPHP\Diagnostics\Severity;

final class PhpStanResultMapperTest extends TestCase
{
    public function testMapsFindingBackToTemplateDeclarationWithTriggeredBy(): void
    {
        $rep = new Representative(
            'XPHP\\Generated\\App\\Box\\T_abc',
            '/tmp/ws/cache/Generated/App/Box/T_abc.php',
            'App\\Box',
            'App\\Box<int>',
            '/project/src/Box.xphp',
            7,
        );
        $finding = new PhpStanFinding($rep->filePath, 15, 'should return int but returns string', 'return.type');

        $diagnostics = PhpStanResultMapper::map([$finding], [$rep]);

        self::assertCount(1, $diagnostics);
        $d = $diagnostics[0];
        self::assertSame(Severity::Error, $d->severity);
        self::assertSame('phpstan.return.type', $d->code);
        self::assertSame('should return int but returns string', $d->message);
        self::assertSame(DiagnosticSource::PhpStan, $d->source);
        self::assertSame('App\\Box<int>', $d->triggeredBy);
        self::assertNotNull($d->location);
        // Anchored at the TEMPLATE declaration, not the generated file/line.
        self::assertSame('/project/src/Box.xphp', $d->location->file);
        self::assertSame(7, $d->location->line);
    }

    public function testFindingWithoutIdentifierGetsGenericCode(): void
    {
        $rep = $this->representative();
        $finding = new PhpStanFinding($rep->filePath, 3, 'some error', null);

        $diagnostics = PhpStanResultMapper::map([$finding], [$rep]);

        self::assertSame('phpstan.error', $diagnostics[0]->code);
    }

    public function testUnmatchedFindingIsSurfacedWithoutLocationOrTriggeredBy(): void
    {
        $rep = $this->representative();
        // A finding in a file that is NOT a known representative (defensive path):
        // surfaced (never dropped) but with no location, so the throwaway temp-dir
        // path it came from never leaks into the report.
        $finding = new PhpStanFinding('/tmp/ws/dist/Other.php', 42, 'orphan error', 'foo.bar');

        $diagnostics = PhpStanResultMapper::map([$finding], [$rep]);

        self::assertCount(1, $diagnostics);
        $d = $diagnostics[0];
        self::assertSame('orphan error', $d->message);
        self::assertNull($d->triggeredBy);
        self::assertNull($d->location);
    }

    public function testEmptyFindingsMapToNoDiagnostics(): void
    {
        self::assertSame([], PhpStanResultMapper::map([], [$this->representative()]));
    }

    public function testMultipleFindingsEachBecomeADiagnostic(): void
    {
        $rep = $this->representative();
        $findings = [
            new PhpStanFinding($rep->filePath, 1, 'first', 'a.b'),
            new PhpStanFinding($rep->filePath, 2, 'second', 'c.d'),
        ];

        $diagnostics = PhpStanResultMapper::map($findings, [$rep]);

        self::assertCount(2, $diagnostics);
        self::assertSame(['first', 'second'], array_map(static fn ($d): string => $d->message, $diagnostics));
    }

    private function representative(): Representative
    {
        return new Representative(
            'XPHP\\Generated\\App\\Box\\T_abc',
            '/tmp/ws/cache/Generated/App/Box/T_abc.php',
            'App\\Box',
            'App\\Box<int>',
            '/project/src/Box.xphp',
            7,
        );
    }
}
