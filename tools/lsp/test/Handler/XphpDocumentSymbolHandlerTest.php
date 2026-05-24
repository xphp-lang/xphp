<?php

declare(strict_types=1);

namespace XPHP\Lsp\Test\Handler;

use PhpParser\ParserFactory;
use Phpactor\LanguageServer\Core\Workspace\Workspace as PhpactorWorkspace;
use Phpactor\LanguageServerProtocol\DocumentSymbol;
use Phpactor\LanguageServerProtocol\DocumentSymbolParams;
use Phpactor\LanguageServerProtocol\SymbolKind;
use Phpactor\LanguageServerProtocol\TextDocumentIdentifier;
use Phpactor\LanguageServerProtocol\TextDocumentItem;
use PHPUnit\Framework\TestCase;
use XPHP\Lsp\Analyzer\Analyzer;
use XPHP\Lsp\Analyzer\ParsedDocumentCache;
use XPHP\Lsp\Handler\XphpDocumentSymbolHandler;
use XPHP\Transpiler\Monomorphize\XphpSourceParser;

use function Amp\Promise\wait;

final class XphpDocumentSymbolHandlerTest extends TestCase
{
    public function testEmitsClassWithMethodsAndPropertiesAndConstants(): void
    {
        $symbols = $this->collect(<<<'XPHP'
        <?php
        namespace App;
        class User {
            const ROLE = 'guest';
            public string $name = '';
            private int $age = 0;
            public function __construct(string $name) { $this->name = $name; }
            public function shout(): string { return ''; }
        }
        XPHP);

        self::assertCount(1, $symbols);
        $class = $symbols[0];
        self::assertSame('User', $class->name);
        self::assertSame(SymbolKind::CLASS_, $class->kind);

        $childIndex = [];
        foreach ($class->children ?? [] as $child) {
            $childIndex[$child->name] = $child;
        }
        self::assertArrayHasKey('ROLE', $childIndex);
        self::assertSame(SymbolKind::CONSTANT, $childIndex['ROLE']->kind);
        self::assertArrayHasKey('$name', $childIndex);
        self::assertSame(SymbolKind::PROPERTY, $childIndex['$name']->kind);
        self::assertArrayHasKey('$age', $childIndex);
        self::assertArrayHasKey('__construct', $childIndex);
        self::assertSame(SymbolKind::CONSTRUCTOR, $childIndex['__construct']->kind);
        self::assertArrayHasKey('shout', $childIndex);
        self::assertSame(SymbolKind::METHOD, $childIndex['shout']->kind);
    }

    public function testInterfaceUsesInterfaceKind(): void
    {
        $symbols = $this->collect(<<<'XPHP'
        <?php
        namespace App;
        interface Greeter {
            public function greet(): string;
        }
        XPHP);

        self::assertCount(1, $symbols);
        self::assertSame('Greeter', $symbols[0]->name);
        self::assertSame(SymbolKind::INTERFACE, $symbols[0]->kind);
        self::assertCount(1, $symbols[0]->children ?? []);
        self::assertSame('greet', $symbols[0]->children[0]->name);
    }

    public function testEnumUsesEnumKindAndExposesCases(): void
    {
        $symbols = $this->collect(<<<'XPHP'
        <?php
        namespace App;
        enum Suit: string {
            case Hearts = 'H';
            case Spades = 'S';
        }
        XPHP);

        self::assertCount(1, $symbols);
        $enum = $symbols[0];
        self::assertSame('Suit', $enum->name);
        self::assertSame(SymbolKind::ENUM, $enum->kind);

        $names = array_map(fn (DocumentSymbol $s): string => $s->name, $enum->children ?? []);
        self::assertContains('Hearts', $names);
        self::assertContains('Spades', $names);
        foreach ($enum->children ?? [] as $case) {
            self::assertSame(SymbolKind::ENUM_MEMBER, $case->kind);
        }
    }

    public function testTopLevelFunctionEmittedAtRoot(): void
    {
        $symbols = $this->collect(<<<'XPHP'
        <?php
        namespace App;
        function identity(mixed $x): mixed { return $x; }
        class Thing {}
        XPHP);

        $names = array_map(fn (DocumentSymbol $s): string => $s->name, $symbols);
        self::assertContains('identity', $names);
        self::assertContains('Thing', $names);

        foreach ($symbols as $s) {
            if ($s->name === 'identity') {
                self::assertSame(SymbolKind::FUNCTION, $s->kind);
            }
        }
    }

