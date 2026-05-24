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
use XPHP\Lsp\Analyzer\ParsedDocumentCache;
use XPHP\Lsp\Handler\WorkspaceSymbols;
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

    public function testMethodsMapRegistersDefinitionEndpoint(): void
    {
        $methods = $this->newHandler(new PhpactorWorkspace())->methods();
        self::assertArrayHasKey('textDocument/definition', $methods);
        self::assertSame('definition', $methods['textDocument/definition']);
    }

    public function testAdvertisesDefinitionProviderCapability(): void
    {
        $capabilities = new \Phpactor\LanguageServerProtocol\ServerCapabilities();
        $this->newHandler(new PhpactorWorkspace())->registerCapabiltiies($capabilities);
        self::assertTrue($capabilities->definitionProvider);
    }

    public function testTargetRangeCoversTheFullIdentifierLength(): void
    {
        // Locks `getEndFilePos() + 1` arithmetic. nikic's endFilePos is
        // inclusive of the last byte; LSP ranges are half-open, so the +1 is
        // required for the range to span the whole identifier. We assert the
        // character count of the returned range matches the identifier length.
        $workspace = new PhpactorWorkspace();
        $boxSource = <<<'XPHP'
        <?php
        namespace App;
        class Container<T> { public T $item; }
        XPHP;
        $useSource = "<?php\nnamespace App;\n\$x = new Container<Plastic>();";
        $workspace->open(new TextDocumentItem('/Container.xphp', 'xphp', 1, $boxSource));
        $workspace->open(new TextDocumentItem('/Use.xphp', 'xphp', 1, $useSource));

        $location = $this->definitionAt($this->newHandler($workspace), '/Use.xphp', $useSource, 'Container<Plastic>');

        self::assertNotNull($location);
        $charCount = $location->range->end->character - $location->range->start->character;
        // "Container" is 9 characters. With `+0` the range would span 8 chars.
        self::assertSame(9, $charCount, 'range must cover the whole "Container" identifier');
    }

    public function testWorkspaceScanContinuesPastFilesThatDoNotDefineTheTemplate(): void
    {
        // Locks the `continue` on line 104 of findDefinitionAcrossWorkspace.
        // With `break`, scanning would stop at the first file that lacks the
        // template instead of trying the rest. We insert an unrelated file
        // BEFORE the one with the definition (insertion order matters; the
        // workspace iterates in insertion order).
        $workspace = new PhpactorWorkspace();
        $workspace->open(new TextDocumentItem('/Unrelated.xphp', 'xphp', 1, <<<'XPHP'
        <?php
        namespace App;
        class Helper {}
        XPHP));
        $workspace->open(new TextDocumentItem('/Container.xphp', 'xphp', 1, <<<'XPHP'
        <?php
        namespace App;
        class Container<T> { public T $item; }
        XPHP));
        $useSource = "<?php\nnamespace App;\n\$x = new Container<Plastic>();";
        $workspace->open(new TextDocumentItem('/Use.xphp', 'xphp', 1, $useSource));

        $location = $this->definitionAt($this->newHandler($workspace), '/Use.xphp', $useSource, 'Container<Plastic>');

        self::assertNotNull($location);
        self::assertSame('/Container.xphp', $location->uri);
    }

    public function testJumpsFromTypeArgInsideGenericClauseToClassDeclaration(): void
    {
        // The xphp-specific case: Ctrl+click on `User` inside the `<>` of
        // `identity<User>(...)` should land on `class User`.  This relies on
        // the second code path in `definition()` -- the inner `User` doesn't
        // survive as a Name node in the AST (XphpSourceParser strips it into
        // a marker entry on the outer FuncCall), so the ATTR_TEMPLATE_FQN
        // lookup misses; the fall-through uses TypeArgPositionDetector +
        // WorkspaceSymbols to resolve.
        $workspace = new PhpactorWorkspace();
        $workspace->open(new TextDocumentItem('/User.xphp', 'xphp', 1, <<<'XPHP'
        <?php
        namespace App;
        class User { public function __construct(public string $name) {} }
        XPHP));
        $useSource = "<?php\nnamespace App;\n\$asUser = identity<User>(new User('bob'));";
        $workspace->open(new TextDocumentItem('/Use.xphp', 'xphp', 1, $useSource));

        // Cursor points at the `User` INSIDE the angle brackets, not the
        // `User` in the `new User(...)` ctor.
        $genericClauseStart = strpos($useSource, 'identity<') + strlen('identity<');
        $location = $this->definitionAtOffset($this->newHandler($workspace), '/Use.xphp', $useSource, $genericClauseStart + 1);

        self::assertNotNull($location);
        self::assertSame('/User.xphp', $location->uri);
    }

    public function testTypeArgFallthroughReturnsNullWhenClassNotInWorkspace(): void
    {
        // No `class Unknown` declared anywhere in the workspace -> null.
        // Distinguishes "we tried Path 2 and didn't find anything" from a
        // wiring bug.
        $workspace = new PhpactorWorkspace();
        $useSource = "<?php\n\$x = identity<Unknown>(null);";
        $workspace->open(new TextDocumentItem('/Use.xphp', 'xphp', 1, $useSource));

        $offset = strpos($useSource, 'Unknown') + 1;
        $location = $this->definitionAtOffset($this->newHandler($workspace), '/Use.xphp', $useSource, $offset);

        self::assertNull($location);
    }

    private function definitionAtOffset(
        XphpDefinitionHandler $handler,
        string $uri,
        string $source,
        int $byte,
    ): ?Location {
        [$line, $character] = (new PositionMap($source))->offsetToPosition($byte);
        $params = new DefinitionParams(
            new TextDocumentIdentifier($uri),
            new Position($line, $character),
        );
        return wait($handler->definition($params));
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
        $cache = new ParsedDocumentCache(
            new Analyzer(new XphpSourceParser((new ParserFactory())->createForHostVersion())),
        );
        return new XphpDefinitionHandler(
            $workspace,
            $cache,
            new WorkspaceSymbols($workspace, $cache),
        );
    }
}
