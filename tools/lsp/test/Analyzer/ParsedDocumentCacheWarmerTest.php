<?php

declare(strict_types=1);

namespace XPHP\Lsp\Test\Analyzer;

use PhpParser\ParserFactory;
use Phpactor\LanguageServer\Core\Workspace\Workspace as PhpactorWorkspace;
use Phpactor\LanguageServer\Event\Initialized;
use Phpactor\LanguageServerProtocol\InitializeParams;
use Phpactor\LanguageServerProtocol\ClientCapabilities;
use Phpactor\LanguageServerProtocol\TextDocumentItem;
use PHPUnit\Framework\TestCase;
use XPHP\Lsp\Analyzer\Analyzer;
use XPHP\Lsp\Analyzer\ParsedDocumentCache;
use XPHP\Lsp\Analyzer\ParsedDocumentCacheWarmer;
use XPHP\Lsp\Reflection\FqnIndex;
use XPHP\Transpiler\Monomorphize\XphpSourceParser;

final class ParsedDocumentCacheWarmerTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/xphp-doc-warmer-' . bin2hex(random_bytes(6));
        mkdir($this->root, 0o755, true);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->root)) {
            $this->rmrf($this->root);
        }
    }

    public function testListensOnlyForInitializedEvent(): void
    {
        $warmer = new ParsedDocumentCacheWarmer($this->index(), $this->cache(), new PhpactorWorkspace());

        $listeners = $warmer->getListenersForEvent(new \stdClass());
        self::assertSame([], is_array($listeners) ? $listeners : iterator_to_array($listeners));

        // Initialized event -> exactly one bound `[$warmer, 'warm']`
        // listener.  Same assertion shape as FqnIndexWarmerTest -- catches
        // the ArrayItemRemoval mutant that would silently drop the
        // method binding.
        $listeners = $warmer->getListenersForEvent($this->event());
        $list = is_array($listeners) ? $listeners : iterator_to_array($listeners);
        self::assertCount(1, $list);

        $listener = $list[0];
        self::assertIsArray($listener);
        self::assertCount(2, $listener);
        self::assertSame($warmer, $listener[0]);
        self::assertSame('warm', $listener[1]);
        self::assertTrue(is_callable($listener));
    }

    public function testWarmSeedsEveryFilesystemFileIntoCache(): void
    {
        file_put_contents($this->root . '/Alpha.xphp', "<?php\nnamespace App;\nclass Alpha {}\n");
        file_put_contents($this->root . '/Beta.xphp', "<?php\nnamespace App;\nclass Beta {}\n");

        $index = $this->index();
        $cache = $this->cache();
        $warmer = new ParsedDocumentCacheWarmer($index, $cache, new PhpactorWorkspace());

        $this->runWarmer($warmer);

        $alpha = $cache->peek('file://' . $this->root . '/Alpha.xphp');
        $beta = $cache->peek('file://' . $this->root . '/Beta.xphp');
        self::assertNotNull($alpha, 'Alpha must be cached after warming');
        self::assertNotNull($beta, 'Beta must be cached after warming');
        self::assertNotNull($alpha->ast);
        self::assertNotNull($beta->ast);
    }

    public function testOpenDocSkipBranchAvoidsSeedingFromDiskAndContinuesToNextFile(): void
    {
        // The `continue` after the workspace-check is load-bearing in
        // two ways:
        //
        //   1. Skip the disk-side read+seed for open URIs (otherwise
        //      a stale version-0 entry would land in the cache for
        //      files whose open-doc bytes diverge from disk).
        //   2. Continue iterating remaining paths (not `break`, which
        //      would abandon every subsequent file).
        //
        // Set up two filesystem files: one open in the workspace, one
        // closed.  Open file comes earlier alphabetically so the
        // foreach hits it first.  Assertions:
        //
        //   - Open URI's cache slot stays empty (covers behaviour #1).
        //   - Closed URI's cache slot IS populated (covers behaviour
        //     #2 -- a `break` mutant would skip the closed file too).
        file_put_contents($this->root . '/A_Open.xphp', "<?php\nnamespace App;\nclass DiskCopy {}\n");
        file_put_contents($this->root . '/B_Closed.xphp', "<?php\nnamespace App;\nclass Closed {}\n");
        $openUri = 'file://' . $this->root . '/A_Open.xphp';
        $closedUri = 'file://' . $this->root . '/B_Closed.xphp';

        $workspace = new PhpactorWorkspace();
        $workspace->open(new TextDocumentItem($openUri, 'xphp', 1, "<?php\nnamespace App;\nclass OpenCopy {}\n"));

        $index = $this->index();
        $cache = $this->cache();
        self::assertNull($cache->peek($openUri), 'precondition: cache empty for open URI');
        self::assertNull($cache->peek($closedUri), 'precondition: cache empty for closed URI');

        $warmer = new ParsedDocumentCacheWarmer($index, $cache, $workspace);
        $this->runWarmer($warmer);

        self::assertNull(
            $cache->peek($openUri),
            'open-doc URI must be skipped (no disk seed)',
        );
        self::assertNotNull(
            $cache->peek($closedUri),
            'closed URI must still be seeded -- continue, not break, is correct',
        );
    }

    public function testWarmSkipsFilesAlreadyOpenInWorkspace(): void
    {
        // Disk-side bytes deliberately differ from the open-doc bytes:
        // the workspace MUST win.  If the warmer overwrote, the cache
        // would carry the disk version and a subsequent open-doc lookup
        // would silently serve outdated text until the next didChange.
        file_put_contents($this->root . '/Open.xphp', "<?php\nnamespace App;\nclass Stale {}\n");
        $uri = 'file://' . $this->root . '/Open.xphp';

        $workspace = new PhpactorWorkspace();
        $workspace->open(new TextDocumentItem($uri, 'xphp', 1, "<?php\nnamespace App;\nclass Live {}\n"));

        $index = $this->index();
        $cache = $this->cache();

        // Prime the cache with the live open-doc parse at version 1 --
        // this is what didOpen's path would do.
        $cache->getOrParse($uri, 1, "<?php\nnamespace App;\nclass Live {}\n");

        $warmer = new ParsedDocumentCacheWarmer($index, $cache, $workspace);
        $this->runWarmer($warmer);

        // Cache still serves the live version-1 entry, not version-0
        // from disk.  Confirm by checking that reading at version 1
        // doesn't trigger another parse -- and that the source bytes
        // serialised back from the cached AST match the live text.
        $cached = $cache->peek($uri);
        self::assertNotNull($cached);
        $printer = new \PhpParser\PrettyPrinter\Standard();
        $serialised = $printer->prettyPrintFile($cached->ast ?? []);
        self::assertStringContainsString('class Live', $serialised);
        self::assertStringNotContainsString('class Stale', $serialised);
    }

    public function testWarmIsResilientToUnreadableFiles(): void
    {
        // Index records the path, but the file vanishes before we read
        // it (race between FqnIndex's walk and the asyncCall fire).
        // The warmer must NOT throw; it should bookkeep and continue.
        file_put_contents($this->root . '/A.xphp', "<?php\nnamespace App;\nclass A {}\n");
        file_put_contents($this->root . '/Ghost.xphp', "<?php\nnamespace App;\nclass Ghost {}\n");

        $index = $this->index();
        // Force the index to record both paths.
        $index->indexedFilesystemPaths();

        // Now delete one file -- the path is still in the index, but the
        // file is gone.  The warmer's `@file_get_contents` returns false;
        // the loop must continue to the next path.
        unlink($this->root . '/Ghost.xphp');

        $cache = $this->cache();
        $warmer = new ParsedDocumentCacheWarmer($index, $cache, new PhpactorWorkspace());
        $this->runWarmer($warmer);

        // A is cached; Ghost is silently skipped (no exception bubbled).
        self::assertNotNull($cache->peek('file://' . $this->root . '/A.xphp'));
        self::assertNull($cache->peek('file://' . $this->root . '/Ghost.xphp'));
    }

    private function event(): Initialized
    {
        return new Initialized(new InitializeParams(new ClientCapabilities()));
    }

    private function runWarmer(ParsedDocumentCacheWarmer $warmer): void
    {
        // Call the extracted synchronous body directly.  See the
        // `warmNow` docblock for the race that the asyncCall-wait
        // pattern introduced under Infection.
        $warmer->warmNow();
    }

    private function index(): FqnIndex
    {
        $parser = new XphpSourceParser((new ParserFactory())->createForHostVersion());
        $cache = new ParsedDocumentCache(new Analyzer($parser));
        return new FqnIndex(new PhpactorWorkspace(), $cache, $parser, $this->root);
    }

    private function cache(): ParsedDocumentCache
    {
        $parser = new XphpSourceParser((new ParserFactory())->createForHostVersion());
        return new ParsedDocumentCache(new Analyzer($parser));
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
