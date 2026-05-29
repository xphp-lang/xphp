<?php

declare(strict_types=1);

namespace XPHP\Lsp\Test\Handler;

use PhpParser\ParserFactory;
use Phpactor\LanguageServer\Core\Workspace\Workspace as PhpactorWorkspace;
use Phpactor\LanguageServerProtocol\CodeAction;
use Phpactor\LanguageServerProtocol\CodeActionContext;
use Phpactor\LanguageServerProtocol\CodeActionOptions;
use Phpactor\LanguageServerProtocol\CodeActionParams;
use Phpactor\LanguageServerProtocol\Position;
use Phpactor\LanguageServerProtocol\Range;
use Phpactor\LanguageServerProtocol\ServerCapabilities;
use Phpactor\LanguageServerProtocol\TextDocumentIdentifier;
use Phpactor\LanguageServerProtocol\TextDocumentItem;
use PHPUnit\Framework\TestCase;
use XPHP\Lsp\Analyzer\Analyzer;
use XPHP\Lsp\Analyzer\ParsedDocumentCache;
use XPHP\Lsp\Handler\XphpCodeActionHandler;
use XPHP\Lsp\Handler\XphpCodeActionResolveHandler;
use XPHP\Lsp\Reflection\FqnIndex;
use XPHP\Lsp\Resolver\DiagnosticCodeActionProvider;
use XPHP\Lsp\Resolver\ImportCodeActionProvider;
use XPHP\Lsp\Resolver\OptimizeImportsCodeActionProvider;
use XPHP\Transpiler\Monomorphize\XphpSourceParser;

use function Amp\Promise\wait;

final class XphpCodeActionHandlerTest extends TestCase
{
    public function testReturnsEmptyArrayForWorkspaceDocument(): void
    {
        $workspace = new PhpactorWorkspace();
        $workspace->open(new TextDocumentItem('/Use.xphp', 'xphp', 1, "<?php\n\$x = 1;\n"));

        $handler = $this->newHandler($workspace);
        $params = new CodeActionParams(
            new TextDocumentIdentifier('/Use.xphp'),
            new Range(new Position(0, 0), new Position(0, 0)),
            new CodeActionContext(diagnostics: []),
        );

        self::assertSame([], wait($handler->codeAction($params)));
    }

    public function testReturnsEmptyArrayForUnknownDocument(): void
    {
        $handler = $this->newHandler(new PhpactorWorkspace());
        $params = new CodeActionParams(
            new TextDocumentIdentifier('/never-opened.xphp'),
            new Range(new Position(0, 0), new Position(0, 0)),
            new CodeActionContext(diagnostics: []),
        );

        self::assertSame([], wait($handler->codeAction($params)));
    }

    public function testReturnsEmptyArrayWhenCancelTokenAlreadyRequested(): void
    {
        $workspace = new PhpactorWorkspace();
        $workspace->open(new TextDocumentItem('/Use.xphp', 'xphp', 1, "<?php\n\$x = 1;\n"));

        $handler = $this->newHandler($workspace);
        $params = new CodeActionParams(
            new TextDocumentIdentifier('/Use.xphp'),
            new Range(new Position(0, 0), new Position(0, 0)),
            new CodeActionContext(diagnostics: []),
        );
        $cancel = new \Amp\CancellationTokenSource();
        $cancel->cancel();

        self::assertSame([], wait($handler->codeAction($params, $cancel->getToken())));
    }

    public function testAdvertisesCodeActionProviderWithResolveProvider(): void
    {
        $handler = $this->newHandler(new PhpactorWorkspace());
        $caps = new ServerCapabilities();
        $handler->registerCapabiltiies($caps);

        self::assertInstanceOf(CodeActionOptions::class, $caps->codeActionProvider);
        self::assertTrue($caps->codeActionProvider->resolveProvider);
    }

    public function testMethodsMapAdvertisesCodeActionEndpoint(): void
    {
        self::assertArrayHasKey(
            'textDocument/codeAction',
            $this->newHandler(new PhpactorWorkspace())->methods(),
        );
    }

