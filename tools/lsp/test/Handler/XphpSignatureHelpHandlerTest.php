<?php

declare(strict_types=1);

namespace XPHP\Lsp\Test\Handler;

use PhpParser\ParserFactory;
use Phpactor\LanguageServer\Core\Workspace\Workspace as PhpactorWorkspace;
use Phpactor\LanguageServerProtocol\Position;
use Phpactor\LanguageServerProtocol\ServerCapabilities;
use Phpactor\LanguageServerProtocol\SignatureHelp;
use Phpactor\LanguageServerProtocol\SignatureHelpOptions;
use Phpactor\LanguageServerProtocol\SignatureHelpParams;
use Phpactor\LanguageServerProtocol\TextDocumentIdentifier;
use Phpactor\LanguageServerProtocol\TextDocumentItem;
use PHPUnit\Framework\TestCase;
use XPHP\Lsp\Analyzer\Analyzer;
use XPHP\Lsp\Analyzer\ParsedDocumentCache;
use XPHP\Lsp\Handler\XphpSignatureHelpHandler;
use XPHP\Lsp\PositionMap;
use XPHP\Lsp\Reflection\FqnIndex;
use XPHP\Lsp\Reflection\ReflectorFactory;
use XPHP\Transpiler\Monomorphize\XphpSourceParser;

use function Amp\Promise\wait;

final class XphpSignatureHelpHandlerTest extends TestCase
{
    public function testFreeFunctionCallShowsSignatureWithFirstArgActive(): void
    {
        $workspace = new PhpactorWorkspace();
        $workspace->open(new TextDocumentItem(
            '/lib.xphp',
            'xphp',
            1,
            "<?php\nfunction greet(string \$name, int \$count): string { return \$name; }\n",
        ));
        $useSource = "<?php\ngreet();\n";
        $workspace->open(new TextDocumentItem('/Use.xphp', 'xphp', 1, $useSource));

        $byte = strpos($useSource, 'greet(') + strlen('greet(');
        $help = $this->signatureAt($workspace, '/Use.xphp', $useSource, $byte);

        self::assertInstanceOf(SignatureHelp::class, $help);
        self::assertCount(1, $help->signatures);
        self::assertSame('greet(string $name, int $count)', $help->signatures[0]->label);
        self::assertSame(0, $help->activeParameter);
    }

    public function testActiveParameterAdvancesPastCommas(): void
    {
        $workspace = new PhpactorWorkspace();
        $workspace->open(new TextDocumentItem(
            '/lib.xphp',
            'xphp',
            1,
            "<?php\nfunction greet(string \$name, int \$count): string { return \$name; }\n",
        ));
        $useSource = "<?php\ngreet('a', );\n";
        $workspace->open(new TextDocumentItem('/Use.xphp', 'xphp', 1, $useSource));

        // Cursor after `'a', ` -- should highlight the second param.
        $byte = strpos($useSource, "'a', ") + strlen("'a', ");
        $help = $this->signatureAt($workspace, '/Use.xphp', $useSource, $byte);

        self::assertInstanceOf(SignatureHelp::class, $help);
        self::assertSame(1, $help->activeParameter);
    }

    public function testConstructorCallShowsConstructorSignature(): void
    {
        $workspace = new PhpactorWorkspace();
        $workspace->open(new TextDocumentItem(
            '/User.xphp',
            'xphp',
            1,
            "<?php\nnamespace App;\nclass User { public function __construct(public string \$name, public int \$age) {} }\n",
        ));
        $useSource = "<?php\nuse App\\User;\nnew User();\n";
        $workspace->open(new TextDocumentItem('/Use.xphp', 'xphp', 1, $useSource));

        $byte = strpos($useSource, 'new User(') + strlen('new User(');
        $help = $this->signatureAt($workspace, '/Use.xphp', $useSource, $byte);

        self::assertInstanceOf(SignatureHelp::class, $help);
        self::assertStringContainsString('$name', $help->signatures[0]->label);
        self::assertStringContainsString('$age', $help->signatures[0]->label);
    }

