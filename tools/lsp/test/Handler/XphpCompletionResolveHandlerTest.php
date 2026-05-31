<?php

declare(strict_types=1);

namespace XPHP\Lsp\Test\Handler;

use PhpParser\ParserFactory;
use Phpactor\LanguageServer\Core\Workspace\Workspace as PhpactorWorkspace;
use Phpactor\LanguageServerProtocol\CompletionItem;
use Phpactor\LanguageServerProtocol\CompletionItemKind;
use Phpactor\LanguageServerProtocol\MarkupContent;
use Phpactor\LanguageServerProtocol\MarkupKind;
use Phpactor\LanguageServerProtocol\TextDocumentItem;
use Phpactor\WorseReflection\Reflector;
use PHPUnit\Framework\TestCase;
use XPHP\Lsp\Analyzer\Analyzer;
use XPHP\Lsp\Analyzer\ParsedDocumentCache;
use XPHP\Lsp\Handler\XphpCompletionResolveHandler;
use XPHP\Lsp\Reflection\FqnIndex;
use XPHP\Lsp\Reflection\ReflectorFactory;
use XPHP\Transpiler\Monomorphize\XphpSourceParser;

use function Amp\Promise\wait;

final class XphpCompletionResolveHandlerTest extends TestCase
{
    public function testEnrichesClassItemWithDocblock(): void
    {
        $workspace = new PhpactorWorkspace();
        $workspace->open(new TextDocumentItem(
            '/User.xphp',
            'xphp',
            1,
            "<?php\nnamespace App;\n/**\n * A user account.\n */\nclass User {}\n",
        ));

        $item = new CompletionItem(
            label: 'User',
            kind: CompletionItemKind::CLASS_,
            data: ['kind' => 'class', 'fqn' => 'App\\User'],
        );

        $resolved = wait($this->handler($workspace)->resolve($item));

        self::assertInstanceOf(MarkupContent::class, $resolved->documentation);
        self::assertSame(MarkupKind::MARKDOWN, $resolved->documentation->kind);
        self::assertStringContainsString('A user account.', $resolved->documentation->value);
    }

    public function testLeavesItemUnchangedWhenDataMissing(): void
    {
        $workspace = new PhpactorWorkspace();
        $item = new CompletionItem(label: 'User', kind: CompletionItemKind::CLASS_);

        $resolved = wait($this->handler($workspace)->resolve($item));

        self::assertNull($resolved->documentation);
    }

    public function testLeavesItemUnchangedWhenDataKindUnrecognised(): void
    {
        $workspace = new PhpactorWorkspace();
        $item = new CompletionItem(
            label: 'foo',
            data: ['kind' => 'function', 'fqn' => 'App\\foo'],
        );

        $resolved = wait($this->handler($workspace)->resolve($item));

        // Future kinds (function, method, constant) might be enriched
        // by later commits.  For now, the resolver only handles 'class'
        // and passes everything else through.
        self::assertNull($resolved->documentation);
    }

    public function testLeavesItemUnchangedWhenClassHasNoDocblock(): void
    {
        $workspace = new PhpactorWorkspace();
        $workspace->open(new TextDocumentItem(
            '/User.xphp',
            'xphp',
            1,
            "<?php\nnamespace App;\nclass User {}\n",
        ));

        $item = new CompletionItem(
            label: 'User',
            kind: CompletionItemKind::CLASS_,
            data: ['kind' => 'class', 'fqn' => 'App\\User'],
        );

        $resolved = wait($this->handler($workspace)->resolve($item));

        self::assertNull($resolved->documentation);
    }

    public function testLeavesItemUnchangedWhenClassNotResolvable(): void
    {
        $workspace = new PhpactorWorkspace();
        $item = new CompletionItem(
            label: 'Mystery',
            kind: CompletionItemKind::CLASS_,
            data: ['kind' => 'class', 'fqn' => 'Unknown\\Mystery'],
        );

        $resolved = wait($this->handler($workspace)->resolve($item));

        self::assertNull($resolved->documentation);
    }

    public function testLeavesItemUnchangedWhenReflectorIsNull(): void
    {
        $handler = new XphpCompletionResolveHandler(null);
        $item = new CompletionItem(
            label: 'User',
            data: ['kind' => 'class', 'fqn' => 'App\\User'],
        );

        $resolved = wait($handler->resolve($item));

        self::assertSame($item, $resolved);
    }

    public function testMethodsMapAdvertisesResolveEndpoint(): void
    {
        self::assertArrayHasKey(
            'completionItem/resolve',
            $this->handler(new PhpactorWorkspace())->methods(),
        );
    }

    public function testLeavesItemUnchangedWhenDataIsNotArray(): void
    {
        $workspace = new PhpactorWorkspace();
        $item = new CompletionItem(label: 'User', data: 'just-a-string');

        $resolved = wait($this->handler($workspace)->resolve($item));

        self::assertNull($resolved->documentation);
    }

    public function testLeavesItemUnchangedWhenFqnIsEmpty(): void
    {
        $workspace = new PhpactorWorkspace();
        $item = new CompletionItem(
            label: 'X',
            data: ['kind' => 'class', 'fqn' => ''],
        );

        $resolved = wait($this->handler($workspace)->resolve($item));

        self::assertNull($resolved->documentation);
    }

    private function handler(PhpactorWorkspace $workspace): XphpCompletionResolveHandler
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
        return new XphpCompletionResolveHandler($reflector);
    }
}
