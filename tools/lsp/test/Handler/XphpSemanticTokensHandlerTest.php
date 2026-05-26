<?php

declare(strict_types=1);

namespace XPHP\Lsp\Test\Handler;

use PhpParser\ParserFactory;
use Phpactor\LanguageServer\Core\Workspace\Workspace as PhpactorWorkspace;
use Phpactor\LanguageServerProtocol\SemanticTokens;
use Phpactor\LanguageServerProtocol\ServerCapabilities;
use Phpactor\LanguageServerProtocol\TextDocumentItem;
use PHPUnit\Framework\TestCase;
use XPHP\Lsp\Analyzer\Analyzer;
use XPHP\Lsp\Analyzer\ParsedDocumentCache;
use XPHP\Lsp\Handler\SemanticTokens\TokenLegend;
use XPHP\Lsp\Handler\XphpSemanticTokensHandler;
use XPHP\Transpiler\Monomorphize\XphpSourceParser;

use function Amp\Promise\wait;

final class XphpSemanticTokensHandlerTest extends TestCase
{
    public function testCapabilityAdvertisesLegendAndFullSupport(): void
    {
        $handler = $this->newHandler(new PhpactorWorkspace());

        $caps = new ServerCapabilities();
        $handler->registerCapabiltiies($caps);

        self::assertIsArray($caps->semanticTokensProvider);
        self::assertArrayHasKey('legend', $caps->semanticTokensProvider);
        self::assertArrayHasKey('full', $caps->semanticTokensProvider);
        self::assertTrue($caps->semanticTokensProvider['full']);

        $legend = $caps->semanticTokensProvider['legend'];
        self::assertSame(TokenLegend::TOKEN_TYPES, $legend->tokenTypes);
        self::assertSame(TokenLegend::TOKEN_MODIFIERS, $legend->tokenModifiers);
    }

    public function testMethodsMapAdvertisesTheFullEndpoint(): void
    {
        $handler = $this->newHandler(new PhpactorWorkspace());
        self::assertSame(
            ['textDocument/semanticTokens/full' => 'semanticTokensFull'],
            $handler->methods(),
        );
    }

    public function testUnknownDocumentReturnsEmptyTokens(): void
    {
        $handler = $this->newHandler(new PhpactorWorkspace());

        $result = wait($handler->semanticTokensFull([
            'textDocument' => ['uri' => '/never-opened.xphp'],
        ]));

        self::assertInstanceOf(SemanticTokens::class, $result);
        self::assertSame([], $result->data);
    }

    public function testKnownDocumentReturnsNonEmptyTokenStream(): void
    {
        // Slice 2: visitor classifies keywords, vars, comments,
        // class names, etc.  The protocol shape (SemanticTokens
        // object wrapping a packed integer array) is the same as
        // slice 1; we just have non-empty data now.  Detailed
        // classification assertions live in AstVisitorTest -- here
        // we only care that the handler delivers SOMETHING.
        $workspace = new PhpactorWorkspace();
        $workspace->open(new TextDocumentItem('/box.xphp', 'xphp', 1, <<<'XPHP'
        <?php
        namespace App;
        class Box<T> {
            public T $item;
        }
        XPHP));

        $handler = $this->newHandler($workspace);

        $result = wait($handler->semanticTokensFull([
            'textDocument' => ['uri' => '/box.xphp'],
        ]));

        self::assertInstanceOf(SemanticTokens::class, $result);
        self::assertNotEmpty($result->data);
        // Sanity: packed array length must be a multiple of 5 (5 ints
        // per token by LSP spec).
        self::assertSame(0, count($result->data) % 5);
    }

    public function testMalformedParamsReturnsEmptyTokens(): void
    {
        // Defensive: if `textDocument` is missing, return empty rather than
        // throwing -- a misbehaving client shouldn't kill the handler.
        $handler = $this->newHandler(new PhpactorWorkspace());

        $result = wait($handler->semanticTokensFull([]));

        self::assertInstanceOf(SemanticTokens::class, $result);
        self::assertSame([], $result->data);
    }

    public function testTextDocumentAsObjectIsAlsoAccepted(): void
    {
        // PassThroughArgumentResolver typically hands us an associative
        // array, but a future caller may pass an object.  Tolerate both
        // shapes (defensive read in `extractUri`).
        $workspace = new PhpactorWorkspace();
        $workspace->open(new TextDocumentItem('/empty.xphp', 'xphp', 1, '<?php'));

        $handler = $this->newHandler($workspace);
        $textDocument = new \stdClass();
        $textDocument->uri = '/empty.xphp';

        $result = wait($handler->semanticTokensFull([
            'textDocument' => $textDocument,
        ]));

        self::assertInstanceOf(SemanticTokens::class, $result);
    }

    private function newHandler(PhpactorWorkspace $workspace): XphpSemanticTokensHandler
    {
        return new XphpSemanticTokensHandler($workspace, $this->newCache());
    }

    private function newCache(): ParsedDocumentCache
    {
        return new ParsedDocumentCache(
            new Analyzer(new XphpSourceParser((new ParserFactory())->createForHostVersion())),
        );
    }
}
