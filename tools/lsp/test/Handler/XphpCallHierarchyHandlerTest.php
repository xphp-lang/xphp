<?php

declare(strict_types=1);

namespace XPHP\Lsp\Test\Handler;

use PhpParser\ParserFactory;
use Phpactor\LanguageServer\Core\Workspace\Workspace as PhpactorWorkspace;
use Phpactor\LanguageServerProtocol\CallHierarchyIncomingCall;
use Phpactor\LanguageServerProtocol\CallHierarchyItem;
use Phpactor\LanguageServerProtocol\CallHierarchyOutgoingCall;
use Phpactor\LanguageServerProtocol\ServerCapabilities;
use Phpactor\LanguageServerProtocol\SymbolKind;
use Phpactor\LanguageServerProtocol\TextDocumentItem;
use PHPUnit\Framework\TestCase;
use XPHP\Lsp\Analyzer\Analyzer;
use XPHP\Lsp\Analyzer\ParsedDocumentCache;
use XPHP\Lsp\Handler\XphpCallHierarchyHandler;
use XPHP\Lsp\Reflection\FqnIndex;
use XPHP\Transpiler\Monomorphize\XphpSourceParser;

use function Amp\Promise\wait;

final class XphpCallHierarchyHandlerTest extends TestCase
{
    public function testPrepareReturnsItemForMethodAtCursor(): void
    {
        $source = <<<'PHP'
        <?php
        namespace App;
        class Foo {
            public function bar(): void {}
        }
        PHP;
        $workspace = new PhpactorWorkspace();
        $workspace->open(new TextDocumentItem('/Foo.xphp', 'xphp', 1, $source));
        $handler = $this->newHandler($workspace);

        $params = [
            'textDocument' => ['uri' => '/Foo.xphp'],
            'position' => ['line' => 3, 'character' => 22],
        ];
        $items = wait($handler->prepare($params));

        self::assertCount(1, $items);
        self::assertSame('App\\Foo::bar', $items[0]->name);
        self::assertSame(SymbolKind::METHOD, $items[0]->kind);
    }

    public function testPrepareReturnsItemForFreeFunctionAtCursor(): void
    {
        $source = "<?php\nfunction greet(): string { return 'hi'; }\n";
        $workspace = new PhpactorWorkspace();
        $workspace->open(new TextDocumentItem('/g.xphp', 'xphp', 1, $source));
        $handler = $this->newHandler($workspace);

        $params = [
            'textDocument' => ['uri' => '/g.xphp'],
            'position' => ['line' => 1, 'character' => 10],
        ];
        $items = wait($handler->prepare($params));

        self::assertCount(1, $items);
        self::assertSame('greet', $items[0]->name);
        self::assertSame(SymbolKind::FUNCTION, $items[0]->kind);
    }

    public function testPrepareReturnsEmptyForUnknownDocument(): void
    {
        $handler = $this->newHandler(new PhpactorWorkspace());
        $items = wait($handler->prepare([
            'textDocument' => ['uri' => '/never-opened.xphp'],
            'position' => ['line' => 0, 'character' => 0],
        ]));
        self::assertSame([], $items);
    }

    public function testIncomingCallsFindsCallSitesAcrossWorkspace(): void
    {
        $callee = <<<'PHP'
        <?php
        namespace App;
        class Repository {
            public function save(): void {}
        }
        PHP;
        $caller = <<<'PHP'
        <?php
        namespace App;
        function persist(Repository $r): void {
            $r->save();
        }
        PHP;
        $workspace = new PhpactorWorkspace();
        $workspace->open(new TextDocumentItem('/Repository.xphp', 'xphp', 1, $callee));
        $workspace->open(new TextDocumentItem('/persist.xphp', 'xphp', 1, $caller));
        $handler = $this->newHandler($workspace);

        $params = [
            'item' => [
                'uri' => '/Repository.xphp',
                'data' => ['classFqn' => 'App\\Repository', 'name' => 'save'],
            ],
        ];
        $incoming = wait($handler->incomingCalls($params));

        self::assertNotEmpty($incoming);
        self::assertContainsOnlyInstancesOf(CallHierarchyIncomingCall::class, $incoming);
        $fromNames = array_map(static fn (CallHierarchyIncomingCall $c): string => $c->from->name, $incoming);
        self::assertContains('App\\persist', $fromNames);
    }

    public function testOutgoingCallsReturnsCalleesFromMethodBody(): void
    {
        $source = <<<'PHP'
        <?php
        namespace App;
        class Foo {
            public function bar(Other $o): void {
                $o->baz();
                $o->qux();
            }
        }
        PHP;
        $workspace = new PhpactorWorkspace();
        $workspace->open(new TextDocumentItem('/Foo.xphp', 'xphp', 1, $source));
        $handler = $this->newHandler($workspace);

        $params = [
            'item' => [
                'uri' => '/Foo.xphp',
                'data' => ['classFqn' => 'App\\Foo', 'name' => 'bar'],
            ],
        ];
        $outgoing = wait($handler->outgoingCalls($params));

        self::assertContainsOnlyInstancesOf(CallHierarchyOutgoingCall::class, $outgoing);
        $calleeNames = array_map(static fn (CallHierarchyOutgoingCall $c): string => $c->to->name, $outgoing);
        self::assertContains('baz', $calleeNames);
        self::assertContains('qux', $calleeNames);
    }

    public function testIncomingCallsReturnsEmptyForMissingItem(): void
    {
        $handler = $this->newHandler(new PhpactorWorkspace());
        self::assertSame([], wait($handler->incomingCalls(['item' => 'not-an-array'])));
        self::assertSame([], wait($handler->incomingCalls([])));
    }

    public function testOutgoingCallsReturnsEmptyForMissingFunctionInDocument(): void
    {
        $workspace = new PhpactorWorkspace();
        $workspace->open(new TextDocumentItem('/x.xphp', 'xphp', 1, "<?php\nfunction other(): void {}"));
        $handler = $this->newHandler($workspace);

        $outgoing = wait($handler->outgoingCalls([
            'item' => [
                'uri' => '/x.xphp',
                'data' => ['classFqn' => '', 'name' => 'doesnotexist'],
            ],
        ]));
        self::assertSame([], $outgoing);
    }

    public function testAdvertisesCallHierarchyProvider(): void
    {
        $handler = $this->newHandler(new PhpactorWorkspace());
        $caps = new ServerCapabilities();
        $handler->registerCapabiltiies($caps);

        self::assertTrue($caps->callHierarchyProvider);
    }

    public function testMethodsMapAdvertisesAllThreeEndpoints(): void
    {
        $methods = $this->newHandler(new PhpactorWorkspace())->methods();
        self::assertArrayHasKey('textDocument/prepareCallHierarchy', $methods);
        self::assertArrayHasKey('callHierarchy/incomingCalls', $methods);
        self::assertArrayHasKey('callHierarchy/outgoingCalls', $methods);
    }

    private function newHandler(PhpactorWorkspace $workspace): XphpCallHierarchyHandler
    {
        $parser = new XphpSourceParser((new ParserFactory())->createForHostVersion());
        $cache = new ParsedDocumentCache(new Analyzer($parser));
        $root = sys_get_temp_dir() . '/xphp-callhier-' . bin2hex(random_bytes(4));
        @mkdir($root, 0o755, true);
        $fqnIndex = new FqnIndex($workspace, $cache, $parser, $root);
        return new XphpCallHierarchyHandler($workspace, $cache, $fqnIndex);
    }
}
