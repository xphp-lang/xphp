<?php

declare(strict_types=1);

namespace XPHP\Lsp\Test\Handler;

use PhpParser\ParserFactory;
use Phpactor\LanguageServer\Core\Workspace\Workspace as PhpactorWorkspace;
use Phpactor\LanguageServerProtocol\FoldingRange;
use Phpactor\LanguageServerProtocol\FoldingRangeKind;
use Phpactor\LanguageServerProtocol\FoldingRangeParams;
use Phpactor\LanguageServerProtocol\TextDocumentIdentifier;
use Phpactor\LanguageServerProtocol\TextDocumentItem;
use PHPUnit\Framework\TestCase;
use XPHP\Lsp\Analyzer\Analyzer;
use XPHP\Lsp\Analyzer\ParsedDocumentCache;
use XPHP\Lsp\Handler\XphpFoldingRangeHandler;
use XPHP\Transpiler\Monomorphize\XphpSourceParser;

use function Amp\Promise\wait;

final class XphpFoldingRangeHandlerTest extends TestCase
{
    public function testFoldsMultiLineClassBodyAndMethodBodies(): void
    {
        $source = <<<'XPHP'
        <?php
        namespace App;
        class Box<T>
        {
            public function __construct(public T $item)
            {
            }

            public function get(): T
            {
                return $this->item;
            }
        }
        XPHP;

        $ranges = $this->foldingRangesFor($source);

        // Class spans lines 2..12 (0-indexed). Two methods each span
        // their own line range.  Order: class first, methods after
        // (collect() recurses into members after emitting the
        // ClassLike range).
        self::assertCount(3, $ranges);
        // Class fold
        self::assertSame(2, $ranges[0]->startLine);
        self::assertSame(12, $ranges[0]->endLine);
        self::assertSame(FoldingRangeKind::REGION, $ranges[0]->kind);
        // First method fold
        self::assertSame(4, $ranges[1]->startLine);
        self::assertSame(6, $ranges[1]->endLine);
        // Second method fold
        self::assertSame(8, $ranges[2]->startLine);
        self::assertSame(11, $ranges[2]->endLine);
    }

    public function testFoldsTopLevelFunctionBody(): void
    {
        $source = <<<'XPHP'
        <?php
        function greet(string $name): string
        {
            return $name;
        }
        XPHP;

        $ranges = $this->foldingRangesFor($source);

        self::assertCount(1, $ranges);
        self::assertSame(1, $ranges[0]->startLine);
        self::assertSame(4, $ranges[0]->endLine);
    }

    public function testSkipsSingleLineDeclarations(): void
    {
        // `class Box<T> {}` is one line -- LSP requires endLine > startLine
        // for a fold to be valid, so we emit nothing.
        $source = "<?php\nnamespace App;\nclass Box<T> {}\n";

        self::assertSame([], $this->foldingRangesFor($source));
    }

    public function testReturnsEmptyArrayForUnparseableSource(): void
    {
        // Garbage source produces null AST.  Return an empty array
        // (not null) so the client doesn't think folding is unsupported.
        $source = "<?php\n{{{ not valid php at all";

        self::assertSame([], $this->foldingRangesFor($source));
    }

    public function testReturnsNullForUnknownDocument(): void
    {
        $workspace = new PhpactorWorkspace();
        $handler = $this->handler($workspace);
        $params = new FoldingRangeParams(new TextDocumentIdentifier('/never-opened.xphp'));

        self::assertNull(wait($handler->foldingRange($params)));
    }

    public function testHandlesInterfaceTraitAndEnum(): void
    {
        $source = <<<'XPHP'
        <?php
        namespace App;
        interface Greeter
        {
            public function greet(): string;
        }
        trait Stamped
        {
            public function stamp(): int
            {
                return time();
            }
        }
        enum Color
        {
            case Red;
            case Blue;
        }
        XPHP;

        $ranges = $this->foldingRangesFor($source);

        // interface (lines 2-5), trait body (6-12) + its method (8-11),
        // enum body (13-17).
        self::assertCount(4, $ranges);

        $kinds = array_map(static fn (FoldingRange $r): string => $r->kind ?? '', $ranges);
        foreach ($kinds as $kind) {
            self::assertSame(FoldingRangeKind::REGION, $kind);
        }

        // Spot-check that interface and enum are covered (the trait's
        // single method gets its own fold which we don't pin to a
        // specific position to avoid coupling to nikic's line indexing
        // for non-class blocks).
        $startLines = array_map(static fn (FoldingRange $r): int => $r->startLine, $ranges);
        self::assertContains(2, $startLines, 'interface fold must start at line 2');
        self::assertContains(13, $startLines, 'enum fold must start at line 13');
    }

    public function testAdvertisesFoldingRangeProviderCapability(): void
    {
        $caps = new \Phpactor\LanguageServerProtocol\ServerCapabilities();
        $this->handler(new PhpactorWorkspace())->registerCapabiltiies($caps);

        self::assertTrue($caps->foldingRangeProvider);
    }

    public function testMethodsMapAdvertisesFoldingRangeEndpoint(): void
    {
        self::assertArrayHasKey(
            'textDocument/foldingRange',
            $this->handler(new PhpactorWorkspace())->methods(),
        );
    }

    /**
     * @return list<FoldingRange>
     */
    private function foldingRangesFor(string $source): array
    {
        $workspace = new PhpactorWorkspace();
        $uri = '/test.xphp';
        $workspace->open(new TextDocumentItem($uri, 'xphp', 1, $source));

        $handler = $this->handler($workspace);
        $params = new FoldingRangeParams(new TextDocumentIdentifier($uri));
        $result = wait($handler->foldingRange($params));
        self::assertIsArray($result);
        return $result;
    }

    private function handler(PhpactorWorkspace $workspace): XphpFoldingRangeHandler
    {
        $parser = new XphpSourceParser((new ParserFactory())->createForHostVersion());
        $cache = new ParsedDocumentCache(new Analyzer($parser));
        return new XphpFoldingRangeHandler($workspace, $cache);
    }
}
