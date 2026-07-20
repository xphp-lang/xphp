<?php

declare(strict_types=1);

namespace XPHP\Diagnostics;

use PHPUnit\Framework\TestCase;

final class DiagnosticCollectorTest extends TestCase
{
    public function testEmptyCollector(): void
    {
        $c = new DiagnosticCollector();

        self::assertSame([], $c->all());
        self::assertFalse($c->hasErrors());
        self::assertSame(0, $c->count());
    }

    public function testAddPreservesInsertionOrder(): void
    {
        $c = new DiagnosticCollector();
        $first = new Diagnostic(Severity::Notice, 'a', 'first');
        $second = new Diagnostic(Severity::Warning, 'b', 'second');
        $c->add($first);
        $c->add($second);

        self::assertSame([$first, $second], $c->all());
        self::assertSame(2, $c->count());
    }

    public function testHasErrorsIsFalseWithoutErrorSeverity(): void
    {
        $c = new DiagnosticCollector();
        $c->add(new Diagnostic(Severity::Warning, 'a', 'w'));
        $c->add(new Diagnostic(Severity::Notice, 'b', 'n'));

        self::assertFalse($c->hasErrors());
    }

    public function testHasErrorsIsTrueWhenAnyErrorPresent(): void
    {
        $c = new DiagnosticCollector();
        $c->add(new Diagnostic(Severity::Warning, 'a', 'w'));
        $c->add(new Diagnostic(Severity::Error, 'b', 'e'));

        self::assertTrue($c->hasErrors());
    }
}
