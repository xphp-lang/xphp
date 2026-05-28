<?php

declare(strict_types=1);

namespace XPHP\Lsp\Test\Resolver;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use XPHP\Lsp\Resolver\ClassFqnPredicate;
use XPHP\Lsp\Resolver\PhpDefinitionResolver;

/**
 * Pins {@see ClassFqnPredicate::is} as the shared gate every
 * `reflectClassLike` caller consults.  Originally introduced as
 * `PhpDefinitionResolver::isClassFqn` (Phase 6 Fix 1, commit
 * 4f22c4a); Cycle C promotes it.  The backwards-compat alias on
 * `PhpDefinitionResolver` is asserted here so external callers
 * (and the existing definition-resolver tests) keep working.
 */
final class ClassFqnPredicateTest extends TestCase
{
    /**
     * @return iterable<string,array{0:string}>
     */
    public static function accepted(): iterable
    {
        yield 'simple name' => ['User'];
        yield 'namespaced' => ['App\\Models\\User'];
        yield 'leading backslash' => ['\\App\\Models\\User'];
        yield 'nullable' => ['?App\\Models\\User'];
        yield 'nullable + leading backslash' => ['?\\App\\Models\\User'];
        yield 'underscore-prefix' => ['_internal'];
    }

    /**
     * @return iterable<string,array{0:string}>
     */
    public static function rejected(): iterable
    {
        yield 'empty' => [''];
        yield 'missing sentinel' => ['<missing>'];
        yield 'union' => ['App\\Foo|App\\Bar'];
        yield 'intersection' => ['App\\Foo&App\\Bar'];
        yield 'grouped union of intersections' => [
            '(PhpParser\\Node\\Stmt\\ClassLike&PhpParser\\Node\\Stmt\\Class_)|(PhpParser\\Node\\Stmt\\ClassLike&PhpParser\\Node\\Stmt\\Interface_)',
        ];
        yield 'grouped method-call union' => [
            '(PhpParser\\Node&PhpParser\\Node\\Expr\\MethodCall)|(PhpParser\\Node&PhpParser\\Node\\Expr\\NullsafeMethodCall)',
        ];
        yield 'integer literal zero' => ['0'];
        yield 'integer literal one' => ['1'];
        yield 'string literal' => ["'foo'"];
    }

    #[DataProvider('accepted')]
    public function testIsReturnsTrueForPlausibleClassFqns(string $name): void
    {
        self::assertTrue(ClassFqnPredicate::is($name), $name);
    }

    #[DataProvider('rejected')]
    public function testIsReturnsFalseForNonClassStrings(string $name): void
    {
        self::assertFalse(ClassFqnPredicate::is($name), $name);
    }

    #[DataProvider('accepted')]
    public function testPhpDefinitionResolverIsClassFqnAliasAccepts(string $name): void
    {
        // Backwards-compat: the original site keeps its public static.
        self::assertTrue(PhpDefinitionResolver::isClassFqn($name), $name);
    }

    #[DataProvider('rejected')]
    public function testPhpDefinitionResolverIsClassFqnAliasRejects(string $name): void
    {
        self::assertFalse(PhpDefinitionResolver::isClassFqn($name), $name);
    }
}
