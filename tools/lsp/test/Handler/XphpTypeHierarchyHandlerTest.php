<?php

declare(strict_types=1);

namespace XPHP\Lsp\Test\Handler;

use PhpParser\ParserFactory;
use Phpactor\LanguageServer\Core\Workspace\Workspace as PhpactorWorkspace;
use Phpactor\LanguageServerProtocol\Position;
use Phpactor\LanguageServerProtocol\ServerCapabilities;
use Phpactor\LanguageServerProtocol\SymbolKind;
use Phpactor\LanguageServerProtocol\TextDocumentIdentifier;
use Phpactor\LanguageServerProtocol\TextDocumentItem;
use Phpactor\LanguageServerProtocol\TextDocumentPositionParams;
use PHPUnit\Framework\TestCase;
use XPHP\Lsp\Analyzer\Analyzer;
use XPHP\Lsp\Analyzer\ParsedDocumentCache;
use XPHP\Lsp\Handler\XphpTypeHierarchyHandler;
use XPHP\Lsp\PositionMap;
use XPHP\Lsp\Reflection\FqnIndex;
use XPHP\Transpiler\Monomorphize\XphpSourceParser;

use function Amp\Promise\wait;

final class XphpTypeHierarchyHandlerTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/xphp-typehier-' . bin2hex(random_bytes(6));
        mkdir($this->root, 0o755, true);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->root)) {
            $this->rmrf($this->root);
        }
    }

    public function testMethodsMapRegistersThreeEndpoints(): void
    {
        $handler = $this->handler(new PhpactorWorkspace());
        $methods = $handler->methods();
        self::assertSame('prepare', $methods['textDocument/prepareTypeHierarchy']);
        self::assertSame('supertypes', $methods['typeHierarchy/supertypes']);
        self::assertSame('subtypes', $methods['typeHierarchy/subtypes']);
    }

    public function testRegisterCapabilitiesAdvertisesProvider(): void
    {
        $handler = $this->handler(new PhpactorWorkspace());
        $capabilities = new ServerCapabilities();
        $handler->registerCapabiltiies($capabilities);
        self::assertTrue($capabilities->typeHierarchyProvider);
    }

    public function testPrepareReturnsEmptyForUnknownUri(): void
    {
        $handler = $this->handler(new PhpactorWorkspace());
        $items = wait($handler->prepare(new TextDocumentPositionParams(
            new TextDocumentIdentifier('/never-opened.xphp'),
            new Position(0, 0),
        )));
        self::assertSame([], $items);
    }

    public function testPrepareReturnsItemForClassAtCursor(): void
    {
        $workspace = new PhpactorWorkspace();
        $workspace->open(new TextDocumentItem('/User.xphp', 'xphp', 1, <<<'XPHP'
        <?php
        namespace App;
        class User {}
        XPHP));
        $handler = $this->handler($workspace);
        $source = $workspace->get('/User.xphp')->text;
        $byte = strpos($source, 'class User') + strlen('class ');
        self::assertNotFalse($byte);
        [$line, $character] = (new PositionMap($source))->offsetToPosition($byte);

        $items = wait($handler->prepare(new TextDocumentPositionParams(
            new TextDocumentIdentifier('/User.xphp'),
            new Position($line, $character),
        )));

        self::assertCount(1, $items);
        self::assertSame('User', $items[0]['name']);
        self::assertSame(SymbolKind::CLASS_, $items[0]['kind']);
        self::assertSame('/User.xphp', $items[0]['uri']);
        self::assertSame('App\\User', $items[0]['data']['fqn']);
        self::assertArrayHasKey('range', $items[0]);
        self::assertArrayHasKey('selectionRange', $items[0]);
    }

    public function testPrepareReturnsInterfaceKindForInterface(): void
    {
        // Locks the SymbolKind dispatch: an interface must surface as
        // SymbolKind::INTERFACE, not SymbolKind::CLASS_.
        $workspace = new PhpactorWorkspace();
        $workspace->open(new TextDocumentItem('/Speaker.xphp', 'xphp', 1, <<<'XPHP'
        <?php
        namespace App;
        interface Speaker {}
        XPHP));
        $handler = $this->handler($workspace);
        $source = $workspace->get('/Speaker.xphp')->text;
        $byte = strpos($source, 'interface Speaker') + strlen('interface ');
        [$line, $character] = (new PositionMap($source))->offsetToPosition($byte);

        $items = wait($handler->prepare(new TextDocumentPositionParams(
            new TextDocumentIdentifier('/Speaker.xphp'),
            new Position($line, $character),
        )));
        self::assertCount(1, $items);
        self::assertSame(SymbolKind::INTERFACE, $items[0]['kind']);
    }

    public function testSupertypesReturnsParentClass(): void
    {
        $workspace = new PhpactorWorkspace();
        $workspace->open(new TextDocumentItem('/Animal.xphp', 'xphp', 1, <<<'XPHP'
        <?php
        namespace App;
        class Animal {}
        XPHP));
        $workspace->open(new TextDocumentItem('/Dog.xphp', 'xphp', 1, <<<'XPHP'
        <?php
        namespace App;
        class Dog extends Animal {}
        XPHP));
        $handler = $this->handler($workspace);

        $items = wait($handler->supertypes(['data' => ['fqn' => 'App\\Dog']]));

        self::assertCount(1, $items);
        self::assertSame('Animal', $items[0]['name']);
        self::assertSame('App\\Animal', $items[0]['data']['fqn']);
        self::assertSame('/Animal.xphp', $items[0]['uri']);
    }

    public function testSupertypesReturnsImplementedInterfaces(): void
    {
        $workspace = new PhpactorWorkspace();
        $workspace->open(new TextDocumentItem('/Speaker.xphp', 'xphp', 1, <<<'XPHP'
        <?php
        namespace App;
        interface Speaker {}
        XPHP));
        $workspace->open(new TextDocumentItem('/Listener.xphp', 'xphp', 1, <<<'XPHP'
        <?php
        namespace App;
        interface Listener {}
        XPHP));
        $workspace->open(new TextDocumentItem('/Dog.xphp', 'xphp', 1, <<<'XPHP'
        <?php
        namespace App;
        class Dog implements Speaker, Listener {}
        XPHP));
        $handler = $this->handler($workspace);

        $items = wait($handler->supertypes(['data' => ['fqn' => 'App\\Dog']]));

        $names = array_map(static fn (array $i): string => $i['name'], $items);
        sort($names);
        self::assertSame(['Listener', 'Speaker'], $names);
    }

    public function testSupertypesReturnsExtendedInterfacesForInterface(): void
    {
        $workspace = new PhpactorWorkspace();
        $workspace->open(new TextDocumentItem('/Speaker.xphp', 'xphp', 1, <<<'XPHP'
        <?php
        namespace App;
        interface Speaker {}
        XPHP));
        $workspace->open(new TextDocumentItem('/Loud.xphp', 'xphp', 1, <<<'XPHP'
        <?php
        namespace App;
        interface Loud extends Speaker {}
        XPHP));
        $handler = $this->handler($workspace);

        $items = wait($handler->supertypes(['data' => ['fqn' => 'App\\Loud']]));
        self::assertCount(1, $items);
        self::assertSame('Speaker', $items[0]['name']);
    }

    public function testSupertypesReturnsEmptyForMalformedParams(): void
    {
        $handler = $this->handler(new PhpactorWorkspace());
        self::assertSame([], wait($handler->supertypes([])));
        self::assertSame([], wait($handler->supertypes(['data' => []])));
        self::assertSame([], wait($handler->supertypes(['data' => ['fqn' => '']])));
        self::assertSame([], wait($handler->supertypes(['data' => ['fqn' => 'App\\NonExistent']])));
    }

    public function testSubtypesReturnsImplementersOfAnInterface(): void
    {
        $workspace = new PhpactorWorkspace();
        $workspace->open(new TextDocumentItem('/Speaker.xphp', 'xphp', 1, <<<'XPHP'
        <?php
        namespace App;
        interface Speaker {}
        XPHP));
        $workspace->open(new TextDocumentItem('/Dog.xphp', 'xphp', 1, <<<'XPHP'
        <?php
        namespace App;
        class Dog implements Speaker {}
        XPHP));
        $workspace->open(new TextDocumentItem('/Cat.xphp', 'xphp', 1, <<<'XPHP'
        <?php
        namespace App;
        class Cat implements Speaker {}
        XPHP));
        $workspace->open(new TextDocumentItem('/Tree.xphp', 'xphp', 1, <<<'XPHP'
        <?php
        namespace App;
        class Tree {}
        XPHP));
        $handler = $this->handler($workspace);

        $items = wait($handler->subtypes(['data' => ['fqn' => 'App\\Speaker']]));

        $names = array_map(static fn (array $i): string => $i['name'], $items);
        sort($names);
        self::assertSame(['Cat', 'Dog'], $names);
    }

    public function testSubtypesReturnsInterfacesThatExtendTarget(): void
    {
        // `interface Loud extends Speaker {}` -- subtypes(Speaker)
        // must include Loud.  Locks the Interface_ branch's
        // `foreach ($node->extends as $iface)` loop in
        // extendsOrImplementsDirectly -- without it, the Class_
        // branch's `implements` walk wouldn't see Loud's parent.
        $workspace = new PhpactorWorkspace();
        $workspace->open(new TextDocumentItem('/Speaker.xphp', 'xphp', 1, <<<'XPHP'
        <?php
        namespace App;
        interface Speaker {}
        XPHP));
        $workspace->open(new TextDocumentItem('/Loud.xphp', 'xphp', 1, <<<'XPHP'
        <?php
        namespace App;
        interface Loud extends Speaker {}
        XPHP));
        $handler = $this->handler($workspace);

        $items = wait($handler->subtypes(['data' => ['fqn' => 'App\\Speaker']]));
        self::assertCount(1, $items);
        self::assertSame('Loud', $items[0]['name']);
    }

    public function testSubtypesReturnsSubclassesOfAClass(): void
    {
        $workspace = new PhpactorWorkspace();
        $workspace->open(new TextDocumentItem('/Animal.xphp', 'xphp', 1, <<<'XPHP'
        <?php
        namespace App;
        class Animal {}
        XPHP));
        $workspace->open(new TextDocumentItem('/Dog.xphp', 'xphp', 1, <<<'XPHP'
        <?php
        namespace App;
        class Dog extends Animal {}
        XPHP));
        $handler = $this->handler($workspace);

        $items = wait($handler->subtypes(['data' => ['fqn' => 'App\\Animal']]));
        self::assertCount(1, $items);
        self::assertSame('Dog', $items[0]['name']);
        self::assertSame('App\\Dog', $items[0]['data']['fqn']);
    }

    public function testSubtypesReturnsEmptyForMalformedOrUnknownItem(): void
    {
        $handler = $this->handler(new PhpactorWorkspace());
        self::assertSame([], wait($handler->subtypes([])));
        self::assertSame([], wait($handler->subtypes(['data' => []])));
        self::assertSame([], wait($handler->subtypes(['data' => ['fqn' => '']])));
        // Unknown FQN with no subclasses → empty.
        self::assertSame([], wait($handler->subtypes(['data' => ['fqn' => 'App\\Nope']])));
    }

    public function testSubtypesReturnsOnlyDirectChildrenNotGrandchildren(): void
    {
        // MVP scope: one-hop subtypes only.  The client recurses per
        // returned item for the deeper hierarchy.  Locks the
        // `extendsOrImplementsDirectly` contract -- Pup extends Dog,
        // Dog extends Animal -- subtypes(Animal) returns Dog only,
        // not Pup.
        $workspace = new PhpactorWorkspace();
        $workspace->open(new TextDocumentItem('/Animal.xphp', 'xphp', 1, <<<'XPHP'
        <?php
        namespace App;
        class Animal {}
        XPHP));
        $workspace->open(new TextDocumentItem('/Dog.xphp', 'xphp', 1, <<<'XPHP'
        <?php
        namespace App;
        class Dog extends Animal {}
        XPHP));
        $workspace->open(new TextDocumentItem('/Pup.xphp', 'xphp', 1, <<<'XPHP'
        <?php
        namespace App;
        class Pup extends Dog {}
        XPHP));
        $handler = $this->handler($workspace);

        $items = wait($handler->subtypes(['data' => ['fqn' => 'App\\Animal']]));
        $names = array_map(static fn (array $i): string => $i['name'], $items);
        self::assertSame(['Dog'], $names, 'subtypes is one-hop -- Pup surfaces only when client recurses on Dog');
    }

    private function handler(PhpactorWorkspace $workspace): XphpTypeHierarchyHandler
    {
        $parser = new XphpSourceParser((new ParserFactory())->createForHostVersion());
        $cache = new ParsedDocumentCache(new Analyzer($parser));
        $fqnIndex = new FqnIndex($workspace, $cache, $parser, $this->root);
        return new XphpTypeHierarchyHandler($workspace, $cache, $parser, $fqnIndex);
    }

    private function rmrf(string $dir): void
    {
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $p = $dir . '/' . $entry;
            if (is_dir($p)) {
                $this->rmrf($p);
            } else {
                unlink($p);
            }
        }
        rmdir($dir);
    }
}
