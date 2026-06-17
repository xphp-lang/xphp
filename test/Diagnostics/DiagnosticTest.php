<?php

declare(strict_types=1);

namespace XPHP\Diagnostics;

use PHPUnit\Framework\TestCase;

final class DiagnosticTest extends TestCase
{
    public function testRequiredFieldsAndDefaults(): void
    {
        $d = new Diagnostic(Severity::Error, 'xphp.bound_violation', 'boom');

        self::assertSame(Severity::Error, $d->severity);
        self::assertSame('xphp.bound_violation', $d->code);
        self::assertSame('boom', $d->message);
        self::assertNull($d->location);
        self::assertNull($d->triggeredBy);
        self::assertSame(DiagnosticSource::Xphp, $d->source);
    }

    public function testAllFieldsSet(): void
    {
        $loc = new SourceLocation('/src/Box.xphp', 12, 5);
        $d = new Diagnostic(
            Severity::Warning,
            'phpstan.return.type',
            'bad return',
            $loc,
            'App\\Box<int>',
            DiagnosticSource::PhpStan,
        );

        self::assertSame(Severity::Warning, $d->severity);
        self::assertSame($loc, $d->location);
        self::assertSame('App\\Box<int>', $d->triggeredBy);
        self::assertSame(DiagnosticSource::PhpStan, $d->source);
    }

    public function testSourceBackingValues(): void
    {
        self::assertSame('xphp', DiagnosticSource::Xphp->value);
        self::assertSame('phpstan', DiagnosticSource::PhpStan->value);
    }
}
