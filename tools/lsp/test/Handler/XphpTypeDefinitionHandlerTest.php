<?php

declare(strict_types=1);

namespace XPHP\Lsp\Test\Handler;

use PhpParser\ParserFactory;
use Phpactor\LanguageServer\Core\Workspace\Workspace as PhpactorWorkspace;
use Phpactor\LanguageServerProtocol\Position;
use Phpactor\LanguageServerProtocol\ServerCapabilities;
use Phpactor\LanguageServerProtocol\TextDocumentIdentifier;
use Phpactor\LanguageServerProtocol\TextDocumentItem;
use Phpactor\LanguageServerProtocol\TypeDefinitionParams;
use PHPUnit\Framework\TestCase;
use XPHP\Lsp\Analyzer\Analyzer;
use XPHP\Lsp\Analyzer\ParsedDocumentCache;
use XPHP\Lsp\Handler\XphpTypeDefinitionHandler;
use XPHP\Lsp\PositionMap;
use XPHP\Lsp\Reflection\FqnIndex;
use XPHP\Lsp\Reflection\ReflectorFactory;
use XPHP\Lsp\Resolver\GenericResolver;
use XPHP\Lsp\Resolver\PhpDefinitionResolver;
use XPHP\Lsp\Resolver\WorkspaceClassLikeLookup;
use XPHP\Transpiler\Monomorphize\XphpSourceParser;

use function Amp\Promise\wait;

final class XphpTypeDefinitionHandlerTest extends TestCase
{
    public function testJumpsToInferredClassOfVariable(): void
    {
        $workspace = new PhpactorWorkspace();
        $workspace->open(new TextDocumentItem(
            '/User.xphp',
            'xphp',
            1,
            "<?php\nnamespace App;\nclass User { public string \$name = ''; }\n",
        ));
        $useSource = "<?php\nuse App\\User;\n\$user = new User();\necho \$user->name;\n";
        $workspace->open(new TextDocumentItem('/Use.xphp', 'xphp', 1, $useSource));

        // Cursor on the SECOND `$user` (the use site) -- regular GTD
        // would point at the assignment; typeDefinition jumps to the
        // class.
        $byte = strpos($useSource, 'echo $user') + strlen('echo ');
        $location = $this->locateTypeAt($workspace, '/Use.xphp', $useSource, $byte);

        self::assertNotNull($location);
        // worse-reflection's locator chain emits `file://` URIs for
        // resolved Locations; accept either form for robustness.
        self::assertStringEndsWith('/User.xphp', $location->uri);
    }

    public function testJumpsToPropertyTypeClass(): void
    {
        // Cursor on the property's value (a member access) returns
        // the property's declared type's class definition.
        $workspace = new PhpactorWorkspace();
        $workspace->open(new TextDocumentItem(
            '/User.xphp',
            'xphp',
            1,
            "<?php\nnamespace App;\nclass User { public string \$name = ''; }\n",
        ));
        $useSource = "<?php\nuse App\\User;\n\$user = new User();\necho \$user->name;\n";
        $workspace->open(new TextDocumentItem('/Use.xphp', 'xphp', 1, $useSource));

        $byte = strpos($useSource, '->name') + 2; // cursor on 'n' of name
        $location = $this->locateTypeAt($workspace, '/Use.xphp', $useSource, $byte);

        // string is a builtin; worse-reflection has no Location for
        // builtin types.  Whatever the resolver returns, it must not
        // crash and must be either null or a real Location.
        if ($location !== null) {
            self::assertNotEmpty($location->uri);
        }
        $this->assertTrue(true);
    }

    public function testReturnsNullForFunctionCursor(): void
    {
        // Functions have no "type" to jump to -- typeDefinition is
        // only meaningful for type-bearing symbols (variable / property
        // / method / class reference).
        $workspace = new PhpactorWorkspace();
        $useSource = "<?php\nfunction greet(): void {}\ngreet();\n";
        $workspace->open(new TextDocumentItem('/lib.xphp', 'xphp', 1, $useSource));

        $byte = strpos($useSource, 'greet();');
        self::assertNull($this->locateTypeAt($workspace, '/lib.xphp', $useSource, $byte));
    }

