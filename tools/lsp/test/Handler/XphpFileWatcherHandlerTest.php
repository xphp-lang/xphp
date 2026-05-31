<?php

declare(strict_types=1);

namespace XPHP\Lsp\Test\Handler;

use PhpParser\ParserFactory;
use Phpactor\LanguageServer\Core\Workspace\Workspace as PhpactorWorkspace;
use Phpactor\LanguageServerProtocol\DidChangeWatchedFilesParams;
use Phpactor\LanguageServerProtocol\FileChangeType;
use Phpactor\LanguageServerProtocol\FileEvent;
use Phpactor\LanguageServerProtocol\TextDocumentItem;
use PHPUnit\Framework\TestCase;
use XPHP\Lsp\Analyzer\Analyzer;
use XPHP\Lsp\Analyzer\ParsedDocumentCache;
use XPHP\Lsp\Handler\XphpFileWatcherHandler;
use XPHP\Lsp\Reflection\FqnIndex;
use XPHP\Transpiler\Monomorphize\XphpSourceParser;

use function Amp\Promise\wait;

final class XphpFileWatcherHandlerTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/xphp-watch-' . bin2hex(random_bytes(6));
        mkdir($this->root, 0o755, true);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->root)) {
            $this->rmrf($this->root);
        }
    }

    public function testMethodsMapRegistersWatchedFilesNotification(): void
    {
        $handler = new XphpFileWatcherHandler($this->index(), new PhpactorWorkspace(), $this->cache());
        self::assertArrayHasKey('workspace/didChangeWatchedFiles', $handler->methods());
        self::assertSame('didChangeWatchedFiles', $handler->methods()['workspace/didChangeWatchedFiles']);
    }

    public function testFsChangeForcesRescanSoNewlyAddedClassSurfaces(): void
    {
        // Initial state: one file on disk.
        file_put_contents($this->root . '/Alpha.xphp', "<?php\nnamespace App;\nclass Alpha {}\n");
        $index = $this->index();
        self::assertContains('App\\Alpha', $index->allClassFqns());

        // Add a second file -- the index hasn't seen it yet.
        file_put_contents($this->root . '/Beta.xphp', "<?php\nnamespace App;\nclass Beta {}\n");
        // Without invalidation, the cached filesystem map still doesn't know about Beta.
        self::assertNotContains(
            'App\\Beta',
            $index->allClassFqns(),
            'pre-invalidation: stale cache should still NOT see Beta',
        );

        // Fire the notification -- forces a rewalk on next query.
        $handler = new XphpFileWatcherHandler($index, new PhpactorWorkspace(), $this->cache());
        $params = new DidChangeWatchedFilesParams([
            new FileEvent('file://' . $this->root . '/Beta.xphp', FileChangeType::CREATED),
        ]);
        $result = wait($handler->didChangeWatchedFiles($params));
        self::assertNull($result);

        // Post-invalidation: next query picks up Beta.
        self::assertContains('App\\Beta', $index->allClassFqns());
        // Alpha still there (the rewalk doesn't lose old entries).
        self::assertContains('App\\Alpha', $index->allClassFqns());
    }

    public function testFsDeletionRemovesEntryAfterInvalidation(): void
    {
        // Two files initially.
        file_put_contents($this->root . '/Keep.xphp', "<?php\nnamespace App;\nclass Keep {}\n");
        file_put_contents($this->root . '/Gone.xphp', "<?php\nnamespace App;\nclass Gone {}\n");
        $index = $this->index();
        self::assertContains('App\\Gone', $index->allClassFqns());

        unlink($this->root . '/Gone.xphp');
        // Still cached.
        self::assertContains('App\\Gone', $index->allClassFqns());

        $handler = new XphpFileWatcherHandler($index, new PhpactorWorkspace(), $this->cache());
        wait($handler->didChangeWatchedFiles(new DidChangeWatchedFilesParams([
            new FileEvent('file://' . $this->root . '/Gone.xphp', FileChangeType::DELETED),
        ])));

        // After rewalk, Gone is gone.
        self::assertNotContains('App\\Gone', $index->allClassFqns());
        self::assertContains('App\\Keep', $index->allClassFqns());
    }

    public function testChangedNotificationForOpenDocSkipsInvalidation(): void
    {
        // The save-of-open-file double-invalidation case from prod logs:
        // textDocument/didChange already refreshed the open-doc layer;
        // workspace/didChangeWatchedFiles for the SAME file is redundant
        // and would force a multi-second filesystem rebuild on the next
        // hover.  Verify the handler skips invalidation when the file
        // is open in the workspace.
        file_put_contents($this->root . '/Open.xphp', "<?php\nnamespace App;\nclass Open {}\n");
        $workspace = new PhpactorWorkspace();
        $uri = 'file://' . $this->root . '/Open.xphp';
        $workspace->open(new TextDocumentItem($uri, 'xphp', 1, "<?php\nnamespace App;\nclass Open {}\n"));

        $index = $this->index();
        $index->allClassFqns(); // warm the cache

        // Externally mutate the file -- but the open-doc layer already
        // has its own copy.  The watcher should skip invalidation.
        file_put_contents($this->root . '/Open.xphp', "<?php\nnamespace App;\nclass Renamed {}\n");
        $handler = new XphpFileWatcherHandler($index, $workspace, $this->cache());
        wait($handler->didChangeWatchedFiles(new DidChangeWatchedFilesParams([
            new FileEvent($uri, FileChangeType::CHANGED),
        ])));

        // Cache is still warm -- `Open` survives because invalidation
        // was skipped.  This is the load-bearing behaviour for fix #4:
        // saves of open files don't pay the rebuild cost.
        self::assertContains('App\\Open', $index->allClassFqns());
    }

    public function testCreatedForOpenDocStillInvalidates(): void
    {
        // CREATED notifications are external by definition (the file
        // didn't exist before).  Even if the URI happens to overlap
        // with a workspace doc, we still invalidate -- the directory
        // listing has changed and other queries (workspace symbols,
        // closed-file GTD) depend on it.
        file_put_contents($this->root . '/Old.xphp', "<?php\nnamespace App;\nclass Old {}\n");
        $workspace = new PhpactorWorkspace();
        $uri = 'file://' . $this->root . '/New.xphp';

        $index = $this->index();
        $index->allClassFqns(); // warm cache

        file_put_contents($this->root . '/New.xphp', "<?php\nnamespace App;\nclass New_ {}\n");
        $handler = new XphpFileWatcherHandler($index, $workspace, $this->cache());
        wait($handler->didChangeWatchedFiles(new DidChangeWatchedFilesParams([
            new FileEvent($uri, FileChangeType::CREATED),
        ])));

        self::assertContains('App\\New_', $index->allClassFqns());
    }

    public function testMixedOpenAndExternalChangesStillInvalidateOnTheExternalOne(): void
    {
        // The `continue` in the changes-loop after a skipped open-doc
        // entry must let the loop keep iterating -- otherwise a
        // following external change in the same notification batch
        // would be lost and the index/cache wouldn't refresh.  This
        // test deliberately interleaves [CHANGED(open), CREATED(ext)]
        // so a `break` mutant would surface as the external change
        // never being applied: the new file's class wouldn't appear in
        // the post-rescan index.
        file_put_contents($this->root . '/Open.xphp', "<?php\nnamespace App;\nclass Open {}\n");
        $workspace = new PhpactorWorkspace();
        $openUri = 'file://' . $this->root . '/Open.xphp';
        $workspace->open(new TextDocumentItem($openUri, 'xphp', 1, "<?php\nnamespace App;\nclass Open {}\n"));

        $index = $this->index();
        $index->allClassFqns(); // warm

        // Create the external file AFTER the index is warm so the
        // rescan is what surfaces it.
        file_put_contents($this->root . '/NewExt.xphp', "<?php\nnamespace App;\nclass NewExt {}\n");
        $handler = new XphpFileWatcherHandler($index, $workspace, $this->cache());
        wait($handler->didChangeWatchedFiles(new DidChangeWatchedFilesParams([
            new FileEvent($openUri, FileChangeType::CHANGED),
            new FileEvent('file://' . $this->root . '/NewExt.xphp', FileChangeType::CREATED),
        ])));

        self::assertContains(
            'App\\NewExt',
            $index->allClassFqns(),
            'external change must still surface even when it follows a skipped open-doc change',
        );
    }

    public function testEmptyChangesArrayDoesNotInvalidate(): void
    {
        // Defensive: zero-change notifications shouldn't trigger a needless
        // rescan.  Some clients may forward debounce flushes as empty
        // payloads; we shouldn't pay the walk cost for those.
        file_put_contents($this->root . '/A.xphp', "<?php\nnamespace App;\nclass A {}\n");
        $index = $this->index();
        // Warm the cache.
        $index->allClassFqns();
        // Add a new file after warming -- it should NOT surface after a
        // no-op notification.
        file_put_contents($this->root . '/B.xphp', "<?php\nnamespace App;\nclass B {}\n");

        $handler = new XphpFileWatcherHandler($index, new PhpactorWorkspace(), $this->cache());
        wait($handler->didChangeWatchedFiles(new DidChangeWatchedFilesParams([])));

        self::assertNotContains('App\\B', $index->allClassFqns());
    }

    public function testExternalChangeDropsWarmedAstCacheEntries(): void
    {
        // Perf #1 cache-invalidation pair: when a filesystem-only change
        // arrives, the FQN index AND the ParsedDocumentCache's warmed
        // (version-0) entries must both be dropped.  Without dropping
        // the cache, ReferenceFinder's filesystem pass would keep
        // serving the pre-change AST and miss new references / surface
        // dead ones.
        file_put_contents($this->root . '/Open.xphp', "<?php\nnamespace App;\nclass Open {}\n");
        file_put_contents($this->root . '/Changed.xphp', "<?php\nnamespace App;\nclass Changed {}\n");

        $workspace = new PhpactorWorkspace();
        $openUri = 'file://' . $this->root . '/Open.xphp';
        $workspace->open(new TextDocumentItem($openUri, 'xphp', 1, "<?php\nnamespace App;\nclass Open {}\n"));

        $cache = $this->cache();
        // Two warmer-seeded entries plus one open-doc entry.
        $cache->seedIfAbsent($openUri, "<?php\nnamespace App;\nclass Open {}\n");
        $cache->seedIfAbsent('file://' . $this->root . '/Changed.xphp', "<?php\nnamespace App;\nclass Changed {}\n");
        $cache->getOrParse($openUri, 1, "<?php\nnamespace App;\nclass Open {}\n");

        $index = $this->index();
        $handler = new XphpFileWatcherHandler($index, $workspace, $cache);
        wait($handler->didChangeWatchedFiles(new DidChangeWatchedFilesParams([
            new FileEvent('file://' . $this->root . '/Changed.xphp', FileChangeType::CHANGED),
        ])));

        // Warmer-seeded entry for the changed file is gone.  The other
        // version-0 entry (Open) is also gone -- forgetFilesystem is
        // bulk by design, matching FqnIndex::invalidateFilesystem's
        // bulk strategy.
        self::assertNull($cache->peek('file://' . $this->root . '/Changed.xphp'));

        // Open-doc entry (version 1) survives.
        self::assertNotNull($cache->peek($openUri));
    }

    public function testOpenDocOnlyChangeLeavesCacheAlone(): void
    {
        // When the only change is for an open doc, we skip invalidation
        // entirely (FqnIndex AND ParsedDocumentCache both stay warm).
        // The cache invalidation must NOT fire on this branch -- it'd
        // pessimise the common save-of-open-file case Phase 2.4 already
        // optimised for.
        file_put_contents($this->root . '/Open.xphp', "<?php\nnamespace App;\nclass Open {}\n");
        $workspace = new PhpactorWorkspace();
        $openUri = 'file://' . $this->root . '/Open.xphp';
        $workspace->open(new TextDocumentItem($openUri, 'xphp', 1, "<?php\nnamespace App;\nclass Open {}\n"));

        $cache = $this->cache();
        // Pre-seed a warmer entry for a different file.
        $cache->seedIfAbsent('file://' . $this->root . '/Other.xphp', "<?php\nnamespace App;\nclass Other {}\n");

        $index = $this->index();
        $handler = new XphpFileWatcherHandler($index, $workspace, $cache);
        wait($handler->didChangeWatchedFiles(new DidChangeWatchedFilesParams([
            new FileEvent($openUri, FileChangeType::CHANGED),
        ])));

        // Other's warmed entry is still there -- the skip-invalidation
        // branch did NOT call forgetFilesystem.
        self::assertNotNull($cache->peek('file://' . $this->root . '/Other.xphp'));
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
