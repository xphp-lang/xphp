<?php

declare(strict_types=1);

namespace XPHP\Lsp\Test\Handler;

use PhpParser\ParserFactory;
use Phpactor\LanguageServer\Core\Workspace\Workspace as PhpactorWorkspace;
use Phpactor\LanguageServerProtocol\Hover;
use Phpactor\LanguageServerProtocol\HoverParams;
use Phpactor\LanguageServerProtocol\MarkupContent;
use Phpactor\LanguageServerProtocol\Position;
use Phpactor\LanguageServerProtocol\TextDocumentIdentifier;
use Phpactor\LanguageServerProtocol\TextDocumentItem;
use PHPUnit\Framework\TestCase;
use XPHP\Lsp\Analyzer\Analyzer;
use XPHP\Lsp\Handler\XphpHoverHandler;
use XPHP\Lsp\PositionMap;
use XPHP\Transpiler\Monomorphize\XphpSourceParser;
use function Amp\Promise\wait;

final class XphpHoverHandlerTest extends TestCase
{
    public function testHoverOverGenericInstantiationShowsSpecializedFqn(): void
    {
        [$handler, $workspace, $uri] = $this->prepare(<<<'XPHP'
        <?php
        namespace App;
        $x = new Box<Plastic>();
        XPHP);
        // Cursor on the `B` of `Box`.
        $hover = $this->hoverAt($handler, $uri, $workspace->get($uri)->text, 'Box<Plastic>');

        self::assertInstanceOf(Hover::class, $hover);
        self::assertInstanceOf(MarkupContent::class, $hover->contents);
        self::assertStringContainsString('Specializes to:', $hover->contents->value);
        self::assertStringContainsString('XPHP\\Generated\\App\\Box\\T_', $hover->contents->value);
    }

    public function testHoverOverTypeParamShowsBoundAndOwner(): void
    {
        [$handler, $workspace, $uri] = $this->prepare(<<<'XPHP'
        <?php
        namespace App;
        class Box<T: \Stringable>
        {
            public T $item;
        }
        XPHP);
        // Cursor on the `T` inside `public T $item;`.
        $hover = $this->hoverAt($handler, $uri, $workspace->get($uri)->text, 'public T $item', offsetInSearch: strlen('public '));

        self::assertInstanceOf(Hover::class, $hover);
        $text = $hover->contents->value;
        self::assertStringContainsString('Type parameter', $text);
        self::assertStringContainsString('`T`', $text);
        self::assertStringContainsString('App\\Box', $text);
        self::assertStringContainsString('Stringable', $text);
    }

    public function testHoverOverPlainNameReturnsNull(): void
    {
        [$handler, $workspace, $uri] = $this->prepare(<<<'XPHP'
        <?php
        namespace App;
        class Tag { public string $name; }
        $t = new Tag();
        XPHP);
        // Cursor over the non-generic `Tag` class name in the new expression.
        $hover = $this->hoverAt($handler, $uri, $workspace->get($uri)->text, 'new Tag()', offsetInSearch: strlen('new '));

        // `Tag` carries no ATTR_GENERIC_ARGS and no enclosing template scope.
        // The handler returns null; LSP clients render nothing.
        self::assertNull($hover);
    }

    public function testUnknownUriYieldsNull(): void
    {
        $workspace = new PhpactorWorkspace();
        $handler = new XphpHoverHandler($workspace, $this->newAnalyzer());
        $params = new HoverParams(
            new TextDocumentIdentifier('/never-opened.xphp'),
            new Position(0, 0),
        );
        self::assertNull(wait($handler->hover($params)));
    }

    /**
     * @return array{0: XphpHoverHandler, 1: PhpactorWorkspace, 2: string}
     */
    private function prepare(string $source): array
    {
        $workspace = new PhpactorWorkspace();
        $uri = '/doc.xphp';
        $workspace->open(new TextDocumentItem($uri, 'xphp', 1, $source));
        $handler = new XphpHoverHandler($workspace, $this->newAnalyzer());
        return [$handler, $workspace, $uri];
    }

    private function hoverAt(
        XphpHoverHandler $handler,
        string $uri,
        string $source,
        string $search,
        int $offsetInSearch = 0,
    ): ?Hover {
        $byte = strpos($source, $search);
        self::assertNotFalse($byte, "fixture search string '{$search}' must exist in source");
        $byte += $offsetInSearch;
        [$line, $character] = (new PositionMap($source))->offsetToPosition($byte);
        $params = new HoverParams(
            new TextDocumentIdentifier($uri),
            new Position($line, $character),
        );
        return wait($handler->hover($params));
    }

    private function newAnalyzer(): Analyzer
    {
        return new Analyzer(new XphpSourceParser((new ParserFactory())->createForHostVersion()));
    }
}
