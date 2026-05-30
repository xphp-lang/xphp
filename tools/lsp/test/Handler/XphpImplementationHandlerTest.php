<?php

declare(strict_types=1);

namespace XPHP\Lsp\Test\Handler;

use PhpParser\ParserFactory;
use Phpactor\LanguageServer\Core\Workspace\Workspace as PhpactorWorkspace;
use Phpactor\LanguageServerProtocol\ImplementationParams;
use Phpactor\LanguageServerProtocol\Location;
use Phpactor\LanguageServerProtocol\Position;
use Phpactor\LanguageServerProtocol\ServerCapabilities;
use Phpactor\LanguageServerProtocol\TextDocumentIdentifier;
use Phpactor\LanguageServerProtocol\TextDocumentItem;
use PHPUnit\Framework\TestCase;
use XPHP\Lsp\Analyzer\Analyzer;
use XPHP\Lsp\Analyzer\ParsedDocumentCache;
use XPHP\Lsp\Handler\XphpImplementationHandler;
use XPHP\Lsp\PositionMap;
use XPHP\Lsp\Reflection\FqnIndex;
use XPHP\Transpiler\Monomorphize\XphpSourceParser;

use function Amp\Promise\wait;

final class XphpImplementationHandlerTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/xphp-impl-' . bin2hex(random_bytes(6));
        mkdir($this->root, 0o755, true);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->root)) {
            $this->rmrf($this->root);
        }
    }

    public function testMethodsMapRegistersEndpoint(): void
    {
        $handler = $this->handler(new PhpactorWorkspace());
        self::assertArrayHasKey('textDocument/implementation', $handler->methods());
        self::assertSame('implementation', $handler->methods()['textDocument/implementation']);
    }

    public function testRegisterCapabilitiesAdvertisesImplementationProvider(): void
    {
        $handler = $this->handler(new PhpactorWorkspace());
        $capabilities = new ServerCapabilities();
        $handler->registerCapabiltiies($capabilities);
        self::assertTrue($capabilities->implementationProvider);
    }

    public function testReturnsEmptyForUnknownUri(): void
    {
        $handler = $this->handler(new PhpactorWorkspace());
        $params = new ImplementationParams(
            new TextDocumentIdentifier('/never-opened.xphp'),
            new Position(0, 0),
        );
        self::assertSame([], wait($handler->implementation($params)));
    }

    public function testReturnsEmptyForCursorOffAClassLike(): void
    {
        $workspace = new PhpactorWorkspace();
        $workspace->open(new TextDocumentItem('/A.xphp', 'xphp', 1, <<<'XPHP'
        <?php
        namespace App;
        class A {}
        XPHP));
        $handler = $this->handler($workspace);
        $params = new ImplementationParams(
            new TextDocumentIdentifier('/A.xphp'),
            new Position(0, 0),  // cursor on `<?php`
        );
        self::assertSame([], wait($handler->implementation($params)));
    }

    public function testReturnsImplementersOfInterfaceFromOpenDocs(): void
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

        $locations = $this->callAtNeedle($handler, $workspace, '/Speaker.xphp', 'interface Speaker', strlen('interface '));

        self::assertCount(2, $locations);
        self::assertContainsOnlyInstancesOf(Location::class, $locations);
        $uris = array_map(static fn (Location $l): string => $l->uri, $locations);
        sort($uris);
        self::assertSame(['/Cat.xphp', '/Dog.xphp'], $uris);
    }

    public function testReturnsSubclassesOfAClass(): void
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

        $locations = $this->callAtNeedle($handler, $workspace, '/Animal.xphp', 'class Animal', strlen('class '));

        self::assertCount(1, $locations);
        self::assertSame('/Dog.xphp', $locations[0]->uri);
    }

    public function testReturnsInterfacesThatExtendTarget(): void
    {
        // `interface Loud extends Speaker {}` -- implementation(Speaker)
        // includes Loud.
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

        $locations = $this->callAtNeedle($handler, $workspace, '/Speaker.xphp', 'interface Speaker', strlen('interface '));
        self::assertCount(1, $locations);
        self::assertSame('/Loud.xphp', $locations[0]->uri);
    }

    public function testReturnsOnlyDirectImplementersNotTransitive(): void
    {
        // MVP scope: one-hop.  Pup extends Dog extends Animal.
        // implementation(Animal) returns Dog only; client recurses on
        // Dog to find Pup.
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

        $locations = $this->callAtNeedle($handler, $workspace, '/Animal.xphp', 'class Animal', strlen('class '));
        $uris = array_map(static fn (Location $l): string => $l->uri, $locations);
        self::assertSame(['/Dog.xphp'], $uris, 'one-hop only -- Pup surfaces when client recurses on Dog');
    }

    public function testReturnsLocationRangePointingAtTheNameToken(): void
    {
        // The Location's range MUST cover the implementing class's
        // NAME token (so PhpStorm highlights `Dog`), not its full body.
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
        $handler = $this->handler($workspace);

        $locations = $this->callAtNeedle($handler, $workspace, '/Speaker.xphp', 'interface Speaker', strlen('interface '));
        self::assertCount(1, $locations);
        $dogSource = $workspace->get('/Dog.xphp')->text;
        $expectedStart = strpos($dogSource, 'Dog');
        self::assertNotFalse($expectedStart);
        [$el, $ec] = (new PositionMap($dogSource))->offsetToPosition($expectedStart);
        self::assertSame($el, $locations[0]->range->start->line);
        self::assertSame($ec, $locations[0]->range->start->character);
        // End position covers exactly `Dog` (3 chars after start).
        self::assertSame($el, $locations[0]->range->end->line);
        self::assertSame($ec + 3, $locations[0]->range->end->character);
    }

    /**
     * @return list<Location>
     */
    private function callAtNeedle(
        XphpImplementationHandler $handler,
        PhpactorWorkspace $workspace,
        string $uri,
        string $needle,
        int $offsetInNeedle,
    ): array {
        $source = $workspace->get($uri)->text;
        $byte = strpos($source, $needle);
        self::assertNotFalse($byte, "needle '$needle' must appear in $uri");
        $byte += $offsetInNeedle;
        [$line, $character] = (new PositionMap($source))->offsetToPosition($byte);
        $params = new ImplementationParams(
            new TextDocumentIdentifier($uri),
            new Position($line, $character),
        );
        $result = wait($handler->implementation($params));
        self::assertIsArray($result);
        return $result;
    }

    private function handler(PhpactorWorkspace $workspace): XphpImplementationHandler
    {
        $parser = new XphpSourceParser((new ParserFactory())->createForHostVersion());
        $cache = new ParsedDocumentCache(new Analyzer($parser));
        $fqnIndex = new FqnIndex($workspace, $cache, $parser, $this->root);
        return new XphpImplementationHandler($workspace, $cache, $parser, $fqnIndex);
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
