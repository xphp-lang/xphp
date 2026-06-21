<?php

declare(strict_types=1);

namespace XPHP\Transpiler\Monomorphize;

use PHPUnit\Framework\TestCase;

/**
 * Locks the canonical-form and display-form output of TypeRef.
 * The canonical form drives the sha256 hash that names every specialized class,
 * so any silent drift in the format would invalidate generated FQCNs across builds.
 */
final class TypeRefTest extends TestCase
{
    public function testCanonicalReturnsBareNameForPlainClass(): void
    {
        $ref = new TypeRef('App\\Models\\Plastic');
        self::assertSame('App\\Models\\Plastic', $ref->canonical());
    }

    public function testCanonicalReturnsBareNameForScalar(): void
    {
        $ref = new TypeRef('string', isScalar: true);
        self::assertSame('string', $ref->canonical());
    }

    public function testCanonicalStripsLeadingBackslashOnPlainName(): void
    {
        $ref = new TypeRef('\\App\\Models\\Plastic');
        self::assertSame('App\\Models\\Plastic', $ref->canonical());
    }

    public function testCanonicalFormatsSingleArgGeneric(): void
    {
        $ref = new TypeRef('App\\Containers\\Box', [new TypeRef('App\\Models\\Plastic')]);
        self::assertSame('App\\Containers\\Box<App\\Models\\Plastic>', $ref->canonical());
    }

    public function testCanonicalFormatsMultiArgGenericWithCommaSeparator(): void
    {
        $ref = new TypeRef('App\\Containers\\Map', [
            new TypeRef('string', isScalar: true),
            new TypeRef('App\\Models\\User'),
        ]);
        self::assertSame('App\\Containers\\Map<string,App\\Models\\User>', $ref->canonical());
    }

    public function testCanonicalFormatsNestedGenericRecursively(): void
    {
        $inner = new TypeRef('App\\Containers\\Collection', [new TypeRef('App\\Models\\Plastic')]);
        $outer = new TypeRef('App\\Containers\\Box', [$inner]);
        self::assertSame(
            'App\\Containers\\Box<App\\Containers\\Collection<App\\Models\\Plastic>>',
            $outer->canonical(),
        );
    }

    public function testCanonicalStripsLeadingBackslashRecursively(): void
    {
        $ref = new TypeRef('\\App\\Box', [new TypeRef('\\App\\Plastic')]);
        self::assertSame('App\\Box<App\\Plastic>', $ref->canonical());
    }

    public function testToDisplayStringPlainName(): void
    {
        $ref = new TypeRef('App\\Models\\Plastic');
        self::assertSame('App\\Models\\Plastic', $ref->toDisplayString());
    }

    public function testToDisplayStringSingleArgWithAngleBrackets(): void
    {
        $ref = new TypeRef('App\\Containers\\Box', [new TypeRef('App\\Models\\Plastic')]);
        self::assertSame(
            'App\\Containers\\Box<App\\Models\\Plastic>',
            $ref->toDisplayString(),
        );
    }

    public function testToDisplayStringMultiArgUsesCommaSpaceSeparator(): void
    {
        $ref = new TypeRef('App\\Containers\\Map', [
            new TypeRef('string', isScalar: true),
            new TypeRef('App\\Models\\User'),
        ]);
        // Display form is human-readable (comma + space); canonical form is comma-only.
        self::assertSame('App\\Containers\\Map<string, App\\Models\\User>', $ref->toDisplayString());
    }

    public function testToDisplayStringNestedGeneric(): void
    {
        $inner = new TypeRef('App\\Containers\\Collection', [new TypeRef('App\\Models\\Plastic')]);
        $outer = new TypeRef('App\\Containers\\Box', [$inner]);
        self::assertSame(
            'App\\Containers\\Box<App\\Containers\\Collection<App\\Models\\Plastic>>',
            $outer->toDisplayString(),
        );
    }

    public function testIsConcreteIsTrueForPlainClass(): void
    {
        self::assertTrue((new TypeRef('App\\Plastic'))->isConcrete());
    }

    public function testIsConcreteIsTrueForScalar(): void
    {
        self::assertTrue((new TypeRef('string', isScalar: true))->isConcrete());
    }

    public function testIsConcreteIsFalseForBareTypeParam(): void
    {
        self::assertFalse((new TypeRef('T', isTypeParam: true))->isConcrete());
    }

    public function testIsConcreteRecursesIntoArgsAndDetectsUnresolvedTypeParam(): void
    {
        $ref = new TypeRef('App\\Box', [new TypeRef('T', isTypeParam: true)]);
        self::assertFalse($ref->isConcrete(), 'a generic carrying an unresolved type-param is not concrete');
    }

    public function testIsConcreteIsTrueWhenAllNestedArgsAreConcrete(): void
    {
        $inner = new TypeRef('App\\Collection', [new TypeRef('App\\Plastic')]);
        $outer = new TypeRef('App\\Box', [$inner]);
        self::assertTrue($outer->isConcrete());
    }
}
