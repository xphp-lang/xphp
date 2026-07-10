<?php

declare(strict_types=1);

namespace XPHP\Transpiler\Monomorphize;

use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt\GroupUse;
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

    public function testResolveNameHonorsAFullyQualifiedNodeDespiteAnAlias(): void
    {
        // A `\App\Box` node must resolve to `App\Box` — never alias-captured,
        // never doubled with the current namespace (both are what flattening
        // it with `toString()` used to produce).
        $ctx = new NamespaceContext();
        $ctx->enterNamespace('App');
        $ctx->indexUse(self::makeUse('Other\\Box'));

        self::assertSame('App\\Box', $ctx->resolveName(new Name\FullyQualified('App\\Box')));
    }

    public function testResolveNameBindsARelativeNodeToTheCurrentNamespaceDespiteAnAlias(): void
    {
        // `namespace\Box` binds to the CURRENT namespace by PHP's rules; the
        // `use Other\Box` alias never applies to a relative name.
        $ctx = new NamespaceContext();
        $ctx->enterNamespace('App');
        $ctx->indexUse(self::makeUse('Other\\Box'));

        self::assertSame('App\\Box', $ctx->resolveName(new Name\Relative('Box')));
    }

    public function testResolveNamePlainNodeStillUsesTheAliasMap(): void
    {
        $ctx = new NamespaceContext();
        $ctx->enterNamespace('App');
        $ctx->indexUse(self::makeUse('Other\\Box'));

        self::assertSame('Other\\Box', $ctx->resolveName(new Name('Box')));
        self::assertSame('Other\\Box', $ctx->resolveName(new Identifier('Box')));
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

    public function testFunctionNameUnqualifiedBindsCurrentNamespace(): void
    {
        $ctx = new NamespaceContext();
        $ctx->enterNamespace('App');

        self::assertSame('App\\helper', $ctx->resolveFunctionName(new Name('helper')));
    }

    public function testFunctionNameUnqualifiedBindsUseFunctionImport(): void
    {
        $ctx = new NamespaceContext();
        $ctx->enterNamespace('Lib');
        $ctx->indexUse(self::makeUse('App\\helper', type: Use_::TYPE_FUNCTION));

        self::assertSame('App\\helper', $ctx->resolveFunctionName(new Name('helper')));
    }

    public function testAClassImportNeverCapturesAFunctionCall(): void
    {
        // A class `use App\helper;` must NOT bind a `helper()` call — PHP keeps class
        // and function symbol namespaces separate. Without a `use function`, the call
        // binds the current namespace.
        $ctx = new NamespaceContext();
        $ctx->enterNamespace('Lib');
        $ctx->indexUse(self::makeUse('App\\helper')); // class import

        self::assertSame('Lib\\helper', $ctx->resolveFunctionName(new Name('helper')));
    }

    public function testFunctionNameFullyQualifiedAndRelativeAndQualified(): void
    {
        $ctx = new NamespaceContext();
        $ctx->enterNamespace('App');
        $ctx->indexUse(self::makeUse('Vendor\\Pkg', alias: 'V')); // namespace import

        self::assertSame('Other\\f', $ctx->resolveFunctionName(new Name\FullyQualified('Other\\f')));
        self::assertSame('App\\f', $ctx->resolveFunctionName(new Name\Relative('f')));
        // Qualified: leading segment resolves via the class/namespace map, not function imports.
        self::assertSame('Vendor\\Pkg\\f', $ctx->resolveFunctionName(new Name('V\\f')));
        // Qualified with no matching import falls to the current namespace.
        self::assertSame('App\\Sub\\f', $ctx->resolveFunctionName(new Name('Sub\\f')));
        // A name merely STARTING with `namespace` is an ordinary qualified path, not the
        // relative `namespace\` keyword — it must take the qualified route, not bind relative.
        self::assertSame('App\\Namespaced\\f', $ctx->resolveFunctionName(new Name('Namespaced\\f')));
    }

    public function testConstNameUsesItsOwnImportMapSeparateFromFunctions(): void
    {
        $ctx = new NamespaceContext();
        $ctx->enterNamespace('Lib');
        $ctx->indexUse(self::makeUse('App\\FACTOR', type: Use_::TYPE_CONSTANT));
        $ctx->indexUse(self::makeUse('App\\helper', type: Use_::TYPE_FUNCTION));

        self::assertSame('App\\FACTOR', $ctx->resolveConstName(new Name('FACTOR')));
        // A `use const` does not bind a function call, and vice-versa.
        self::assertSame('Lib\\FACTOR', $ctx->resolveFunctionName(new Name('FACTOR')));
        self::assertSame('Lib\\helper', $ctx->resolveConstName(new Name('helper')));
    }

    public function testMixedGroupItemTypesRouteToTheRightMaps(): void
    {
        // `use App\{function f, const C, D}` — item-level types partition the maps.
        $ctx = new NamespaceContext();
        $ctx->enterNamespace('Lib');
        $ctx->indexUse(self::makeMultiTypedUse('App', [
            ['name' => 'App\\f', 'type' => Use_::TYPE_FUNCTION],
            ['name' => 'App\\C', 'type' => Use_::TYPE_CONSTANT],
            ['name' => 'App\\D', 'type' => Use_::TYPE_NORMAL],
        ]));

        self::assertSame('App\\f', $ctx->resolveFunctionName(new Name('f')));
        self::assertSame('App\\C', $ctx->resolveConstName(new Name('C')));
        // The class item `D` is not a function/const import.
        self::assertSame('Lib\\f2', $ctx->resolveFunctionName(new Name('f2')));
    }

    public function testFunctionAndConstMapsResetOnNamespaceChange(): void
    {
        $ctx = new NamespaceContext();
        $ctx->enterNamespace('Lib');
        $ctx->indexUse(self::makeUse('App\\helper', type: Use_::TYPE_FUNCTION));
        self::assertSame('App\\helper', $ctx->resolveFunctionName(new Name('helper')));

        $ctx->enterNamespace('Other');
        self::assertSame('Other\\helper', $ctx->resolveFunctionName(new Name('helper')));
    }

    public function testIndexGroupUseFunctionImportsRouteToFunctionMap(): void
    {
        // `use function Vendor\{make, scale};` — statement-level FUNCTION type governs both members.
        $ctx = new NamespaceContext();
        $ctx->enterNamespace('App');
        $ctx->indexGroupUse(self::makeGroupUse('Vendor', [
            ['name' => 'make', 'alias' => null, 'type' => Use_::TYPE_UNKNOWN],
            ['name' => 'scale', 'alias' => null, 'type' => Use_::TYPE_UNKNOWN],
        ], Use_::TYPE_FUNCTION));

        self::assertSame('Vendor\\make', $ctx->resolveFunctionName(new Name('make')));
        self::assertSame('Vendor\\scale', $ctx->resolveFunctionName(new Name('scale')));
        // A group `use function` must not leak into const resolution.
        self::assertSame('App\\make', $ctx->resolveConstName(new Name('make')));
    }

    public function testIndexGroupUseMixedItemTypesRouteToTheRightMaps(): void
    {
        // `use Vendor\{function make, const RATE, Box};` — per-item types partition the maps.
        $ctx = new NamespaceContext();
        $ctx->enterNamespace('App');
        $ctx->indexGroupUse(self::makeGroupUse('Vendor', [
            ['name' => 'make', 'alias' => null, 'type' => Use_::TYPE_FUNCTION],
            ['name' => 'RATE', 'alias' => null, 'type' => Use_::TYPE_CONSTANT],
            ['name' => 'Box', 'alias' => null, 'type' => Use_::TYPE_NORMAL],
        ], Use_::TYPE_UNKNOWN));

        self::assertSame('Vendor\\make', $ctx->resolveFunctionName(new Name('make')));
        self::assertSame('Vendor\\RATE', $ctx->resolveConstName(new Name('RATE')));
        // The class item `Box` is neither a function nor a const import.
        self::assertSame('App\\Box', $ctx->resolveFunctionName(new Name('Box')));
        self::assertSame('App\\Box', $ctx->resolveConstName(new Name('Box')));
    }

    public function testIndexGroupUseHonoursMemberAlias(): void
    {
        // `use function Vendor\{make as mk};` binds the alias, not the member name.
        $ctx = new NamespaceContext();
        $ctx->enterNamespace('App');
        $ctx->indexGroupUse(self::makeGroupUse('Vendor', [
            ['name' => 'make', 'alias' => 'mk', 'type' => Use_::TYPE_UNKNOWN],
        ], Use_::TYPE_FUNCTION));

        self::assertSame('Vendor\\make', $ctx->resolveFunctionName(new Name('mk')));
        // The un-aliased member name does not resolve to the import.
        self::assertSame('App\\make', $ctx->resolveFunctionName(new Name('make')));
    }

    public function testIndexGroupUseClassImportRoutesToClassMapNotSymbolMaps(): void
    {
        // A plain class group-import `use Vendor\{Box, Crate};` is stored in the class/namespace
        // useMap (so a relocated body re-qualifies it to the import target, exactly like a single
        // `use Vendor\Box;`), but contributes NO callable/const alias — the separate-symbol-namespace
        // invariant still holds.
        $ctx = new NamespaceContext();
        $ctx->enterNamespace('App');
        $ctx->indexGroupUse(self::makeGroupUse('Vendor', [
            ['name' => 'Box', 'alias' => null, 'type' => Use_::TYPE_UNKNOWN],
            ['name' => 'Crate', 'alias' => null, 'type' => Use_::TYPE_UNKNOWN],
        ], Use_::TYPE_NORMAL));

        // Class map: the import target, and counts as imported.
        self::assertSame('Vendor\\Box', $ctx->resolveName(new Name('Box')));
        self::assertSame('Vendor\\Crate', $ctx->resolveName(new Name('Crate')));
        self::assertTrue($ctx->isImported('Box'));
        self::assertTrue($ctx->isImported('Crate'));
        // Symbol maps: untouched — an unqualified function/const name still falls to the current ns.
        self::assertSame('App\\Box', $ctx->resolveFunctionName(new Name('Box')));
        self::assertSame('App\\Crate', $ctx->resolveConstName(new Name('Crate')));
    }

    public function testIndexGroupUseClassImportHonoursAliasInClassMap(): void
    {
        // `use Vendor\{Tool as T};` — the alias resolves to the import target in the class map.
        $ctx = new NamespaceContext();
        $ctx->enterNamespace('App');
        $ctx->indexGroupUse(self::makeGroupUse('Vendor', [
            ['name' => 'Tool', 'alias' => 'T', 'type' => Use_::TYPE_UNKNOWN],
        ], Use_::TYPE_NORMAL));

        self::assertSame('Vendor\\Tool', $ctx->resolveName(new Name('T')));
        self::assertTrue($ctx->isImported('T'));
        // The un-aliased member name is NOT imported.
        self::assertSame('App\\Tool', $ctx->resolveName(new Name('Tool')));
        self::assertFalse($ctx->isImported('Tool'));
    }

    public function testIndexGroupUseNamespaceImportResolvesQualifiedNames(): void
    {
        // `use Vendor\{Sub};` then `Sub\Thing` resolves through the class/namespace map — the case a
        // class-only split would have missed.
        $ctx = new NamespaceContext();
        $ctx->enterNamespace('App');
        $ctx->indexGroupUse(self::makeGroupUse('Vendor', [
            ['name' => 'Sub', 'alias' => null, 'type' => Use_::TYPE_UNKNOWN],
        ], Use_::TYPE_NORMAL));

        self::assertSame('Vendor\\Sub\\Thing', $ctx->resolveName(new Name('Sub\\Thing')));
        self::assertTrue($ctx->isImported('Sub'));
    }

    private static function makeUse(string $fqn, ?string $alias = null, int $type = Use_::TYPE_NORMAL): Use_
    {
        $useItem = new UseItem(
            new Name($fqn),
            $alias !== null ? new Identifier($alias) : null,
        );
        return new Use_([$useItem], type: $type);
    }

    /**
     * A single group-style `Use_` whose items carry their own per-item type.
     *
     * @param list<array{name: string, type: int}> $entries
     */
    private static function makeMultiTypedUse(string $prefix, array $entries): Use_
    {
        $items = array_map(
            static fn (array $e): UseItem => new UseItem(new Name($e['name']), null, $e['type']),
            $entries,
        );
        // Statement type UNKNOWN so the per-item types govern (as a mixed group parses).
        return new Use_($items, type: Use_::TYPE_UNKNOWN);
    }

    /**
     * A `GroupUse` (`use PREFIX\{ ... }`). Each member name is the RELATIVE part; the
     * per-item type governs a mixed group, else the statement type applies.
     *
     * @param list<array{name: string, alias: ?string, type: int}> $members
     */
    private static function makeGroupUse(string $prefix, array $members, int $type = Use_::TYPE_NORMAL): GroupUse
    {
        $items = array_map(
            static fn (array $m): UseItem => new UseItem(
                new Name($m['name']),
                $m['alias'] !== null ? new Identifier($m['alias']) : null,
                $m['type'],
            ),
            $members,
        );
        return new GroupUse(new Name($prefix), $items, $type);
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
