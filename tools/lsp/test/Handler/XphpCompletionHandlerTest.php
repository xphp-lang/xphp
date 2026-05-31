<?php

declare(strict_types=1);

namespace XPHP\Lsp\Test\Handler;

use PhpParser\ParserFactory;
use Phpactor\LanguageServer\Core\Workspace\Workspace as PhpactorWorkspace;
use Phpactor\LanguageServerProtocol\CompletionItem;
use Phpactor\LanguageServerProtocol\CompletionItemKind;
use Phpactor\LanguageServerProtocol\CompletionList;
use Phpactor\LanguageServerProtocol\CompletionParams;
use Phpactor\LanguageServerProtocol\Position;
use Phpactor\LanguageServerProtocol\TextDocumentIdentifier;
use Phpactor\LanguageServerProtocol\TextDocumentItem;
use PHPUnit\Framework\TestCase;
use XPHP\Lsp\Analyzer\Analyzer;
use XPHP\Lsp\Analyzer\ParsedDocumentCache;
use XPHP\Lsp\Handler\WorkspaceSymbols;
use XPHP\Lsp\Handler\XphpCompletionHandler;
use XPHP\Lsp\PositionMap;
use XPHP\Lsp\Reflection\FqnIndex;
use XPHP\Lsp\Reflection\ReflectorFactory;
use XPHP\Transpiler\Monomorphize\XphpSourceParser;
use function Amp\Promise\wait;

final class XphpCompletionHandlerTest extends TestCase
{
    public function testSuggestsWorkspaceClassesInsideTypeArgPosition(): void
    {
        $workspace = new PhpactorWorkspace();
        $workspace->open(new TextDocumentItem('/Models.xphp', 'xphp', 1, <<<'XPHP'
        <?php
        namespace App\Models;
        class Plastic {}
        class Metal {}
        XPHP));
        // Cursor at end of `Box<` line — type-arg position with empty prefix.
        $useSource = "<?php\nnamespace App;\n\$x = new Box<";
        $workspace->open(new TextDocumentItem('/Use.xphp', 'xphp', 1, $useSource));

        $list = $this->complete($workspace, '/Use.xphp', $useSource, strlen($useSource));

        $labels = array_map(static fn (CompletionItem $i): string => $i->label, $list->items);
        self::assertContains('Plastic', $labels);
        self::assertContains('Metal', $labels);
        self::assertContains('int', $labels, 'scalar types must also be suggested');
        // Class items use scope-aware insertText. The fixture file's
        // namespace is `App` (not `App\Models`) and has no `use App\Models\Plastic`
        // import, so the only safe form is leading-backslash FQ.
        // Inserting the bare FQN (`App\Models\Plastic`) would namespace-prepend
        // to `App\App\Models\Plastic` inside `namespace App;` and
        // autoload-fail at runtime.
        foreach ($list->items as $item) {
            if ($item->label === 'Plastic') {
                self::assertSame('\\App\\Models\\Plastic', $item->insertText);
                self::assertSame(CompletionItemKind::CLASS_, $item->kind);
            }
        }
    }

    public function testInsertsShortNameWhenFqnIsAlreadyImported(): void
    {
        $workspace = new PhpactorWorkspace();
        $workspace->open(new TextDocumentItem('/Models.xphp', 'xphp', 1, <<<'XPHP'
        <?php
        namespace App\Models;
        class Plastic {}
        XPHP));
        $useSource = "<?php\nnamespace App;\nuse App\\Models\\Plastic;\n\$x = new Box<";
        $workspace->open(new TextDocumentItem('/Use.xphp', 'xphp', 1, $useSource));

        $list = $this->complete($workspace, '/Use.xphp', $useSource, strlen($useSource));

        foreach ($list->items as $item) {
            if ($item->label === 'Plastic') {
                self::assertSame('Plastic', $item->insertText);
                return;
            }
        }
        self::fail('expected a Plastic completion item');
    }

    public function testInsertsShortNameWhenCandidateIsInSameNamespace(): void
    {
        $workspace = new PhpactorWorkspace();
        $workspace->open(new TextDocumentItem('/Models.xphp', 'xphp', 1, <<<'XPHP'
        <?php
        namespace App\Models;
        class Plastic {}
        XPHP));
        $useSource = "<?php\nnamespace App\\Models;\n\$x = new Box<";
        $workspace->open(new TextDocumentItem('/Use.xphp', 'xphp', 1, $useSource));

        $list = $this->complete($workspace, '/Use.xphp', $useSource, strlen($useSource));

        foreach ($list->items as $item) {
            if ($item->label === 'Plastic') {
                self::assertSame('Plastic', $item->insertText);
                return;
            }
        }
        self::fail('expected a Plastic completion item');
    }

