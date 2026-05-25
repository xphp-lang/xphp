<?php

declare(strict_types=1);

namespace XPHP\Lsp\Test\Handler;

use PhpParser\ParserFactory;
use Phpactor\LanguageServer\Core\Workspace\Workspace as PhpactorWorkspace;
use Phpactor\LanguageServerProtocol\SymbolInformation;
use Phpactor\LanguageServerProtocol\SymbolKind;
use Phpactor\LanguageServerProtocol\TextDocumentItem;
use Phpactor\LanguageServerProtocol\WorkspaceSymbolParams;
use PHPUnit\Framework\TestCase;
use XPHP\Lsp\Analyzer\Analyzer;
use XPHP\Lsp\Analyzer\ParsedDocumentCache;
use XPHP\Lsp\Handler\XphpWorkspaceSymbolHandler;
use XPHP\Lsp\Reflection\FqnIndex;
use XPHP\Transpiler\Monomorphize\XphpSourceParser;

use function Amp\Promise\wait;

final class XphpWorkspaceSymbolHandlerTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/xphp-ws-sym-' . bin2hex(random_bytes(6));
        mkdir($this->root, 0o755, true);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->root)) {
            $this->rmrf($this->root);
        }
    }

    public function testEmptyQueryReturnsAllDeclarations(): void
    {
        $workspace = new PhpactorWorkspace();
        $workspace->open(new TextDocumentItem('/User.xphp', 'xphp', 1, <<<'XPHP'
        <?php
        namespace App\Models;
        class User {}
        XPHP));
        $workspace->open(new TextDocumentItem('/Repo.xphp', 'xphp', 1, <<<'XPHP'
        <?php
        namespace App\Containers;
        interface Repository<T> {}
        XPHP));

        $results = $this->query($workspace, '');
        $names = array_map(fn (SymbolInformation $s): string => $s->name, $results);
        self::assertContains('User', $names);
        self::assertContains('Repository', $names);
    }

    public function testQueryFiltersBySubstringOnShortName(): void
    {
        $workspace = new PhpactorWorkspace();
        $workspace->open(new TextDocumentItem('/Tag.xphp', 'xphp', 1, "<?php\nnamespace App;\nclass Tag {}\n"));
        $workspace->open(new TextDocumentItem('/Pair.xphp', 'xphp', 1, "<?php\nnamespace App;\nclass Pair {}\n"));
        $workspace->open(new TextDocumentItem('/Repo.xphp', 'xphp', 1, "<?php\nnamespace App;\nclass Repository {}\n"));

        $results = $this->query($workspace, 'tag');
        $names = array_map(fn (SymbolInformation $s): string => $s->name, $results);
        self::assertContains('Tag', $names);
        self::assertNotContains('Pair', $names);
        self::assertNotContains('Repository', $names);
    }

    public function testQueryMatchesCaseInsensitively(): void
    {
        $workspace = new PhpactorWorkspace();
        $workspace->open(new TextDocumentItem('/HTTPClient.xphp', 'xphp', 1, "<?php\nclass HTTPClient {}\n"));

        $results = $this->query($workspace, 'httpc');
        $names = array_map(fn (SymbolInformation $s): string => $s->name, $results);
        self::assertContains('HTTPClient', $names);
    }

    public function testQueryDoesNotMatchAgainstNamespace(): void
    {
        // PhpStorm's "Go to Symbol" popup expects matching on the short
        // name only; if a user types "App" they want classes literally
        // named App*, not every class in the App namespace.  The client
        // re-filters anyway, but our pre-filter should be conservative.
        $workspace = new PhpactorWorkspace();
        $workspace->open(new TextDocumentItem('/User.xphp', 'xphp', 1, "<?php\nnamespace App\\Models;\nclass User {}\n"));

        $results = $this->query($workspace, 'models');
        self::assertSame([], $results);
    }

    public function testEmitsCorrectKindPerClassLikeShape(): void
    {
        $workspace = new PhpactorWorkspace();
        $workspace->open(new TextDocumentItem('/A.xphp', 'xphp', 1, "<?php\nnamespace N;\nclass C {}\ninterface I {}\ntrait Tr {}\nenum E {}\nfunction f() {}\n"));

        $byName = [];
        foreach ($this->query($workspace, '') as $s) {
            $byName[$s->name] = $s;
        }
        self::assertSame(SymbolKind::CLASS_, $byName['C']->kind);
        self::assertSame(SymbolKind::INTERFACE, $byName['I']->kind);
        // LSP has no TRAIT kind; we render with CLASS_.
        self::assertSame(SymbolKind::CLASS_, $byName['Tr']->kind);
        self::assertSame(SymbolKind::ENUM, $byName['E']->kind);
        self::assertSame(SymbolKind::FUNCTION, $byName['f']->kind);
    }

    public function testContainerNameSurfacesNamespace(): void
    {
        $workspace = new PhpactorWorkspace();
        $workspace->open(new TextDocumentItem(
            '/Repo.xphp',
            'xphp',
            1,
            "<?php\nnamespace App\\Containers;\nclass Repository {}\n",
        ));

        $results = $this->query($workspace, 'Repo');
        self::assertCount(1, $results);
        self::assertSame('Repository', $results[0]->name);
        self::assertSame('App\\Containers', $results[0]->containerName);
    }

    public function testLocationPointsAtIdentifierLine(): void
    {
        $workspace = new PhpactorWorkspace();
        $source = "<?php\nnamespace App;\n\nclass Repository {}\n";
        $workspace->open(new TextDocumentItem('/Repo.xphp', 'xphp', 1, $source));

        $results = $this->query($workspace, 'Repo');
        self::assertCount(1, $results);
        $loc = $results[0]->location;
        // `class Repository {}` is on line 3 (0-indexed), `Repository` is
        // at char 6 (after "class ").
        self::assertSame(3, $loc->range->start->line);
        self::assertSame(6, $loc->range->start->character);
        self::assertSame(3, $loc->range->end->line);
        // Range covers the identifier itself.
        self::assertSame(6 + strlen('Repository'), $loc->range->end->character);
    }

    public function testFilesystemDeclarationSurfacedWhenNoOpenDoc(): void
    {
        file_put_contents($this->root . '/Hidden.xphp', <<<'XPHP'
        <?php
        namespace App\Stuff;
        class Hidden {}
        XPHP);

        $workspace = new PhpactorWorkspace();
        $results = $this->query($workspace, 'Hidden');
        self::assertCount(1, $results);
        self::assertSame('Hidden', $results[0]->name);
        self::assertSame('App\\Stuff', $results[0]->containerName);
        // Filesystem-only declarations get a file:// URI.
        self::assertStringStartsWith('file://', $results[0]->location->uri);
    }

    public function testOpenDocBeatsFilesystemOnFqnCollision(): void
    {
        file_put_contents($this->root . '/User.xphp', <<<'XPHP'
        <?php
        namespace App;
        class User {}
        XPHP);

        $workspace = new PhpactorWorkspace();
        $workspace->open(new TextDocumentItem(
            '/edit/User.xphp',
            'xphp',
            7,
            "<?php\nnamespace App;\nclass User {}\n",
        ));

        $results = $this->query($workspace, 'User');
        self::assertCount(1, $results, 'collision must de-dupe to a single hit');
        // Open-doc URI wins; the workspace URI does NOT start with file://.
        self::assertStringStartsNotWith('file://', $results[0]->location->uri);
    }

    public function testClassMemberSuffixIsStripped(): void
    {
        // Phase 3: PhpStorm sends `Class::method` when the user types
        // the qualified form in the symbol popup.  We don't index methods
        // at workspace level so we strip the suffix and return the class
        // match.
        $workspace = new PhpactorWorkspace();
        $workspace->open(new TextDocumentItem('/Box.xphp', 'xphp', 1, "<?php\nnamespace App;\nclass Box {}\n"));

        $names = array_map(
            fn (SymbolInformation $s): string => $s->name,
            $this->query($workspace, 'Box::get'),
        );
        self::assertContains('Box', $names);
    }

    public function testEmptyClassMemberStillStripsAndReturns(): void
    {
        // `Class::` (no member yet -- mid-typing) also resolves to the class.
        $workspace = new PhpactorWorkspace();
        $workspace->open(new TextDocumentItem('/Box.xphp', 'xphp', 1, "<?php\nnamespace App;\nclass Box {}\n"));

        $names = array_map(
            fn (SymbolInformation $s): string => $s->name,
            $this->query($workspace, 'Box::'),
        );
        self::assertContains('Box', $names);
    }

    public function testResultCapPreventsRunawayPayloads(): void
    {
        // Cap is 250 -- emit 260 declarations and assert we cut off.
        $source = "<?php\nnamespace Big;\n";
        for ($i = 0; $i < 260; $i++) {
            $source .= "class C{$i} {}\n";
        }
        $workspace = new PhpactorWorkspace();
        $workspace->open(new TextDocumentItem('/Big.xphp', 'xphp', 1, $source));

        $results = $this->query($workspace, '');
        self::assertCount(250, $results);
    }

    public function testMethodsMapRegistersEndpoint(): void
    {
        $handler = $this->handler(new PhpactorWorkspace());
        self::assertArrayHasKey('workspace/symbol', $handler->methods());
        self::assertSame('symbol', $handler->methods()['workspace/symbol']);
    }

    /**
     * @return list<SymbolInformation>
     */
    private function query(PhpactorWorkspace $workspace, string $q): array
    {
        $handler = $this->handler($workspace);
        $params = new WorkspaceSymbolParams($q);
        $result = wait($handler->symbol($params));
        self::assertIsArray($result);
        return $result;
    }

    private function handler(PhpactorWorkspace $workspace): XphpWorkspaceSymbolHandler
    {
        $parser = new XphpSourceParser((new ParserFactory())->createForHostVersion());
        $cache = new ParsedDocumentCache(new Analyzer($parser));
        $index = new FqnIndex($workspace, $cache, $parser, $this->root);
        return new XphpWorkspaceSymbolHandler($index);
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
