<?php

declare(strict_types=1);

namespace XPHP\Lsp\Test\Handler;

use PhpParser\ParserFactory;
use Phpactor\LanguageServer\Core\Workspace\Workspace as PhpactorWorkspace;
use Phpactor\LanguageServerProtocol\DidChangeWatchedFilesParams;
use Phpactor\LanguageServerProtocol\FileChangeType;
use Phpactor\LanguageServerProtocol\FileEvent;
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
        $handler = new XphpFileWatcherHandler($this->index());
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
        $handler = new XphpFileWatcherHandler($index);
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

        $handler = new XphpFileWatcherHandler($index);
        wait($handler->didChangeWatchedFiles(new DidChangeWatchedFilesParams([
            new FileEvent('file://' . $this->root . '/Gone.xphp', FileChangeType::DELETED),
        ])));

        // After rewalk, Gone is gone.
        self::assertNotContains('App\\Gone', $index->allClassFqns());
        self::assertContains('App\\Keep', $index->allClassFqns());
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

        $handler = new XphpFileWatcherHandler($index);
        wait($handler->didChangeWatchedFiles(new DidChangeWatchedFilesParams([])));

        self::assertNotContains('App\\B', $index->allClassFqns());
    }

    private function index(): FqnIndex
    {
        $parser = new XphpSourceParser((new ParserFactory())->createForHostVersion());
        $cache = new ParsedDocumentCache(new Analyzer($parser));
        return new FqnIndex(new PhpactorWorkspace(), $cache, $parser, $this->root);
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