    public function testInsertsAliasedShortNameForAliasedUse(): void
    {
        $workspace = new PhpactorWorkspace();
        $workspace->open(new TextDocumentItem('/Models.xphp', 'xphp', 1, <<<'XPHP'
        <?php
        namespace App\Models;
        class Plastic {}
        XPHP));
        $useSource = "<?php\nnamespace App;\nuse App\\Models\\Plastic as MyPlastic;\n\$x = new Box<";
        $workspace->open(new TextDocumentItem('/Use.xphp', 'xphp', 1, $useSource));

        $list = $this->complete($workspace, '/Use.xphp', $useSource, strlen($useSource));

        foreach ($list->items as $item) {
            if ($item->label === 'Plastic') {
                self::assertSame('MyPlastic', $item->insertText);
                return;
            }
        }
        self::fail('expected a Plastic completion item');
    }

    public function testFallsBackToFqWhenShortNameIsBoundToDifferentFqn(): void
    {
        // Two classes share the short name `Plastic`; the file imports
        // the wrong one. The completion item for App\Models\Plastic must
        // emit the FQ form, otherwise the inserted `Plastic` would
        // resolve to App\Other\Plastic.
        $workspace = new PhpactorWorkspace();
        $workspace->open(new TextDocumentItem('/Models.xphp', 'xphp', 1, <<<'XPHP'
        <?php
        namespace App\Models;
        class Plastic {}
        XPHP));
        $workspace->open(new TextDocumentItem('/Other.xphp', 'xphp', 1, <<<'XPHP'
        <?php
        namespace App\Other;
        class Plastic {}
        XPHP));
        $useSource = "<?php\nnamespace App;\nuse App\\Other\\Plastic;\n\$x = new Box<";
        $workspace->open(new TextDocumentItem('/Use.xphp', 'xphp', 1, $useSource));

        $list = $this->complete($workspace, '/Use.xphp', $useSource, strlen($useSource));

        $modelsItem = null;
        $otherItem = null;
        foreach ($list->items as $item) {
            if ($item->detail === 'App\\Models\\Plastic') {
                $modelsItem = $item;
            }
            if ($item->detail === 'App\\Other\\Plastic') {
                $otherItem = $item;
            }
        }
        self::assertNotNull($modelsItem);
        self::assertNotNull($otherItem);
        self::assertSame('\\App\\Models\\Plastic', $modelsItem->insertText, 'unimported same-short collides → FQ');
        self::assertSame('Plastic', $otherItem->insertText, 'imported one → bare short');
    }

    public function testFiltersByPrefix(): void
    {
        $workspace = new PhpactorWorkspace();
        $workspace->open(new TextDocumentItem('/Models.xphp', 'xphp', 1, <<<'XPHP'
        <?php
        namespace App\Models;
        class Plastic {}
        class Metal {}
        class Wood {}
        XPHP));
        $useSource = "<?php\nnamespace App;\n\$x = new Box<Pla";
        $workspace->open(new TextDocumentItem('/Use.xphp', 'xphp', 1, $useSource));

        $list = $this->complete($workspace, '/Use.xphp', $useSource, strlen($useSource));

        $labels = array_map(static fn (CompletionItem $i): string => $i->label, $list->items);
        self::assertContains('Plastic', $labels);
        self::assertNotContains('Metal', $labels, 'Pla prefix must exclude Metal');
        self::assertNotContains('Wood', $labels);
    }

    public function testReturnsEmptyListOutsideTypeArgPosition(): void
    {
        $workspace = new PhpactorWorkspace();
        $source = "<?php\n\$x = 1;";
        $workspace->open(new TextDocumentItem('/Doc.xphp', 'xphp', 1, $source));

        $list = $this->complete($workspace, '/Doc.xphp', $source, strlen($source));
        self::assertSame([], $list->items);
    }

    public function testUnknownUriYieldsEmptyList(): void
    {
        $workspace = new PhpactorWorkspace();
        $handler = new XphpCompletionHandler(
            $workspace,
            new WorkspaceSymbols($workspace, $this->newCache()),
        );
        $params = new CompletionParams(
            new TextDocumentIdentifier('/never-opened.xphp'),
            new Position(0, 0),
        );
        $list = wait($handler->complete($params));
        self::assertInstanceOf(CompletionList::class, $list);
        self::assertSame([], $list->items);
    }

