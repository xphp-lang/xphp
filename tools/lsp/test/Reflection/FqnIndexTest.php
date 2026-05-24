<?php

declare(strict_types=1);

namespace XPHP\Lsp\Test\Reflection;

use Phpactor\LanguageServer\Core\Workspace\Workspace as PhpactorWorkspace;
use Phpactor\LanguageServerProtocol\TextDocumentItem;
use PhpParser\ParserFactory;
use PHPUnit\Framework\TestCase;
use XPHP\Lsp\Analyzer\Analyzer;
use XPHP\Lsp\Analyzer\ParsedDocumentCache;
use XPHP\Lsp\Reflection\FqnIndex;
use XPHP\Transpiler\Monomorphize\XphpSourceParser;

final class FqnIndexTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/xphp-fqn-' . bin2hex(random_bytes(6));
        mkdir($this->root, 0o755, true);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->root)) {
            $this->rmrf($this->root);
        }
    }

    public function testResolvesOpenDocumentDeclarationsByFqn(): void
    {
        $workspace = new PhpactorWorkspace();
        $workspace->open(new TextDocumentItem(
            'file:///workspace/Collection.xphp',
            'xphp',
            1,
            "<?php\nnamespace App\\Containers;\nclass Collection<T> {}\n",
        ));
        $index = $this->index($workspace);

        self::assertSame('file:///workspace/Collection.xphp', $index->pathFor('App\\Containers\\Collection'));
        self::assertContains('App\\Containers\\Collection', $index->allClassFqns());
    }

    public function testResolvesFilesystemDeclarationsByFqn(): void
    {
        $this->writeFile('App/Models/User.xphp', "<?php\nnamespace App\\Models;\nclass User {}\n");
        $index = $this->index(new PhpactorWorkspace());

        self::assertStringEndsWith('App/Models/User.xphp', $index->pathFor('App\\Models\\User'));
        self::assertContains('App\\Models\\User', $index->allClassFqns());
    }

    public function testOpenDocWinsOverFilesystemOnCollidingFqn(): void
    {
        // Same FQN on disk and in open editor -- live editor view wins.
        $this->writeFile('Collection.xphp', "<?php\nnamespace App\\Containers;\nclass Collection {}\n");
        $workspace = new PhpactorWorkspace();
        $workspace->open(new TextDocumentItem(
            'file:///workspace/Collection.xphp',
            'xphp',
            1,
            "<?php\nnamespace App\\Containers;\nclass Collection<T> {}\n",
        ));
        $index = $this->index($workspace);

        self::assertSame(
            'file:///workspace/Collection.xphp',
            $index->pathFor('App\\Containers\\Collection'),
        );
    }

    public function testClassLikeForReturnsAstWithXphpAttributesFromFilesystem(): void
    {
        // Critical for GenericResolver: the ClassLike we hand back from a
        // closed file MUST still carry ATTR_GENERIC_PARAMS so type-arg
        // substitution works without re-parsing.
        $this->writeFile('Collection.xphp', <<<'XPHP'
        <?php
        namespace App\Containers;
        class Collection<T> {
            public function first(): ?T { return null; }
        }
        XPHP);
        $index = $this->index(new PhpactorWorkspace());

        $class = $index->classLikeFor('App\\Containers\\Collection');

        self::assertNotNull($class);
        self::assertSame('Collection', $class->name?->toString());
        $params = $class->getAttribute(XphpSourceParser::ATTR_GENERIC_PARAMS);
        self::assertIsArray($params);
        self::assertCount(1, $params);
        self::assertSame('T', $params[0]->name);
    }

    public function testClassLikeForReturnsAstFromOpenDocument(): void
    {
        $workspace = new PhpactorWorkspace();
        $workspace->open(new TextDocumentItem(
            'file:///workspace/Pair.xphp',
            'xphp',
            1,
            "<?php\nnamespace App\\Containers;\nclass Pair<K, V> { public function key(): K { return null; } }\n",
        ));
        $index = $this->index($workspace);

        $class = $index->classLikeFor('App\\Containers\\Pair');

        self::assertNotNull($class);
        $params = $class->getAttribute(XphpSourceParser::ATTR_GENERIC_PARAMS);
        self::assertCount(2, $params);
        self::assertSame('K', $params[0]->name);
        self::assertSame('V', $params[1]->name);
    }

    public function testFunctionFqnsTracked(): void
    {
        $this->writeFile('helpers.xphp', "<?php\nnamespace App;\nfunction greet(string \$n): string { return \$n; }\n");
        $workspace = new PhpactorWorkspace();
        $workspace->open(new TextDocumentItem(
            'file:///fn.xphp',
            'xphp',
            1,
            "<?php\nfunction global_fn(): void {}\n",
        ));
        $index = $this->index($workspace);

        $functions = $index->allFunctionFqns();

        self::assertContains('App\\greet', $functions, 'filesystem function must be indexed');
        self::assertContains('global_fn', $functions, 'open-doc function must be indexed');
        // Class declarations don't leak into the function list.
        self::assertNotContains('App\\greet', $index->allClassFqns());
    }

    public function testReturnsNullForUnknownFqn(): void
    {
        $index = $this->index(new PhpactorWorkspace());

        self::assertNull($index->pathFor('Mystery\\Class'));
        self::assertNull($index->classLikeFor('Mystery\\Class'));
    }

    public function testEmptyFqnReturnsNullEarly(): void
    {
        $index = $this->index(new PhpactorWorkspace());

        self::assertNull($index->pathFor(''));
        self::assertNull($index->classLikeFor(''));
    }

    public function testMissingRootPathReturnsEmptyFilesystemSide(): void
    {
        $parser = new XphpSourceParser((new ParserFactory())->createForHostVersion());
        $cache = new ParsedDocumentCache(new Analyzer($parser));
        $index = new FqnIndex(
            new PhpactorWorkspace(),
            $cache,
            $parser,
            '/path/that/definitely/does/not/exist',
        );

        self::assertSame([], $index->allClassFqns());
        self::assertSame([], $index->allFunctionFqns());
    }

    public function testSkipsExcludedDirectories(): void
    {
        // Files under skipped dirs must NOT appear in the index.
        $this->writeFile('vendor/should-skip.xphp', "<?php\nclass VendorClass {}\n");
        $this->writeFile('node_modules/should-skip.xphp', "<?php\nclass NodeClass {}\n");
        $this->writeFile('legit.xphp', "<?php\nclass LegitClass {}\n");

        $index = $this->index(new PhpactorWorkspace());

        self::assertContains('LegitClass', $index->allClassFqns());
        self::assertNotContains('VendorClass', $index->allClassFqns());
        self::assertNotContains('NodeClass', $index->allClassFqns());
    }

    public function testLocationForFqnPointsAtIdentifierInOpenDoc(): void
    {
        $workspace = new PhpactorWorkspace();
        $workspace->open(new TextDocumentItem(
            '/User.xphp',
            'xphp',
            1,
            "<?php\nnamespace App\\Models;\n\nclass User {}\n",
        ));
        $index = $this->index($workspace);

        $hit = $index->locationForFqn('App\\Models\\User');

        self::assertNotNull($hit);
        self::assertSame('/User.xphp', $hit['uri']);
        self::assertSame(3, $hit['line']);
        self::assertSame(6, $hit['char']);
        self::assertSame('User', $hit['short']);
    }

    public function testLocationForFqnFallsThroughToFilesystem(): void
    {
        $this->writeFile('Box.xphp', "<?php\nnamespace App\\Containers;\nclass Box<T> {}\n");
        $index = $this->index(new PhpactorWorkspace());

        $hit = $index->locationForFqn('App\\Containers\\Box');

        self::assertNotNull($hit);
        self::assertSame('file://' . $this->root . '/Box.xphp', $hit['uri']);
        // class Box<T> is on line 2 (0-indexed); `Box` is at char 6.
        self::assertSame(2, $hit['line']);
        self::assertSame(6, $hit['char']);
    }

    public function testLocationByShortNameFallsThroughToFilesystem(): void
    {
        $this->writeFile('User.xphp', "<?php\nnamespace App\\Models;\nclass User {}\n");
        $index = $this->index(new PhpactorWorkspace());

        $hit = $index->locationByShortName('User');

        self::assertNotNull($hit);
        self::assertSame('User', $hit['short']);
        self::assertSame('file://' . $this->root . '/User.xphp', $hit['uri']);
    }

    public function testLocationByShortNameMatchesUnnamespacedClass(): void
    {
        // A class declared outside any namespace: FQN == short name.  The
        // tail-suffix matcher would skip this since there's no `\<short>`
        // suffix to find; the explicit equality branch covers it.
        $this->writeFile('Bare.xphp', "<?php\nclass Bare {}\n");
        $index = $this->index(new PhpactorWorkspace());

        $hit = $index->locationByShortName('Bare');

        self::assertNotNull($hit);
        self::assertSame('Bare', $hit['short']);
    }

    public function testLocationByShortNameReturnsNullForUnknown(): void
    {
        $index = $this->index(new PhpactorWorkspace());
        self::assertNull($index->locationByShortName('NeverDeclared'));
    }

    public function testLocationForFqnReturnsNullForEmptyOrUnknown(): void
    {
        $index = $this->index(new PhpactorWorkspace());
        self::assertNull($index->locationForFqn(''));
        self::assertNull($index->locationForFqn('\\'));
        self::assertNull($index->locationForFqn('Nope\\Mystery'));
    }

    public function testInvalidateFilesystemForcesRebuildOnNextQuery(): void
    {
        $this->writeFile('Alpha.xphp', "<?php\nnamespace App;\nclass Alpha {}\n");
        $index = $this->index(new PhpactorWorkspace());

        // Warm the cache.
        $first = $index->allClassFqns();
        self::assertContains('App\\Alpha', $first);

        // Add a file post-warming.  The cached map doesn't know about it.
        $this->writeFile('Beta.xphp', "<?php\nnamespace App;\nclass Beta {}\n");
        self::assertNotContains('App\\Beta', $index->allClassFqns());

        // Invalidate -- next query re-walks.
        $index->invalidateFilesystem();
        $after = $index->allClassFqns();
        self::assertContains('App\\Beta', $after);
        self::assertContains('App\\Alpha', $after);
    }

    public function testHandlesUnparseableFilesGracefully(): void
    {
        // A garbage file shouldn't blow up the whole index build.
        $this->writeFile('garbage.xphp', "<?php\nthis is not valid php at all{{{");
        $this->writeFile('ok.xphp', "<?php\nnamespace App;\nclass Ok {}\n");

        $index = $this->index(new PhpactorWorkspace());

        self::assertContains('App\\Ok', $index->allClassFqns());
    }

    private function index(PhpactorWorkspace $workspace): FqnIndex
    {
        $parser = new XphpSourceParser((new ParserFactory())->createForHostVersion());
        $cache = new ParsedDocumentCache(new Analyzer($parser));
        return new FqnIndex($workspace, $cache, $parser, $this->root);
    }

    private function writeFile(string $relativePath, string $contents): void
    {
        $path = $this->root . '/' . $relativePath;
        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0o755, true);
        }
        file_put_contents($path, $contents);
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