    public function testOffersImportActionForUnresolvedClassReference(): void
    {
        $workspace = new PhpactorWorkspace();
        // Decl file the index can resolve.
        $workspace->open(new TextDocumentItem(
            '/Models/User.xphp',
            'xphp',
            1,
            "<?php\nnamespace App\\Models;\nclass User {}\n",
        ));
        // Consumer file with a bare `User` reference and no `use`.
        $consumer = "<?php\nnamespace App\\Demos;\nfunction make(): void { new User(); }\n";
        $workspace->open(new TextDocumentItem('/Demos/Make.xphp', 'xphp', 1, $consumer));

        $handler = $this->newHandler($workspace);
        // Cursor on `User` token (line 2, character offset within `new User()`).
        $needle = strpos($consumer, 'new User()');
        self::assertNotFalse($needle);
        $userOffset = $needle + 4;
        // Convert byte offset back to LSP coords via PositionMap shim.
        $positionMap = new \XPHP\Lsp\PositionMap($consumer);
        [$line, $char] = $positionMap->offsetToPosition($userOffset);
        $params = new CodeActionParams(
            new TextDocumentIdentifier('/Demos/Make.xphp'),
            new Range(new Position($line, $char), new Position($line, $char)),
            new CodeActionContext(diagnostics: []),
        );
        $actions = wait($handler->codeAction($params));

        self::assertNotEmpty($actions);
        $titles = array_map(static fn (CodeAction $a): string => $a->title, $actions);
        self::assertContains('Import App\\Models\\User', $titles);
    }

    public function testOffersSimplifyActionForFullyQualifiedName(): void
    {
        $workspace = new PhpactorWorkspace();
        $workspace->open(new TextDocumentItem(
            '/Models/User.xphp',
            'xphp',
            1,
            "<?php\nnamespace App\\Models;\nclass User {}\n",
        ));
        $consumer = "<?php\nnamespace App\\Demos;\nfunction take(\\App\\Models\\User \$u): void {}\n";
        $workspace->open(new TextDocumentItem('/Demos/Take.xphp', 'xphp', 1, $consumer));

        $handler = $this->newHandler($workspace);
        $needle = strpos($consumer, '\\App\\Models\\User');
        self::assertNotFalse($needle);
        $positionMap = new \XPHP\Lsp\PositionMap($consumer);
        [$line, $char] = $positionMap->offsetToPosition($needle + 1);
        $params = new CodeActionParams(
            new TextDocumentIdentifier('/Demos/Take.xphp'),
            new Range(new Position($line, $char), new Position($line, $char)),
            new CodeActionContext(diagnostics: []),
        );
        $actions = wait($handler->codeAction($params));

        $titles = array_map(static fn (CodeAction $a): string => $a->title, $actions);
        self::assertContains('Simplify App\\Models\\User', $titles);
    }

    private function newHandler(PhpactorWorkspace $workspace): XphpCodeActionHandler
    {
        $parser = new XphpSourceParser((new ParserFactory())->createForHostVersion());
        $cache = new ParsedDocumentCache(new Analyzer($parser));
        // Use an empty temp dir so FqnIndex's filesystem walk has
        // nothing to traverse -- the index is workspace-only for these
        // tests.
        $root = sys_get_temp_dir() . '/xphp-codeaction-test-' . bin2hex(random_bytes(4));
        @mkdir($root, 0o755, true);
        $fqnIndex = new FqnIndex($workspace, $cache, $parser, $root);
        return new XphpCodeActionHandler(
            $workspace,
            new ImportCodeActionProvider($fqnIndex, $cache),
            new DiagnosticCodeActionProvider(),
            new OptimizeImportsCodeActionProvider($cache),
        );
    }

    public function testResolveHandlerReturnsActionUnchanged(): void
    {
        $handler = new XphpCodeActionResolveHandler();
        $action = new CodeAction(title: 'Quick fix scaffold');

        $resolved = wait($handler->resolve($action));

        self::assertSame($action, $resolved);
    }

    public function testResolveHandlerMethodsMapAdvertisesEndpoint(): void
    {
        self::assertArrayHasKey(
            'codeAction/resolve',
            (new XphpCodeActionResolveHandler())->methods(),
        );
    }
}