    public function testEmptyPrefixIncludesAllScalars(): void
    {
        // Locks the `$prefix !== ''` guard on line 114. With the guard
        // weakened/inverted (e.g. `=== ''`), the scalar loop would skip every
        // item even though prefix is empty.
        $workspace = new PhpactorWorkspace();
        $useSource = "<?php\nnamespace App;\n\$x = new Box<";
        $workspace->open(new TextDocumentItem('/Use.xphp', 'xphp', 1, $useSource));
        $list = $this->complete($workspace, '/Use.xphp', $useSource, strlen($useSource));

        $labels = array_map(static fn (CompletionItem $i): string => $i->label, $list->items);
        foreach (XphpSourceParser::SCALAR_TYPES as $scalar) {
            self::assertContains($scalar, $labels, "scalar '{$scalar}' must be present when prefix is empty");
        }
    }

    public function testScalarFilteringByPrefix(): void
    {
        // Locks the `continue` branch on line 115 (when a scalar doesn't match
        // the prefix). Without it, every scalar would surface regardless of
        // prefix.
        $workspace = new PhpactorWorkspace();
        $useSource = "<?php\nnamespace App;\n\$x = new Box<in";
        $workspace->open(new TextDocumentItem('/Use.xphp', 'xphp', 1, $useSource));
        $list = $this->complete($workspace, '/Use.xphp', $useSource, strlen($useSource));

        $labels = array_map(static fn (CompletionItem $i): string => $i->label, $list->items);
        // Matches: int + integer (prefix); string ("in" is at position 3 → FQN substring).
        self::assertContains('int', $labels);
        self::assertContains('integer', $labels);
        self::assertContains('string', $labels, 'matchesPrefix accepts FQN-substring matches, and "string" contains "in" at offset 3');
        // Does NOT match: any scalar with no "in" substring.
        self::assertNotContains('void', $labels);
        self::assertNotContains('iterable', $labels);
        self::assertNotContains('bool', $labels);
    }

    public function testClassFilteringExcludesNonMatchingFqn(): void
    {
        // Locks the `continue` on line 103. With it removed, every class
        // would be returned regardless of prefix match.
        $workspace = new PhpactorWorkspace();
        $workspace->open(new TextDocumentItem('/Models.xphp', 'xphp', 1, <<<'XPHP'
        <?php
        namespace App\Models;
        class Plastic {}
        class Metal {}
        class Stone {}
        XPHP));
        $useSource = "<?php\nnamespace App;\n\$x = new Box<Pla";
        $workspace->open(new TextDocumentItem('/Use.xphp', 'xphp', 1, $useSource));
        $list = $this->complete($workspace, '/Use.xphp', $useSource, strlen($useSource));

        $labels = array_map(static fn (CompletionItem $i): string => $i->label, $list->items);
        self::assertContains('Plastic', $labels);
        self::assertNotContains('Metal', $labels);
        self::assertNotContains('Stone', $labels);
    }

    public function testEmptyAfterLtrimPrefixReturnsAllCandidates(): void
    {
        // Locks the `ltrim($prefix, '\\\\')` + the second `=== ''` guard. A
        // prefix that's pure backslashes ("\\\\") ltrims to '' and the
        // matcher must return all candidates. With UnwrapLtrim, the
        // backslash-prefix would stay, no candidates would match.
        $workspace = new PhpactorWorkspace();
        $workspace->open(new TextDocumentItem('/Models.xphp', 'xphp', 1, <<<'XPHP'
        <?php
        namespace App\Models;
        class Plastic {}
        XPHP));
        $useSource = "<?php\nnamespace App;\n\$x = new Box<\\";
        $workspace->open(new TextDocumentItem('/Use.xphp', 'xphp', 1, $useSource));
        $list = $this->complete($workspace, '/Use.xphp', $useSource, strlen($useSource));

        $labels = array_map(static fn (CompletionItem $i): string => $i->label, $list->items);
        self::assertContains('Plastic', $labels, 'leading-backslash-only prefix must still match every candidate');
    }

    public function testFqnSubstringMatchPicksUpDeeperNamespace(): void
    {
        // Locks the OR branch on line 135: matchesPrefix returns true when
        // the needle appears ANYWHERE in the FQN, not only when it's a
        // prefix of the short name. With LogicalOr → LogicalAnd, the
        // namespace-substring case would be lost.
        $workspace = new PhpactorWorkspace();
        $workspace->open(new TextDocumentItem('/Models.xphp', 'xphp', 1, <<<'XPHP'
        <?php
        namespace App\Models\Deep;
        class Thing {}
        XPHP));
        // Prefix "Deep" is a substring of the FQN but NOT a prefix of the
        // short name "Thing" — must still surface as a candidate.
        $useSource = "<?php\nnamespace App;\n\$x = new Box<Deep";
        $workspace->open(new TextDocumentItem('/Use.xphp', 'xphp', 1, $useSource));
        $list = $this->complete($workspace, '/Use.xphp', $useSource, strlen($useSource));

        $labels = array_map(static fn (CompletionItem $i): string => $i->label, $list->items);
        self::assertContains('Thing', $labels);
    }

