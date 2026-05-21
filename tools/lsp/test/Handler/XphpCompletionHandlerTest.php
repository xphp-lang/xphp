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
use XPHP\Lsp\Handler\WorkspaceSymbols;
use XPHP\Lsp\Handler\XphpCompletionHandler;
use XPHP\Lsp\PositionMap;
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
        // Class items insertText carries the FQN — easier for the user to land
        // a correct instantiation when no `use` is in scope.
        foreach ($list->items as $item) {
            if ($item->label === 'Plastic') {
                self::assertSame('App\\Models\\Plastic', $item->insertText);
                self::assertSame(CompletionItemKind::CLASS_, $item->kind);
            }
        }
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
            new WorkspaceSymbols($workspace, $this->newAnalyzer()),
        );
        $params = new CompletionParams(
            new TextDocumentIdentifier('/never-opened.xphp'),
            new Position(0, 0),
        );
        $list = wait($handler->complete($params));
        self::assertInstanceOf(CompletionList::class, $list);
        self::assertSame([], $list->items);
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
        $analyzer = $this->newAnalyzer();
        $handler = new XphpCompletionHandler(
            $workspace,
            new WorkspaceSymbols($workspace, $analyzer),
        );
        return wait($handler->complete($params));
    }

    private function newAnalyzer(): Analyzer
    {
        return new Analyzer(new XphpSourceParser((new ParserFactory())->createForHostVersion()));
    }
}
