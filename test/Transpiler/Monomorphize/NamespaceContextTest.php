<?php

declare(strict_types=1);

namespace XPHP\Transpiler\Monomorphize;

use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt\Use_;
use PhpParser\Node\UseItem;
use PHPUnit\Framework\TestCase;

final class NamespaceContextTest extends TestCase
{
    public function testBareNameResolvesToCurrentNamespacePrefix(): void
    {
        $ctx = new NamespaceContext();
        $ctx->enterNamespace('App\\Containers');

        self::assertSame('App\\Containers\\Box', $ctx->resolveAgainstContext('Box'));
    }

    public function testLeadingBackslashStripsAndShortCircuits(): void
    {
        $ctx = new NamespaceContext();
        $ctx->enterNamespace('App\\Containers');

        self::assertSame('Other\\Vendor\\Box', $ctx->resolveAgainstContext('\\Other\\Vendor\\Box'));
    }

    public function testUseMapAliasRewritesFirstSegment(): void
    {
        $ctx = new NamespaceContext();
        $ctx->enterNamespace('App\\Containers');
        $ctx->indexUse(self::makeUse('Other\\Vendor\\Box'));

        self::assertSame('Other\\Vendor\\Box', $ctx->resolveAgainstContext('Box'));
    }

    public function testUseAliasOverridesShortName(): void
    {
        $ctx = new NamespaceContext();
        $ctx->enterNamespace('App\\Containers');
        $ctx->indexUse(self::makeUse('Other\\Vendor\\LongName', alias: 'B'));

        self::assertSame('Other\\Vendor\\LongName', $ctx->resolveAgainstContext('B'));
    }

    public function testRelativeNamespaceNameBindsToCurrentNamespace(): void
    {
        // `namespace\Box` is PHP's explicit current-namespace reference; it
        // must NOT fall into the bare-name path (which would produce the
        // impossible `App\Containers\namespace\Box`).
        $ctx = new NamespaceContext();
        $ctx->enterNamespace('App\\Containers');

        self::assertSame('App\\Containers\\Box', $ctx->resolveAgainstContext('namespace\\Box'));
    }

    public function testRelativeNamespaceNameIsNeverCapturedByAUseAlias(): void
    {
        // A colliding `use Other\Box` must not rewrite `namespace\Box` — PHP
        // never applies aliases to relative references.
        $ctx = new NamespaceContext();
        $ctx->enterNamespace('App');
        $ctx->indexUse(self::makeUse('Other\\Box'));

        self::assertSame('App\\Box', $ctx->resolveAgainstContext('namespace\\Box'));
    }

    public function testRelativeNamespaceNameInGlobalNamespace(): void
    {
        $ctx = new NamespaceContext();

        self::assertSame('Box', $ctx->resolveAgainstContext('namespace\\Box'));
    }

    public function testRelativeNamespaceKeywordIsCaseInsensitive(): void
    {
        $ctx = new NamespaceContext();
        $ctx->enterNamespace('App');

        self::assertSame('App\\Box', $ctx->resolveAgainstContext('NAMESPACE\\Box'));
    }

    public function testNameMerelyStartingWithNamespaceIsNotRelative(): void
    {
        // Only the exact `namespace\` keyword segment is relative —
        // `Namespaced\Utils` is an ordinary (legal) class path and must take
        // the bare-name route, keyword-prefix match notwithstanding.
        $ctx = new NamespaceContext();
        $ctx->enterNamespace('App');

        self::assertSame('App\\Namespaced\\Utils', $ctx->resolveAgainstContext('Namespaced\\Utils'));
    }

    public function testUseMapResolutionPreservesTailSegments(): void
    {
        $ctx = new NamespaceContext();
        $ctx->enterNamespace('App');
        $ctx->indexUse(self::makeUse('Other\\Vendor'));

        self::assertSame('Other\\Vendor\\Sub\\Tail', $ctx->resolveAgainstContext('Vendor\\Sub\\Tail'));
    }

    public function testTopLevelNamespaceKeepsBareName(): void
    {
        $ctx = new NamespaceContext();
        $ctx->enterNamespace(null);

        self::assertSame('Box', $ctx->resolveAgainstContext('Box'));
        self::assertSame('', $ctx->currentNamespace());
    }

    public function testUseAliasPlusTailResolvesToAliasedFqnPlusTail(): void
    {
        $ctx = new NamespaceContext();
        $ctx->enterNamespace('App');
        $ctx->indexUse(self::makeUse('Foo\\Bar', alias: 'B'));

        self::assertSame('Foo\\Bar\\Sub', $ctx->resolveAgainstContext('B\\Sub'));
    }

    public function testIndexUseHandlesMultipleUseItemsInOneUseNode(): void
    {
        $ctx = new NamespaceContext();
        $ctx->enterNamespace('App');
        $ctx->indexUse(self::makeMultiUse([
            ['fqn' => 'First\\One',  'alias' => null],
            ['fqn' => 'Second\\Two', 'alias' => 'Aliased'],
        ]));

        self::assertSame('First\\One', $ctx->resolveAgainstContext('One'));
        self::assertSame('Second\\Two', $ctx->resolveAgainstContext('Aliased'));
    }

    public function testEnterNamespaceResetsTheUseMap(): void
    {
        $ctx = new NamespaceContext();
        $ctx->enterNamespace('App\\First');
        $ctx->indexUse(self::makeUse('First\\Hello'));
        self::assertSame('First\\Hello', $ctx->resolveAgainstContext('Hello'));

        $ctx->enterNamespace('App\\Second');
        self::assertSame('App\\Second\\Hello', $ctx->resolveAgainstContext('Hello'));
    }

    public function testIsImportedTrueForAliasedFirstSegment(): void
    {
        $ctx = new NamespaceContext();
        $ctx->enterNamespace('App');
        $ctx->indexUse(self::makeUse('Other\\Vendor\\Box'));

        self::assertTrue($ctx->isImported('Box'));
        self::assertTrue($ctx->isImported('Box\\Sub')); // first segment is what matters
    }

    public function testIsImportedFalseForNonImportedName(): void
    {
        $ctx = new NamespaceContext();
        $ctx->enterNamespace('App');
        $ctx->indexUse(self::makeUse('Other\\Vendor\\Box'));

        self::assertFalse($ctx->isImported('T'));
        self::assertFalse($ctx->isImported('Unrelated'));
    }

    public function testIsImportedResetsWithNamespace(): void
    {
        $ctx = new NamespaceContext();
        $ctx->enterNamespace('App\\First');
        $ctx->indexUse(self::makeUse('First\\Box'));
        self::assertTrue($ctx->isImported('Box'));

        $ctx->enterNamespace('App\\Second');
        self::assertFalse($ctx->isImported('Box'));
    }

    private static function makeUse(string $fqn, ?string $alias = null): Use_
    {
        $useItem = new UseItem(
            new Name($fqn),
            $alias !== null ? new Identifier($alias) : null,
        );
        return new Use_([$useItem]);
    }

    /**
     * @param list<array{fqn: string, alias: ?string}> $entries
     */
    private static function makeMultiUse(array $entries): Use_
    {
        $items = array_map(
            static fn (array $e): UseItem => new UseItem(
                new Name($e['fqn']),
                $e['alias'] !== null ? new Identifier($e['alias']) : null,
            ),
            $entries,
        );
        return new Use_($items);
    }
}
