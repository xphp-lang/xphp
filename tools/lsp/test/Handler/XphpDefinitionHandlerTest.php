<?php

declare(strict_types=1);

namespace XPHP\Lsp\Test\Handler;

use PhpParser\ParserFactory;
use Phpactor\LanguageServer\Core\Workspace\Workspace as PhpactorWorkspace;
use Phpactor\LanguageServerProtocol\DefinitionParams;
use Phpactor\LanguageServerProtocol\Location;
use Phpactor\LanguageServerProtocol\Position;
use Phpactor\LanguageServerProtocol\TextDocumentIdentifier;
use Phpactor\LanguageServerProtocol\TextDocumentItem;
use PHPUnit\Framework\TestCase;
use XPHP\Lsp\Analyzer\Analyzer;
use XPHP\Lsp\Handler\XphpDefinitionHandler;
use XPHP\Lsp\PositionMap;
use XPHP\Transpiler\Monomorphize\XphpSourceParser;
use function Amp\Promise\wait;

final class XphpDefinitionHandlerTest extends TestCase
{
    public function testJumpsFromInstantiationToTemplateInAnotherDocument(): void
    {
        $workspace = new PhpactorWorkspace();
        $boxSource = <<<'XPHP'
        <?php
        namespace App;
        class Box<T>
        {
            public T $item;
        }
        XPHP;
        $useSource = <<<'XPHP'
        <?php
        namespace App;
        $x = new Box<Plastic>();
        XPHP;
        $workspace->open(new TextDocumentItem('/Box.xphp', 'xphp', 1, $boxSource));
        $workspace->open(new TextDocumentItem('/Use.xphp', 'xphp', 1, $useSource));

        $handler = $this->newHandler($workspace);
        $location = $this->definitionAt($handler, '/Use.xphp', $useSource, 'Box<Plastic>');

        self::assertInstanceOf(Location::class, $location);
        self::assertSame('/Box.xphp', $location->uri);
        // The target range points at the `Box` identifier inside `class Box<T>`,
        // not the entire class body.
        [$expectedLine, $expectedChar] = (new PositionMap($boxSource))->offsetToPosition(strpos($boxSource, 'class Box') + strlen('class '));
        self::assertSame($expectedLine, $location->range->start->line);
        self::assertSame($expectedChar, $location->range->start->character);
    }

    public function testNonGenericNameReturnsNull(): void
    {
        $workspace = new PhpactorWorkspace();
        $source = <<<'XPHP'
        <?php
        namespace App;
        class Tag { public string $name; }
        $t = new Tag();
        XPHP;
        $workspace->open(new TextDocumentItem('/doc.xphp', 'xphp', 1, $source));

        $handler = $this->newHandler($workspace);
        // Cursor on `Tag` in `new Tag()` — but `Tag` here has no template
        // FQN attribute since it's not a generic instantiation. Returns null.
        $location = $this->definitionAt($handler, '/doc.xphp', $source, 'new Tag', offsetInSearch: strlen('new '));
        self::assertNull($location);
    }

    public function testTemplateNotInOpenWorkspaceReturnsNull(): void
    {
        // Only the Use.xphp is open; the Box template lives elsewhere (on disk).
        // Without a workspace index of unopened files (a follow-up), we return null.
        $workspace = new PhpactorWorkspace();
        $useSource = <<<'XPHP'
        <?php
        namespace App;
        $x = new Box<Plastic>();
        XPHP;
        $workspace->open(new TextDocumentItem('/Use.xphp', 'xphp', 1, $useSource));

        $handler = $this->newHandler($workspace);
        $location = $this->definitionAt($handler, '/Use.xphp', $useSource, 'Box<Plastic>');

        self::assertNull($location);
    }

    public function testUnknownUriReturnsNull(): void
    {
        $handler = $this->newHandler(new PhpactorWorkspace());
        $params = new DefinitionParams(
            new TextDocumentIdentifier('/never-opened.xphp'),
            new Position(0, 0),
        );
        self::assertNull(wait($handler->definition($params)));
    }

    private function definitionAt(
        XphpDefinitionHandler $handler,
        string $uri,
        string $source,
        string $search,
        int $offsetInSearch = 0,
    ): ?Location {
        $byte = strpos($source, $search);
        self::assertNotFalse($byte, "fixture search string '{$search}' must exist in source");
        $byte += $offsetInSearch;
        [$line, $character] = (new PositionMap($source))->offsetToPosition($byte);
        $params = new DefinitionParams(
            new TextDocumentIdentifier($uri),
            new Position($line, $character),
        );
        return wait($handler->definition($params));
    }

    private function newHandler(PhpactorWorkspace $workspace): XphpDefinitionHandler
    {
        return new XphpDefinitionHandler(
            $workspace,
            new Analyzer(new XphpSourceParser((new ParserFactory())->createForHostVersion())),
        );
    }
}