    public function testMultipleNamesInSinglePropertyOrConstStatementYieldSeparateSymbols(): void
    {
        // `public string $a, $b;` produces a single Property AST node with
        // two PropertyItem entries.  The outline must surface BOTH.
        $symbols = $this->collect(<<<'XPHP'
        <?php
        namespace App;
        class Pair {
            const FIRST = 1, SECOND = 2;
            public string $left = '', $right = '';
        }
        XPHP);

        self::assertCount(1, $symbols);
        $names = array_map(fn (DocumentSymbol $s): string => $s->name, $symbols[0]->children ?? []);
        self::assertContains('FIRST', $names);
        self::assertContains('SECOND', $names);
        self::assertContains('$left', $names);
        self::assertContains('$right', $names);
    }

    public function testAnonymousClassesAreSkipped(): void
    {
        $symbols = $this->collect(<<<'XPHP'
        <?php
        namespace App;
        $obj = new class {
            public function ping(): void {}
        };
        class Named {}
        XPHP);

        $names = array_map(fn (DocumentSymbol $s): string => $s->name, $symbols);
        // Only the named class survives -- the anonymous one has no entry
        // point in the outline.
        self::assertSame(['Named'], $names);
    }

    public function testEmptyDocumentReturnsEmptyList(): void
    {
        self::assertSame([], $this->collect("<?php\n"));
    }

    public function testUnknownUriYieldsNull(): void
    {
        $workspace = new PhpactorWorkspace();
        $handler = new XphpDocumentSymbolHandler($workspace, $this->newCache());
        $params = new DocumentSymbolParams(new TextDocumentIdentifier('/never-opened.xphp'));
        self::assertNull(wait($handler->documentSymbol($params)));
    }

    public function testMethodsMapRegistersEndpoint(): void
    {
        // Without this entry, the dispatcher never routes
        // textDocument/documentSymbol to the handler.  ArrayItemRemoval guard.
        $methods = (new XphpDocumentSymbolHandler(new PhpactorWorkspace(), $this->newCache()))
            ->methods();
        self::assertArrayHasKey('textDocument/documentSymbol', $methods);
        self::assertSame('documentSymbol', $methods['textDocument/documentSymbol']);
    }

    public function testTBracketPropertyEmitsOriginalSourcePositions(): void
    {
        // Regression for the prod trace from xphp-20260525-003910-606.log:
        // properties declared with `T[]` sugar reported selectionRanges
        // shifted by +2 columns because the parser rewrites `T[]` (3 bytes)
        // to `array` (5 bytes).  The handler must back-translate AST
        // offsets through the ByteOffsetMap before constructing Ranges.
        $source = "<?php\nnamespace App;\nclass Repo<T>\n{\n    private T[] \$items = [];\n}\n";
        $symbols = $this->collect($source);

        self::assertCount(1, $symbols);
        $children = $symbols[0]->children ?? [];
        self::assertCount(1, $children);
        $prop = $children[0];
        self::assertSame('$items', $prop->name);

        // The original source's line 4 (0-indexed) is:
        //   "    private T[] $items = [];"
        // `$items` starts at column 16, `items` (the name) starts at column 17.
        // Without the offset map the handler would emit column 18 (the byte
        // position in the stripped source where `array $items` ends at 18).
        self::assertSame(4, $prop->selectionRange->start->line);
        self::assertSame(16, $prop->selectionRange->start->character);
    }

    public function testSelectionRangeTargetsNameNotEntireClass(): void
    {
        // The outline panel scrolls/highlights based on selectionRange; if we
        // pointed it at the whole class body the editor would highlight the
        // entire block instead of the identifier.
        $symbols = $this->collect(<<<'XPHP'
        <?php
        namespace App;
        class Tag {
            public string $name = '';
        }
        XPHP);

        $class = $symbols[0];
        self::assertSame('Tag', $class->name);
        // selectionRange.start must equal the byte offset of `Tag` in the source.
        // The class statement starts further left at `class `, so range.start
        // is strictly less.
        $selStart = $class->selectionRange->start;
        $rangeStart = $class->range->start;
        $isLater = $selStart->line > $rangeStart->line
            || ($selStart->line === $rangeStart->line && $selStart->character > $rangeStart->character);
        self::assertTrue($isLater, 'selectionRange must start AFTER the `class ` keyword');
    }

    /**
     * @return list<DocumentSymbol>
     */
    private function collect(string $source): array
    {
        $workspace = new PhpactorWorkspace();
        $uri = '/doc.xphp';
        $workspace->open(new TextDocumentItem($uri, 'xphp', 1, $source));
        $handler = new XphpDocumentSymbolHandler($workspace, $this->newCache());
        $params = new DocumentSymbolParams(new TextDocumentIdentifier($uri));
        $result = wait($handler->documentSymbol($params));
        self::assertIsArray($result);
        return $result;
    }

    private function newCache(): ParsedDocumentCache
    {
        return new ParsedDocumentCache(
            new Analyzer(new XphpSourceParser((new ParserFactory())->createForHostVersion())),
        );
    }
}