    public function testBoundedTypeArgWorksWhenContainerClassIsFilesystemOnly(): void
    {
        // Prod regression: bound-aware filtering used WorkspaceSymbols
        // (open-only) to resolve the container Name to its FQN, so when
        // the generic class's file was closed in the editor the lookup
        // returned null and filtering degraded to "no bound".  The
        // candidate enumeration had the same gap.  Both now consult
        // FqnIndex (open + filesystem).
        $root = sys_get_temp_dir() . '/xphp-comp-fs-' . bin2hex(random_bytes(6));
        mkdir($root, 0o755, true);
        try {
            // Container + candidates live only on disk -- nothing
            // is in the open workspace.
            file_put_contents($root . '/Box.xphp', <<<'XPHP'
            <?php
            namespace App;
            class Box<T: \Stringable> {}
            XPHP);
            file_put_contents($root . '/Tag.xphp', <<<'XPHP'
            <?php
            namespace App;
            class Tag implements \Stringable {
                public function __toString(): string { return ''; }
            }
            XPHP);
            file_put_contents($root . '/Number.xphp', <<<'XPHP'
            <?php
            namespace App;
            class Number {}
            XPHP);

            $workspace = new PhpactorWorkspace();
            $useSource = "<?php\nnamespace App;\n\$x = new Box<";
            $workspace->open(new TextDocumentItem('/Use.xphp', 'xphp', 1, $useSource));

            $list = $this->completeBoundAware($workspace, '/Use.xphp', $useSource, strlen($useSource), $root);
            $labels = array_map(static fn (CompletionItem $i): string => $i->label, $list->items);

            self::assertContains('Tag', $labels, 'closed-file Stringable implementor must still surface');
            self::assertNotContains('Number', $labels, 'closed-file non-implementor must be filtered out');
            self::assertNotContains('int', $labels, 'scalars must be dropped when slot is class-bounded');
        } finally {
            $this->rmrfPath($root);
        }
    }

