<?php

declare(strict_types=1);

namespace XPHP\Lsp\Test\Handler;

use PhpParser\ParserFactory;
use Phpactor\LanguageServer\Core\Workspace\Workspace as PhpactorWorkspace;
use Phpactor\LanguageServerProtocol\Location;
use Phpactor\LanguageServerProtocol\Position;
use Phpactor\LanguageServerProtocol\ReferenceContext;
use Phpactor\LanguageServerProtocol\ReferenceParams;
use Phpactor\LanguageServerProtocol\TextDocumentIdentifier;
use Phpactor\LanguageServerProtocol\TextDocumentItem;
use PHPUnit\Framework\TestCase;
use XPHP\Lsp\Analyzer\Analyzer;
use XPHP\Lsp\Analyzer\ParsedDocumentCache;
use XPHP\Lsp\Handler\XphpReferencesHandler;
use XPHP\Lsp\PositionMap;
use XPHP\Lsp\Reflection\FqnIndex;
use XPHP\Lsp\Resolver\ReferenceFinder;
use XPHP\Transpiler\Monomorphize\XphpSourceParser;

use function Amp\Promise\wait;

final class XphpReferencesHandlerTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/xphp-refs-' . bin2hex(random_bytes(6));
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
        self::assertArrayHasKey('textDocument/references', $handler->methods());
        self::assertSame('references', $handler->methods()['textDocument/references']);
    }

    public function testFindsClassReferencesAcrossOpenDocs(): void
    {
        $workspace = new PhpactorWorkspace();
        $workspace->open(new TextDocumentItem('/User.xphp', 'xphp', 1, <<<'XPHP'
        <?php
        namespace App;
        class User {}
        XPHP));
        $workspace->open(new TextDocumentItem('/Use1.xphp', 'xphp', 1, <<<'XPHP'
        <?php
        namespace App\Other;
        use App\User;
        $u = new User();
        XPHP));
        $workspace->open(new TextDocumentItem('/Use2.xphp', 'xphp', 1, <<<'XPHP'
        <?php
        namespace App\Other;
        function f(\App\User $u): void {}
        XPHP));

        $locations = $this->references($workspace, '/User.xphp', 'class User', strlen('class '));

        $uris = array_map(fn (Location $l): string => $l->uri, $locations);
        // Four matches: declaration + `use App\User;` import + `new User()` + `\App\User` type hint.
        // The `use` statement IS counted as a usage -- PhpStorm's Find
        // Usages groups them under "imports" but does surface them.
        self::assertCount(4, $locations);
        self::assertContains('/User.xphp', $uris);
        self::assertContains('/Use1.xphp', $uris);
        self::assertContains('/Use2.xphp', $uris);
    }

    public function testIncludeDeclarationFalseDropsDeclSite(): void
    {
        $workspace = new PhpactorWorkspace();
        $workspace->open(new TextDocumentItem('/User.xphp', 'xphp', 1, "<?php\nnamespace App;\nclass User {}\n"));
        $workspace->open(new TextDocumentItem('/Use.xphp', 'xphp', 1, "<?php\nnamespace X;\nuse App\\User;\n\$u = new User();\n"));

        $locations = $this->references(
            $workspace,
            '/User.xphp',
            'class User',
            strlen('class '),
            includeDeclaration: false,
        );

        $uris = array_map(fn (Location $l): string => $l->uri, $locations);
        // 2 matches in /Use.xphp (the `use App\User;` import + `new User()`);
        // the declaration in /User.xphp is dropped by includeDeclaration=false.
        self::assertCount(2, $locations);
        foreach ($uris as $u) {
            self::assertSame('/Use.xphp', $u);
        }
    }

    public function testFindsClassReferencesFromUseCaseInsteadOfDeclaration(): void
    {
        // Cursor on a USE of the class -- should still find every other
        // use of it, not just the cursor's own line.
        $workspace = new PhpactorWorkspace();
        $workspace->open(new TextDocumentItem('/User.xphp', 'xphp', 1, "<?php\nnamespace App;\nclass User {}\n"));
        $workspace->open(new TextDocumentItem('/Use1.xphp', 'xphp', 1, "<?php\nnamespace X;\nuse App\\User;\n\$u = new User();\n"));
        $workspace->open(new TextDocumentItem('/Use2.xphp', 'xphp', 1, "<?php\nuse App\\User;\n\$u = new User();\n"));

        $locations = $this->references($workspace, '/Use1.xphp', 'new User()', strlen('new '));

        $uris = array_map(fn (Location $l): string => $l->uri, $locations);
        // All three files contain App\User -- decl + two uses (each Use*
        // contains one `new User()` reference).
        sort($uris);
        self::assertContains('/User.xphp', $uris);
        self::assertContains('/Use1.xphp', $uris);
        self::assertContains('/Use2.xphp', $uris);
    }

    public function testFindsFunctionReferences(): void
    {
        $workspace = new PhpactorWorkspace();
        $workspace->open(new TextDocumentItem('/lib.xphp', 'xphp', 1, "<?php\nnamespace App;\nfunction identity(\$x) { return \$x; }\n"));
        $workspace->open(new TextDocumentItem('/use.xphp', 'xphp', 1, "<?php\nuse function App\\identity;\nidentity(1);\nidentity(2);\n"));

        $locations = $this->references($workspace, '/lib.xphp', 'function identity', strlen('function '));

        $uris = array_map(fn (Location $l): string => $l->uri, $locations);
        // Declaration + two calls in use.xphp.
        self::assertCount(3, $locations);
        self::assertContains('/lib.xphp', $uris);
        self::assertContains('/use.xphp', $uris);
    }

    public function testFindsReferencesAcrossFilesystem(): void
    {
        file_put_contents($this->root . '/User.xphp', "<?php\nnamespace App;\nclass User {}\n");
        file_put_contents($this->root . '/Consumer.xphp', "<?php\nnamespace App\\X;\nuse App\\User;\n\$u = new User();\n");

        $workspace = new PhpactorWorkspace();
        // Only ONE doc is open -- the cursor sits in it.  The other
        // reference lives on disk only.  Find Usages should still see it.
        $workspace->open(new TextDocumentItem($this->root . '/User.xphp', 'xphp', 1, file_get_contents($this->root . '/User.xphp')));

        $locations = $this->references(
            $workspace,
            $this->root . '/User.xphp',
            'class User',
            strlen('class '),
        );

        $uris = array_map(fn (Location $l): string => $l->uri, $locations);
        self::assertContains($this->root . '/User.xphp', $uris, 'declaration site');
        self::assertContains('file://' . $this->root . '/Consumer.xphp', $uris, 'unopened filesystem reference');
    }

    public function testInstanceofExtendsImplementsAllSurfaceAsClassRefs(): void
    {
        $workspace = new PhpactorWorkspace();
        $workspace->open(new TextDocumentItem('/Base.xphp', 'xphp', 1, "<?php\nnamespace App;\nclass Base {}\ninterface IBase {}\n"));
        $workspace->open(new TextDocumentItem('/Child.xphp', 'xphp', 1, "<?php\nnamespace App;\nclass Child extends Base implements IBase {\n    public function f(\$x): bool { return \$x instanceof Base; }\n}\n"));

        $locations = $this->references($workspace, '/Base.xphp', 'class Base', strlen('class '));
        $uris = array_map(fn (Location $l): string => $l->uri, $locations);

        // Declaration + extends Base + instanceof Base.
        self::assertCount(3, $locations);
        self::assertContains('/Base.xphp', $uris);
        self::assertContains('/Child.xphp', $uris);
        $childMatches = array_filter($locations, fn ($l) => $l->uri === '/Child.xphp');
        self::assertCount(2, $childMatches, 'extends + instanceof both count');
    }

    public function testEmptyResultWhenCursorIsNotOnReferenceableSymbol(): void
    {
        $workspace = new PhpactorWorkspace();
        $workspace->open(new TextDocumentItem('/x.xphp', 'xphp', 1, "<?php\n\$x = 42;\n"));

        // Cursor on `42`.
        $locations = $this->references($workspace, '/x.xphp', '42', 0);
        self::assertSame([], $locations);
    }

    public function testEmptyResultForUnknownUri(): void
    {
        $handler = $this->handler(new PhpactorWorkspace());
        $params = new ReferenceParams(
            new ReferenceContext(true),
            new TextDocumentIdentifier('/never-opened.xphp'),
            new Position(0, 0),
        );
        self::assertSame([], wait($handler->references($params)));
    }

    /**
     * @return list<Location>
     */
    private function references(
        PhpactorWorkspace $workspace,
        string $uri,
        string $needle,
        int $offsetInNeedle,
        bool $includeDeclaration = true,
    ): array {
        $item = $workspace->get($uri);
        $byte = strpos($item->text, $needle);
        self::assertNotFalse($byte, "needle '$needle' must appear in $uri");
        $byte += $offsetInNeedle;
        [$line, $character] = (new PositionMap($item->text))->offsetToPosition($byte);
        $params = new ReferenceParams(
            new ReferenceContext($includeDeclaration),
            new TextDocumentIdentifier($uri),
            new Position($line, $character),
        );
        $result = wait($this->handler($workspace)->references($params));
        self::assertIsArray($result);
        return $result;
    }

    private function handler(PhpactorWorkspace $workspace): XphpReferencesHandler
    {
        $parser = new XphpSourceParser((new ParserFactory())->createForHostVersion());
        $cache = new ParsedDocumentCache(new Analyzer($parser));
        $fqnIndex = new FqnIndex($workspace, $cache, $parser, $this->root);
        return new XphpReferencesHandler(
            $workspace,
            new ReferenceFinder($workspace, $cache, $fqnIndex, $parser),
        );
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
