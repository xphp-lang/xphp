<?php

declare(strict_types=1);

namespace XPHP\Lsp\Test\Handler;

use PhpParser\ParserFactory;
use Phpactor\LanguageServer\Core\Workspace\Workspace as PhpactorWorkspace;
use Phpactor\LanguageServerProtocol\CodeLens;
use Phpactor\LanguageServerProtocol\CodeLensOptions;
use Phpactor\LanguageServerProtocol\CodeLensParams;
use Phpactor\LanguageServerProtocol\ServerCapabilities;
use Phpactor\LanguageServerProtocol\TextDocumentIdentifier;
use Phpactor\LanguageServerProtocol\TextDocumentItem;
use PHPUnit\Framework\TestCase;
use XPHP\Lsp\Analyzer\Analyzer;
use XPHP\Lsp\Analyzer\ParsedDocumentCache;
use XPHP\Lsp\Handler\XphpCodeLensHandler;
use XPHP\Transpiler\Monomorphize\XphpSourceParser;

use function Amp\Promise\wait;

final class XphpCodeLensHandlerTest extends TestCase
{
    public function testEmitsLensForClassDeclaration(): void
    {
        $source = "<?php\nnamespace App;\nclass Foo {}\n";
        $workspace = new PhpactorWorkspace();
        $workspace->open(new TextDocumentItem('/Foo.xphp', 'xphp', 1, $source));
        $handler = $this->newHandler($workspace);

        $lenses = wait($handler->codeLens(new CodeLensParams(new TextDocumentIdentifier('/Foo.xphp'))));

        self::assertCount(1, $lenses);
        self::assertSame(2, $lenses[0]->range->start->line);
        self::assertSame('Show references', $lenses[0]->command?->title);
        self::assertSame(XphpCodeLensHandler::COMMAND_NAME, $lenses[0]->command?->command);
    }

    public function testEmitsLensForEachMethodInsideAClass(): void
    {
        $source = <<<'PHP'
        <?php
        namespace App;
        class Foo {
            public function bar(): void {}
            public function baz(): void {}
        }
        PHP;
        $workspace = new PhpactorWorkspace();
        $workspace->open(new TextDocumentItem('/Foo.xphp', 'xphp', 1, $source));
        $handler = $this->newHandler($workspace);

        $lenses = wait($handler->codeLens(new CodeLensParams(new TextDocumentIdentifier('/Foo.xphp'))));

        // 1 class + 2 methods = 3 lenses.
        self::assertCount(3, $lenses);
    }

    public function testEmitsLensForFreeFunction(): void
    {
        $source = "<?php\nfunction greet(): string { return 'hi'; }\n";
        $workspace = new PhpactorWorkspace();
        $workspace->open(new TextDocumentItem('/greet.xphp', 'xphp', 1, $source));
        $handler = $this->newHandler($workspace);

        $lenses = wait($handler->codeLens(new CodeLensParams(new TextDocumentIdentifier('/greet.xphp'))));

        self::assertCount(1, $lenses);
        self::assertSame(1, $lenses[0]->range->start->line);
    }

    public function testEmptyResponseForUnknownDocument(): void
    {
        $handler = $this->newHandler(new PhpactorWorkspace());

        $lenses = wait($handler->codeLens(new CodeLensParams(new TextDocumentIdentifier('/never-opened.xphp'))));

        self::assertSame([], $lenses);
    }

    public function testEmptyResponseWhenCancelled(): void
    {
        $workspace = new PhpactorWorkspace();
        $workspace->open(new TextDocumentItem('/x.xphp', 'xphp', 1, "<?php\nclass Foo {}"));
        $handler = $this->newHandler($workspace);
        $cancel = new \Amp\CancellationTokenSource();
        $cancel->cancel();

        $lenses = wait($handler->codeLens(
            new CodeLensParams(new TextDocumentIdentifier('/x.xphp')),
            $cancel->getToken(),
        ));

        self::assertSame([], $lenses);
    }

    public function testAdvertisesCodeLensProvider(): void
    {
        $handler = $this->newHandler(new PhpactorWorkspace());
        $caps = new ServerCapabilities();
        $handler->registerCapabiltiies($caps);

        self::assertInstanceOf(CodeLensOptions::class, $caps->codeLensProvider);
        self::assertFalse($caps->codeLensProvider->resolveProvider);
    }

    public function testMethodsMapAdvertisesEndpoint(): void
    {
        self::assertArrayHasKey('textDocument/codeLens', $this->newHandler(new PhpactorWorkspace())->methods());
    }

    public function testCommandArgumentsCarryUriAndPosition(): void
    {
        $source = "<?php\nnamespace App;\nclass Foo {}\n";
        $workspace = new PhpactorWorkspace();
        $workspace->open(new TextDocumentItem('/Foo.xphp', 'xphp', 1, $source));
        $handler = $this->newHandler($workspace);

        $lenses = wait($handler->codeLens(new CodeLensParams(new TextDocumentIdentifier('/Foo.xphp'))));

        $args = $lenses[0]->command?->arguments;
        self::assertIsArray($args);
        self::assertSame('/Foo.xphp', $args[0]);
        // Position points at the `Foo` identifier; we expect line 2.
        self::assertSame(2, $args[1]['line']);
    }

    private function newHandler(PhpactorWorkspace $workspace): XphpCodeLensHandler
    {
        $parser = new XphpSourceParser((new ParserFactory())->createForHostVersion());
        $cache = new ParsedDocumentCache(new Analyzer($parser));
        return new XphpCodeLensHandler($workspace, $cache);
    }
}