    private function rmrfPath(string $dir): void
    {
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $p = $dir . '/' . $entry;
            if (is_dir($p)) {
                $this->rmrfPath($p);
            } else {
                unlink($p);
            }
        }
        rmdir($dir);
    }

    public function testBoundedTypeArgFiltersToSubtypesAndDropsScalars(): void
    {
        // Phase 3: `Box<T: Stringable>` constrains the type arg.  Completion
        // at `new Box<|` must surface only candidates that satisfy the
        // bound (subclasses or implementors of Stringable), drop scalars
        // (a scalar can't be Stringable), and keep classes that don't.
        $workspace = new PhpactorWorkspace();
        $workspace->open(new TextDocumentItem('/Box.xphp', 'xphp', 1, <<<'XPHP'
        <?php
        namespace App;
        class Box<T: \Stringable> {}
        XPHP));
        $workspace->open(new TextDocumentItem('/Models.xphp', 'xphp', 1, <<<'XPHP'
        <?php
        namespace App;
        class Tag implements \Stringable {
            public function __toString(): string { return ''; }
        }
        class Number {}
        XPHP));
        $useSource = "<?php\nnamespace App;\n\$x = new Box<";
        $workspace->open(new TextDocumentItem('/Use.xphp', 'xphp', 1, $useSource));

        $list = $this->completeBoundAware($workspace, '/Use.xphp', $useSource, strlen($useSource));
        $labels = array_map(static fn (CompletionItem $i): string => $i->label, $list->items);

        self::assertContains('Tag', $labels, 'Stringable implementor must surface');
        self::assertNotContains('Number', $labels, 'non-Stringable class must be filtered out');
        self::assertNotContains('int', $labels, 'scalars must be dropped when slot is class-bounded');
        self::assertNotContains('string', $labels, 'scalars must be dropped when slot is class-bounded');
    }

    public function testUnboundedSecondSlotStillSuggestsScalarsAndClasses(): void
    {
        // Slot indexing: `Pair<K: \Stringable, V>` -- slot 0 is bounded
        // and should filter; slot 1 is unbounded and should keep scalars
        // plus every class.
        $workspace = new PhpactorWorkspace();
        $workspace->open(new TextDocumentItem('/Pair.xphp', 'xphp', 1, <<<'XPHP'
        <?php
        namespace App;
        class Pair<K: \Stringable, V> {}
        XPHP));
        $workspace->open(new TextDocumentItem('/Models.xphp', 'xphp', 1, <<<'XPHP'
        <?php
        namespace App;
        class Tag implements \Stringable {
            public function __toString(): string { return ''; }
        }
        class Number {}
        XPHP));
        $useSource = "<?php\nnamespace App;\n\$x = new Pair<Tag, ";
        $workspace->open(new TextDocumentItem('/Use.xphp', 'xphp', 1, $useSource));

        $list = $this->completeBoundAware($workspace, '/Use.xphp', $useSource, strlen($useSource));
        $labels = array_map(static fn (CompletionItem $i): string => $i->label, $list->items);

        // Slot 1 (V) is unbounded -- everything goes.
        self::assertContains('Tag', $labels);
        self::assertContains('Number', $labels);
        self::assertContains('int', $labels);
    }

    public function testCompletionAdvertisesCorrectTriggerCharacters(): void
    {
        // Locks the ArrayItemRemoval on line 65: each of '<' and ',' is
        // a distinct trigger character; removing either would silently
        // break the editor's auto-trigger behaviour.
        $capabilities = new \Phpactor\LanguageServerProtocol\ServerCapabilities();
        (new XphpCompletionHandler(
            new PhpactorWorkspace(),
            new WorkspaceSymbols(new PhpactorWorkspace(), $this->newCache()),
        ))->registerCapabiltiies($capabilities);

        self::assertNotNull($capabilities->completionProvider);
        self::assertSame(
            ['<', ',', '>', ':'],
            $capabilities->completionProvider->triggerCharacters,
        );
    }

    public function testCompletionMethodsMapRegistersTheLspMethodName(): void
    {
        // Locks ArrayItemRemoval on line 55. Without the entry, dispatcher
        // would never route textDocument/completion to this handler.
        $methods = (new XphpCompletionHandler(
            new PhpactorWorkspace(),
            new WorkspaceSymbols(new PhpactorWorkspace(), $this->newCache()),
        ))->methods();

        self::assertArrayHasKey('textDocument/completion', $methods);
        self::assertSame('complete', $methods['textDocument/completion']);
    }

    private function complete(
        PhpactorWorkspace $workspace,
        string $uri,
        string $source,
        int $byteOffset,
    ): CompletionList {
        [$line, $character] = (new PositionMap($source))->offsetToPosition($byteOffset);
        $params = new CompletionParams(
            new TextDocumentIdentifier($uri),
            new Position($line, $character),
        );
        $cache = $this->newCache();
        $handler = new XphpCompletionHandler(
            $workspace,
            new WorkspaceSymbols($workspace, $cache),
        );
        return wait($handler->complete($params));
    }

    /**
     * Variant of `complete` that wires FqnIndex + Reflector so the bound-
     * aware filtering path can run.  Used by tests exercising
     * `Box<T: \Stringable>`-style suppression.
     */
    private function completeBoundAware(
        PhpactorWorkspace $workspace,
        string $uri,
        string $source,
        int $byteOffset,
        string $rootPath = '',
    ): CompletionList {
        [$line, $character] = (new PositionMap($source))->offsetToPosition($byteOffset);
        $params = new CompletionParams(
            new TextDocumentIdentifier($uri),
            new Position($line, $character),
        );
        $parser = new XphpSourceParser((new ParserFactory())->createForHostVersion());
        $cache = new ParsedDocumentCache(new Analyzer($parser));
        $fqnIndex = new FqnIndex($workspace, $cache, $parser, $rootPath);
        $reflector = (new ReflectorFactory(
            $workspace,
            $cache,
            $parser,
            rootPath: $rootPath,
            stubPath: ReflectorFactory::defaultStubPath(),
            cacheDir: ReflectorFactory::defaultCacheDir(),
            fqnIndex: $fqnIndex,
        ))->build();
        $handler = new XphpCompletionHandler(
            $workspace,
            new WorkspaceSymbols($workspace, $cache),
            null,
            $fqnIndex,
            $reflector,
        );
        return wait($handler->complete($params));
    }

    private function newCache(): ParsedDocumentCache
    {
        return new ParsedDocumentCache(
            new Analyzer(new XphpSourceParser((new ParserFactory())->createForHostVersion())),
        );
    }
}