    public function testStaticCallShowsMethodSignature(): void
    {
        $workspace = new PhpactorWorkspace();
        $workspace->open(new TextDocumentItem(
            '/Factory.xphp',
            'xphp',
            1,
            "<?php\nnamespace App;\nclass Factory { public static function make(string \$kind, int \$qty): void {} }\n",
        ));
        $useSource = "<?php\nuse App\\Factory;\nFactory::make();\n";
        $workspace->open(new TextDocumentItem('/Use.xphp', 'xphp', 1, $useSource));

        $byte = strpos($useSource, 'make(') + strlen('make(');
        $help = $this->signatureAt($workspace, '/Use.xphp', $useSource, $byte);

        self::assertInstanceOf(SignatureHelp::class, $help);
        self::assertStringContainsString('$kind', $help->signatures[0]->label);
    }

    public function testReturnsNullWhenCursorNotInsideCall(): void
    {
        $workspace = new PhpactorWorkspace();
        $useSource = "<?php\n\$x = 1 + 2;\n";
        $workspace->open(new TextDocumentItem('/Use.xphp', 'xphp', 1, $useSource));

        $byte = strpos($useSource, '$x');
        self::assertNull($this->signatureAt($workspace, '/Use.xphp', $useSource, $byte));
    }

    public function testReturnsNullForUnknownDocument(): void
    {
        $workspace = new PhpactorWorkspace();
        $handler = $this->handler($workspace);
        $params = new SignatureHelpParams(
            new TextDocumentIdentifier('/never-opened.xphp'),
            new Position(0, 0),
        );

        self::assertNull(wait($handler->signatureHelp($params)));
    }

    public function testReturnsNullWhenCancelTokenAlreadyRequested(): void
    {
        $workspace = new PhpactorWorkspace();
        $useSource = "<?php\nfunction greet(string \$n): void {}\ngreet();\n";
        $workspace->open(new TextDocumentItem('/Use.xphp', 'xphp', 1, $useSource));

        $handler = $this->handler($workspace);
        $byte = strpos($useSource, 'greet(') + strlen('greet(');
        [$line, $character] = (new PositionMap($useSource))->offsetToPosition($byte);
        $params = new SignatureHelpParams(
            new TextDocumentIdentifier('/Use.xphp'),
            new Position($line, $character),
        );
        $cancel = new \Amp\CancellationTokenSource();
        $cancel->cancel();

        self::assertNull(wait($handler->signatureHelp($params, $cancel->getToken())));
    }

    public function testAdvertisesSignatureHelpProviderWithTriggerChars(): void
    {
        $caps = new ServerCapabilities();
        $this->handler(new PhpactorWorkspace())->registerCapabiltiies($caps);

        self::assertInstanceOf(SignatureHelpOptions::class, $caps->signatureHelpProvider);
        self::assertSame(['(', ','], $caps->signatureHelpProvider->triggerCharacters);
    }

    public function testMethodsMapAdvertisesSignatureHelpEndpoint(): void
    {
        self::assertArrayHasKey(
            'textDocument/signatureHelp',
            $this->handler(new PhpactorWorkspace())->methods(),
        );
    }

    private function signatureAt(PhpactorWorkspace $workspace, string $uri, string $source, int $byte): ?SignatureHelp
    {
        $handler = $this->handler($workspace);
        [$line, $character] = (new PositionMap($source))->offsetToPosition($byte);
        $params = new SignatureHelpParams(
            new TextDocumentIdentifier($uri),
            new Position($line, $character),
        );
        return wait($handler->signatureHelp($params));
    }

    private function handler(PhpactorWorkspace $workspace): XphpSignatureHelpHandler
    {
        $parser = new XphpSourceParser((new ParserFactory())->createForHostVersion());
        $cache = new ParsedDocumentCache(new Analyzer($parser));
        $fqnIndex = new FqnIndex($workspace, $cache, $parser, '');
        $reflector = (new ReflectorFactory(
            $workspace,
            $cache,
            $parser,
            rootPath: '',
            stubPath: ReflectorFactory::defaultStubPath(),
            cacheDir: ReflectorFactory::defaultCacheDir(),
            fqnIndex: $fqnIndex,
        ))->build();
        return new XphpSignatureHelpHandler($workspace, $cache, $parser, $reflector);
    }
}
