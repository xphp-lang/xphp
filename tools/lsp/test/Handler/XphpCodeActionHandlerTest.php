<?php

declare(strict_types=1);

namespace XPHP\Lsp\Test\Handler;

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
use XPHP\Lsp\Handler\XphpCodeActionHandler;
use XPHP\Lsp\Handler\XphpCodeActionResolveHandler;

use function Amp\Promise\wait;

final class XphpCodeActionHandlerTest extends TestCase
{
    public function testReturnsEmptyArrayForWorkspaceDocument(): void
    {
        $workspace = new PhpactorWorkspace();
        $workspace->open(new TextDocumentItem('/Use.xphp', 'xphp', 1, "<?php\n\$x = 1;\n"));

        $handler = new XphpCodeActionHandler($workspace);
        $params = new CodeActionParams(
            new TextDocumentIdentifier('/Use.xphp'),
            new Range(new Position(0, 0), new Position(0, 0)),
            new CodeActionContext(diagnostics: []),
        );

        self::assertSame([], wait($handler->codeAction($params)));
    }

    public function testReturnsEmptyArrayForUnknownDocument(): void
    {
        $handler = new XphpCodeActionHandler(new PhpactorWorkspace());
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

        $handler = new XphpCodeActionHandler($workspace);
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
        $handler = new XphpCodeActionHandler(new PhpactorWorkspace());
        $caps = new ServerCapabilities();
        $handler->registerCapabiltiies($caps);

        self::assertInstanceOf(CodeActionOptions::class, $caps->codeActionProvider);
        self::assertTrue($caps->codeActionProvider->resolveProvider);
    }

    public function testMethodsMapAdvertisesCodeActionEndpoint(): void
    {
        self::assertArrayHasKey(
            'textDocument/codeAction',
            (new XphpCodeActionHandler(new PhpactorWorkspace()))->methods(),
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
