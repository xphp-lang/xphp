<?php

declare(strict_types=1);

namespace XPHP\Transpiler\Monomorphize;

use PhpParser\Node\Name;
use PhpParser\Node\Name\FullyQualified;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Function_;
use PhpParser\Node\Stmt\Namespace_;
use PHPUnit\Framework\TestCase;

/**
 * {@see TemplateIndex} — the five call-site symbol tables bundled behind typed accessors.
 * Pins each accessor's hit/miss, the `Class::method` key composition, and the
 * `resolveFunction` global-fallback resolution (the load-bearing branch the free-function
 * closure-argument check relies on).
 */
final class TemplateIndexTest extends TestCase
{
    public function testMethodTemplateComposesTheClassMethodKey(): void
    {
        $map = new ClassMethod('map');
        $index = self::index(methodTemplates: ['App\\Box::map' => $map]);

        self::assertSame($map, $index->methodTemplate('App\\Box', 'map'));
        self::assertNull($index->methodTemplate('App\\Box', 'filter'), 'wrong method misses');
        self::assertNull($index->methodTemplate('App\\Other', 'map'), 'wrong class misses');
    }

    public function testClassLikeHitAndMiss(): void
    {
        $box = new Class_('Box');
        $index = self::index(classesByFqn: ['App\\Box' => $box]);

        self::assertSame($box, $index->classLike('App\\Box'));
        self::assertNull($index->classLike('App\\Missing'));
    }

    public function testFunctionTemplateAndHasFunctionTemplate(): void
    {
        $fn = new Function_('wrap');
        $index = self::index(functionTemplates: ['App\\wrap' => $fn]);

        self::assertSame($fn, $index->functionTemplate('App\\wrap'));
        self::assertNull($index->functionTemplate('App\\nope'));
        self::assertTrue($index->hasFunctionTemplate('App\\wrap'));
        self::assertFalse($index->hasFunctionTemplate('App\\nope'));
    }

    public function testFunctionNamespaceNodeHitAndBareTopLevel(): void
    {
        $ns = new Namespace_(new Name('App'));
        $index = self::index(functionNamespaces: ['App\\wrap' => $ns, 'bare' => null]);

        self::assertSame($ns, $index->functionNamespaceNode('App\\wrap'));
        self::assertNull($index->functionNamespaceNode('bare'), 'bare top-level function → null node');
        self::assertNull($index->functionNamespaceNode('App\\unknown'), 'unindexed → null');
    }

    public function testAllFunctionHitAndMiss(): void
    {
        $fn = new Function_('helper');
        $index = self::index(allFunctionsByFqn: ['App\\helper' => $fn]);

        self::assertSame($fn, $index->allFunction('App\\helper'));
        self::assertNull($index->allFunction('App\\absent'));
    }

    public function testResolveFunctionViaCurrentNamespace(): void
    {
        $fn = new Function_('helper');
        $index = self::index(allFunctionsByFqn: ['App\\helper' => $fn]);
        $ctx = new NamespaceContext();
        $ctx->enterNamespace('App');

        self::assertSame($fn, $index->resolveFunction(new Name('helper'), $ctx));
    }

    public function testResolveFunctionFallsBackToTheGlobalName(): void
    {
        // Defined only as a bare global function; the call sits inside namespace App.
        $fn = new Function_('array_thing');
        $index = self::index(allFunctionsByFqn: ['array_thing' => $fn]);
        $ctx = new NamespaceContext();
        $ctx->enterNamespace('App');

        // Primary lookup (`App\array_thing`) misses; the global fallback resolves it.
        self::assertSame($fn, $index->resolveFunction(new Name('array_thing'), $ctx));
    }

    public function testResolveFunctionHandlesFullyQualifiedName(): void
    {
        $fn = new Function_('doThing');
        $index = self::index(allFunctionsByFqn: ['App\\Lib\\doThing' => $fn]);
        $ctx = new NamespaceContext();
        $ctx->enterNamespace('Other');

        self::assertSame($fn, $index->resolveFunction(new FullyQualified('App\\Lib\\doThing'), $ctx));
    }

    public function testResolveFunctionDoesNotGlobalFallbackForAQualifiedName(): void
    {
        // A function is indexed under the exact `Sub\thing` key the fallback would use
        // ($name->toString()) — so ONLY the qualified-name guard keeps this unresolved.
        // A qualified name resolves its leading segment as a namespace (here `App\Sub\thing`,
        // which is absent) and must never take the global fallback. Drop the guard and this
        // call would wrongly return the function.
        $index = self::index(allFunctionsByFqn: ['Sub\\thing' => new Function_('thing')]);
        $ctx = new NamespaceContext();
        $ctx->enterNamespace('App');

        self::assertNull($index->resolveFunction(new Name('Sub\\thing'), $ctx));
    }

    public function testResolveFunctionReturnsNullWhenUnresolved(): void
    {
        $index = self::index();
        $ctx = new NamespaceContext();
        $ctx->enterNamespace('App');

        self::assertNull($index->resolveFunction(new Name('nope'), $ctx));
    }

    /**
     * @param array<string, ClassMethod> $methodTemplates
     * @param array<string, Class_> $classesByFqn
     * @param array<string, Function_> $functionTemplates
     * @param array<string, ?Namespace_> $functionNamespaces
     * @param array<string, Function_> $allFunctionsByFqn
     */
    private static function index(
        array $methodTemplates = [],
        array $classesByFqn = [],
        array $functionTemplates = [],
        array $functionNamespaces = [],
        array $allFunctionsByFqn = [],
    ): TemplateIndex {
        return new TemplateIndex(
            $methodTemplates,
            $classesByFqn,
            $functionTemplates,
            $functionNamespaces,
            $allFunctionsByFqn,
        );
    }
}
