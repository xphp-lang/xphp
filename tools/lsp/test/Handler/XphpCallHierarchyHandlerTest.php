<?php

declare(strict_types=1);

namespace XPHP\Lsp\Test\Handler;

use PhpParser\ParserFactory;
use Phpactor\LanguageServer\Core\Workspace\Workspace as PhpactorWorkspace;
use Phpactor\LanguageServerProtocol\CallHierarchyIncomingCall;
use Phpactor\LanguageServerProtocol\CallHierarchyItem;
use Phpactor\LanguageServerProtocol\CallHierarchyOutgoingCall;
use Phpactor\LanguageServerProtocol\Position;
use Phpactor\LanguageServerProtocol\ServerCapabilities;
use Phpactor\LanguageServerProtocol\SymbolKind;
use Phpactor\LanguageServerProtocol\TextDocumentIdentifier;
use Phpactor\LanguageServerProtocol\TextDocumentItem;
use Phpactor\LanguageServerProtocol\TextDocumentPositionParams;
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

        $params = new TextDocumentPositionParams(
            new TextDocumentIdentifier('/Foo.xphp'),
            new Position(3, 22),
        );
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

        $params = new TextDocumentPositionParams(
            new TextDocumentIdentifier('/g.xphp'),
            new Position(1, 10),
        );
        $items = wait($handler->prepare($params));

        self::assertCount(1, $items);
        self::assertSame('greet', $items[0]->name);
        self::assertSame(SymbolKind::FUNCTION, $items[0]->kind);
    }

    public function testPrepareReturnsEmptyForUnknownDocument(): void
    {
        $handler = $this->newHandler(new PhpactorWorkspace());
        $items = wait($handler->prepare(new TextDocumentPositionParams(
            new TextDocumentIdentifier('/never-opened.xphp'),
            new Position(0, 0),
        )));
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

        $item = [
            'uri' => '/Repository.xphp',
            'data' => ['classFqn' => 'App\\Repository', 'name' => 'save'],
        ];
        $incoming = wait($handler->incomingCalls($item));

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

        $item = [
            'uri' => '/Foo.xphp',
            'data' => ['classFqn' => 'App\\Foo', 'name' => 'bar'],
        ];
        $outgoing = wait($handler->outgoingCalls($item));

        self::assertContainsOnlyInstancesOf(CallHierarchyOutgoingCall::class, $outgoing);
        $calleeNames = array_map(static fn (CallHierarchyOutgoingCall $c): string => $c->to->name, $outgoing);
        self::assertContains('baz', $calleeNames);
        self::assertContains('qux', $calleeNames);
    }

    public function testIncomingCallsSurfacesTopLevelCallSitesViaModuleScope(): void
    {
        // PHP allows top-level script code (no enclosing function /
        // method).  Calls there must surface in the Callers view --
        // before this fix the walker only descended into Function_ /
        // ClassMethod, so script-mode call sites were invisible.
        //
        // Fixture mirrors the playground demo shape: a class with the
        // target method + a separate file that calls it from
        // top-level scripting under a namespace.
        $workspace = new PhpactorWorkspace();
        $workspace->open(new TextDocumentItem('/Animal.xphp', 'xphp', 1, <<<'PHP'
        <?php
        namespace App;
        class Animal {
            public function speak(): string { return 'noise'; }
        }
        PHP));
        $workspace->open(new TextDocumentItem('/demo.xphp', 'xphp', 1, <<<'PHP'
        <?php
        namespace App\Demos;
        use App\Animal;
        $a = new Animal();
        $a->speak();
        PHP));
        $handler = $this->newHandler($workspace);

        $item = [
            'uri' => '/Animal.xphp',
            'data' => ['classFqn' => 'App\\Animal', 'name' => 'speak'],
        ];
        $incoming = wait($handler->incomingCalls($item));

        self::assertNotEmpty($incoming, 'top-level call site must surface');
        self::assertCount(1, $incoming);
        // Synthetic top-level scope item: SymbolKind::MODULE,
        // name = file basename, data.name sentinel = `__topLevel`.
        $from = $incoming[0]->from;
        self::assertSame('demo.xphp', $from->name);
        self::assertSame(SymbolKind::MODULE, $from->kind);
        self::assertSame('__topLevel', $from->data['name']);
        self::assertSame('/demo.xphp', $from->uri);
        // fromRanges must point at the call site.
        self::assertCount(1, $incoming[0]->fromRanges);
    }

    public function testOutgoingCallsResolvesTopLevelScopeBody(): void
    {
        // Symmetric: when the user navigates into the top-level
        // scope entry from a Callers view and asks for its
        // outgoing calls, walk the file's script-mode statements
        // (not a method body).  Locks the `__topLevel` sentinel
        // special-case in outgoingCalls.
        $workspace = new PhpactorWorkspace();
        $workspace->open(new TextDocumentItem('/demo.xphp', 'xphp', 1, <<<'PHP'
        <?php
        namespace App\Demos;
        $a = new \App\Animal();
        $a->speak();
        $a->describe();
        PHP));
        $handler = $this->newHandler($workspace);

        $item = [
            'uri' => '/demo.xphp',
            'data' => ['classFqn' => '', 'name' => '__topLevel'],
        ];
        $outgoing = wait($handler->outgoingCalls($item));

        $names = array_map(static fn (CallHierarchyOutgoingCall $c): string => $c->to->name, $outgoing);
        sort($names);
        self::assertSame(['describe', 'speak'], $names);
    }

    public function testIncomingCallsScansFilesystemPathsNotJustOpenDocuments(): void
    {
        // Prod scenario: user has only the callee class open
        // (`Animal.xphp`); the file with the calls (`Inheritance.xphp`)
        // sits on disk but is closed.  Without the filesystem walk
        // the Callers view always comes back empty.  Locks the
        // FqnIndex::indexedFilesystemPaths() iteration in
        // collectCallSites.
        $root = sys_get_temp_dir() . '/xphp-callhier-fs-' . bin2hex(random_bytes(4));
        mkdir($root, 0o755, true);
        try {
            $animalSource = <<<'PHP'
            <?php
            namespace App;
            class Animal {
                public function speak(): string { return 'noise'; }
            }
            PHP;
            $demoSource = <<<'PHP'
            <?php
            namespace App\Demos;
            $a = new \App\Animal();
            $a->speak();
            PHP;
            file_put_contents($root . '/Animal.xphp', $animalSource);
            file_put_contents($root . '/demo.xphp', $demoSource);

            // Only Animal.xphp is OPEN in the workspace.  demo.xphp
            // lives on disk only -- the workspace iteration alone
            // would never see it.
            $workspace = new PhpactorWorkspace();
            $workspace->open(new TextDocumentItem(
                'file://' . $root . '/Animal.xphp',
                'xphp',
                1,
                $animalSource,
            ));
            $handler = $this->newHandler($workspace, $root);

            $item = [
                'uri' => 'file://' . $root . '/Animal.xphp',
                'data' => ['classFqn' => 'App\\Animal', 'name' => 'speak'],
            ];
            $incoming = wait($handler->incomingCalls($item));

            self::assertNotEmpty($incoming, 'closed-file call site must surface via FS walk');
            $uris = array_map(static fn (CallHierarchyIncomingCall $c): string => $c->from->uri, $incoming);
            self::assertContains('file://' . $root . '/demo.xphp', $uris);
        } finally {
            @unlink($root . '/Animal.xphp');
            @unlink($root . '/demo.xphp');
            @rmdir($root);
        }
    }

    public function testIncomingCallsReturnsEmptyForMissingItem(): void
    {
        // incomingCalls(array $item) is type-hinted; non-array would
        // TypeError.  Exercise the empty-name defensive guard with
        // an array that has the right shape but no useful name.
        $handler = $this->newHandler(new PhpactorWorkspace());
        self::assertSame([], wait($handler->incomingCalls(['data' => ['name' => '']])));
        self::assertSame([], wait($handler->incomingCalls([])));
    }

    public function testOutgoingCallsReturnsEmptyForMissingFunctionInDocument(): void
    {
        $workspace = new PhpactorWorkspace();
        $workspace->open(new TextDocumentItem('/x.xphp', 'xphp', 1, "<?php\nfunction other(): void {}"));
        $handler = $this->newHandler($workspace);

        $outgoing = wait($handler->outgoingCalls([
            'uri' => '/x.xphp',
            'data' => ['classFqn' => '', 'name' => 'doesnotexist'],
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

    private function newHandler(PhpactorWorkspace $workspace, ?string $root = null): XphpCallHierarchyHandler
    {
        $parser = new XphpSourceParser((new ParserFactory())->createForHostVersion());
        $cache = new ParsedDocumentCache(new Analyzer($parser));
        if ($root === null) {
            $root = sys_get_temp_dir() . '/xphp-callhier-' . bin2hex(random_bytes(4));
            @mkdir($root, 0o755, true);
        }
        $fqnIndex = new FqnIndex($workspace, $cache, $parser, $root);
        return new XphpCallHierarchyHandler($workspace, $cache, $fqnIndex, $parser);
    }
}
