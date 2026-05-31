<?php

declare(strict_types=1);

namespace XPHP\Lsp\Test\Resolver;

use PhpParser\ParserFactory;
use PHPUnit\Framework\TestCase;
use XPHP\Lsp\Resolver\ClassNameImportContext;

final class ClassNameImportContextTest extends TestCase
{
    public function testExtractCapturesNamespaceAndUseMapFromSimpleUse(): void
    {
        $ctx = $this->extract(<<<'PHP'
        <?php
        namespace App\Demos;
        use App\Models\Tag;
        use App\Models\User as Account;
        PHP);

        self::assertSame('App\\Demos', $ctx->namespace);
        self::assertSame(
            ['Tag' => 'App\\Models\\Tag', 'Account' => 'App\\Models\\User'],
            $ctx->useMap,
        );
    }

    public function testExtractHandlesGroupUseAsTopLevel(): void
    {
        $ctx = $this->extract(<<<'PHP'
        <?php
        namespace App\Demos;
        use App\Models\{Tag, User as Account};
        PHP);

        self::assertSame('App\\Demos', $ctx->namespace);
        self::assertSame(
            ['Tag' => 'App\\Models\\Tag', 'Account' => 'App\\Models\\User'],
            $ctx->useMap,
        );
    }

    public function testExtractSkipsFunctionAndConstUses(): void
    {
        $ctx = $this->extract(<<<'PHP'
        <?php
        namespace App\Demos;
        use function App\Models\helper;
        use const App\Models\VERSION;
        use App\Models\Tag;
        PHP);

        self::assertSame(['Tag' => 'App\\Models\\Tag'], $ctx->useMap);
    }

    public function testExtractHandlesFileWithoutNamespace(): void
    {
        $ctx = $this->extract(<<<'PHP'
        <?php
        use App\Models\Tag;
        PHP);

        self::assertSame('', $ctx->namespace);
        self::assertSame(['Tag' => 'App\\Models\\Tag'], $ctx->useMap);
    }

    public function testChooseInsertTextReturnsAliasWhenFqnIsAlreadyImported(): void
    {
        $ctx = new ClassNameImportContext(
            namespace: 'App\\Demos',
            useMap: ['Tag' => 'App\\Models\\Tag'],
        );
        self::assertSame('Tag', $ctx->chooseInsertText('App\\Models\\Tag'));
    }

    public function testChooseInsertTextReturnsAliasForAliasedUse(): void
    {
        $ctx = new ClassNameImportContext(
            namespace: 'App\\Demos',
            useMap: ['MyTag' => 'App\\Models\\Tag'],
        );
        self::assertSame('MyTag', $ctx->chooseInsertText('App\\Models\\Tag'));
    }

    public function testChooseInsertTextReturnsShortNameWhenSameNamespace(): void
    {
        $ctx = new ClassNameImportContext(
            namespace: 'App\\Models',
            useMap: [],
        );
        self::assertSame('Tag', $ctx->chooseInsertText('App\\Models\\Tag'));
    }

    public function testChooseInsertTextReturnsLeadingBackslashFqnWhenNoImportAndDifferentNamespace(): void
    {
        $ctx = new ClassNameImportContext(
            namespace: 'App\\Demos',
            useMap: [],
        );
        self::assertSame('\\App\\Models\\Tag', $ctx->chooseInsertText('App\\Models\\Tag'));
    }

    public function testChooseInsertTextFallsBackToFqOnConflictingShortName(): void
    {
        // Same namespace as Tag, but a use already binds the short name
        // to a DIFFERENT FQN. Inserting bare `Tag` would resolve to
        // App\Other\Tag, not App\Models\Tag.
        $ctx = new ClassNameImportContext(
            namespace: 'App\\Models',
            useMap: ['Tag' => 'App\\Other\\Tag'],
        );
        self::assertSame('\\App\\Models\\Tag', $ctx->chooseInsertText('App\\Models\\Tag'));
    }

    public function testChooseInsertTextStripsLeadingBackslashOnInputBeforeMatching(): void
    {
        // Callers may pass FQNs in either form; the result should be
        // identical.
        $ctx = new ClassNameImportContext(
            namespace: 'App\\Demos',
            useMap: ['Tag' => 'App\\Models\\Tag'],
        );
        self::assertSame('Tag', $ctx->chooseInsertText('\\App\\Models\\Tag'));
    }

    public function testChooseInsertTextNormalizesLeadingBackslashInUseMapValues(): void
    {
        // Map values arrive without leading backslash from nikic's parser,
        // but the defensive `ltrim` lets callers construct contexts directly
        // with either form and still get a match. Locks the ltrim against
        // an UnwrapLtrim mutation.
        $ctx = new ClassNameImportContext(
            namespace: 'App\\Demos',
            useMap: ['Tag' => '\\App\\Models\\Tag'],
        );
        self::assertSame('Tag', $ctx->chooseInsertText('App\\Models\\Tag'));
    }

    public function testExtractKeepsAllItemsInGroupUseEvenWithFunctionMixedIn(): void
    {
        // `use App\Models\{function helper, Tag};` is a group-use with
        // per-item type overrides: the first item is a function-import
        // (TYPE_FUNCTION), the second is the default class-import
        // (TYPE_NORMAL). The extractor must `continue` past the function
        // item to keep `Tag` in the use map. A `break` mutation would
        // drop `Tag` and lose the import.
        $ctx = $this->extract(<<<'PHP'
        <?php
        namespace App\Demos;
        use App\Models\{function helper, Tag};
        PHP);

        self::assertSame(['Tag' => 'App\\Models\\Tag'], $ctx->useMap);
    }

    public function testExtractStopsAtFirstBracketedNamespaceBlock(): void
    {
        // PHP allows multiple bracketed namespace blocks in one file.
        // The extractor stops at the FIRST: subsequent blocks aren't
        // relevant once we've located our namespace + its stmts.
        // A `break -> continue` mutation would overwrite with the
        // LAST block's namespace + stmts, losing the first's imports.
        $ctx = $this->extract(<<<'PHP'
        <?php
        namespace App\First {
            use App\Models\Tag;
        }
        namespace App\Second {
            use App\Other\Plastic;
        }
        PHP);

        self::assertSame('App\\First', $ctx->namespace);
        self::assertSame(['Tag' => 'App\\Models\\Tag'], $ctx->useMap);
    }

    public function testChooseInsertTextHandlesGlobalNamespaceClass(): void
    {
        // A class like `\Stringable` (parent namespace = ''): the
        // leading-backslash FQ branch is the safest bet from any
        // non-global file.
        $ctx = new ClassNameImportContext(
            namespace: 'App\\Demos',
            useMap: [],
        );
        self::assertSame('\\Stringable', $ctx->chooseInsertText('Stringable'));
    }

    public function testChooseInsertTextReturnsShortNameForGlobalClassFromGlobalNamespace(): void
    {
        $ctx = new ClassNameImportContext(
            namespace: '',
            useMap: [],
        );
        self::assertSame('Stringable', $ctx->chooseInsertText('Stringable'));
    }

    /** @return ClassNameImportContext */
    private function extract(string $source): ClassNameImportContext
    {
        $parser = (new ParserFactory())->createForHostVersion();
        $ast = $parser->parse($source);
        self::assertNotNull($ast);
        return ClassNameImportContext::extract($ast);
    }
}