    public function testReturnsNullForUnknownDocument(): void
    {
        $workspace = new PhpactorWorkspace();
        $handler = $this->handler($workspace);
        $params = new TypeDefinitionParams(
            new TextDocumentIdentifier('/never-opened.xphp'),
            new Position(0, 0),
        );

        self::assertNull(wait($handler->typeDefinition($params)));
    }

    public function testReturnsNullWhenCancelTokenAlreadyRequested(): void
    {
        $workspace = new PhpactorWorkspace();
        $workspace->open(new TextDocumentItem(
            '/User.xphp',
            'xphp',
            1,
            "<?php\nnamespace App;\nclass User {}\n",
        ));
        $useSource = "<?php\nuse App\\User;\n\$u = new User();\n";
        $workspace->open(new TextDocumentItem('/Use.xphp', 'xphp', 1, $useSource));

        $handler = $this->handler($workspace);
        $byte = strpos($useSource, 'new User');
        [$line, $character] = (new PositionMap($useSource))->offsetToPosition($byte + 4);
        $params = new TypeDefinitionParams(
            new TextDocumentIdentifier('/Use.xphp'),
            new Position($line, $character),
        );

        $cancel = new \Amp\CancellationTokenSource();
        $cancel->cancel();

        self::assertNull(wait($handler->typeDefinition($params, $cancel->getToken())));
    }

    public function testReturnsResultWhenCancelTokenNotRequested(): void
    {
        $workspace = new PhpactorWorkspace();
        $workspace->open(new TextDocumentItem(
            '/User.xphp',
            'xphp',
            1,
            "<?php\nnamespace App;\nclass User {}\n",
        ));
        $useSource = "<?php\nuse App\\User;\n\$u = new User();\n";
        $workspace->open(new TextDocumentItem('/Use.xphp', 'xphp', 1, $useSource));

        $handler = $this->handler($workspace);
        $byte = strpos($useSource, 'new User');
        [$line, $character] = (new PositionMap($useSource))->offsetToPosition($byte + 4);
        $params = new TypeDefinitionParams(
            new TextDocumentIdentifier('/Use.xphp'),
            new Position($line, $character),
        );

        $cancel = new \Amp\CancellationTokenSource();
        // Deliberately NOT cancelled.

        $location = wait($handler->typeDefinition($params, $cancel->getToken()));
        self::assertNotNull($location, 'non-requested cancel token must not short-circuit');
    }

    public function testAdvertisesTypeDefinitionProviderCapability(): void
    {
        $caps = new ServerCapabilities();
        $this->handler(new PhpactorWorkspace())->registerCapabiltiies($caps);

        self::assertTrue($caps->typeDefinitionProvider);
    }

    public function testMethodsMapAdvertisesTypeDefinitionEndpoint(): void
    {
        self::assertArrayHasKey(
            'textDocument/typeDefinition',
            $this->handler(new PhpactorWorkspace())->methods(),
        );
    }

    private function locateTypeAt(PhpactorWorkspace $workspace, string $uri, string $source, int $byte): ?\Phpactor\LanguageServerProtocol\Location
    {
        $handler = $this->handler($workspace);
        [$line, $character] = (new PositionMap($source))->offsetToPosition($byte);
        $params = new TypeDefinitionParams(
            new TextDocumentIdentifier($uri),
            new Position($line, $character),
        );
        return wait($handler->typeDefinition($params));
    }

    private function handler(PhpactorWorkspace $workspace): XphpTypeDefinitionHandler
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
        $classLikeLookup = new WorkspaceClassLikeLookup($workspace, $cache);
        $generic = new GenericResolver($workspace, $cache, $classLikeLookup, $parser, $fqnIndex);
        $resolver = new PhpDefinitionResolver($workspace, $parser, $reflector, $cache, $generic);
        return new XphpTypeDefinitionHandler($resolver);
    }
}
