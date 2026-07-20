<?php

declare(strict_types=1);

namespace XPHP\Transpiler\Monomorphize;

use PHPUnit\Framework\TestCase;

/**
 * {@see MethodKey} — the single source of truth for the generic-method-template map key.
 * Pins the composed `"Class::method"` form, the inverse parse, and their round-trip, so the
 * builder write side and {@see TemplateIndex::methodTemplate()} read side can never drift.
 */
final class MethodKeyTest extends TestCase
{
    public function testComposesTheClassMethodString(): void
    {
        self::assertSame('App\\Box::map', (string) new MethodKey('App\\Box', 'map'));
    }

    public function testParseSplitsIntoClassAndMethod(): void
    {
        $key = MethodKey::parse('App\\Box::map');

        self::assertSame('App\\Box', $key->classFqn);
        self::assertSame('map', $key->method);
    }

    public function testParseIsTheInverseOfToString(): void
    {
        $original = new MethodKey('App\\Containers\\Repo', 'fetch');

        self::assertEquals($original, MethodKey::parse((string) $original));
    }

    public function testToStringIsTheInverseOfParse(): void
    {
        self::assertSame('App\\Box::map', (string) MethodKey::parse('App\\Box::map'));
    }
}
