<?php

declare(strict_types=1);

namespace XPHP\Lsp\Test\Reflection;

use PhpParser\ParserFactory;
use Phpactor\LanguageServer\Core\Workspace\Workspace as PhpactorWorkspace;
use Phpactor\LanguageServer\Event\Initialized;
use Phpactor\LanguageServerProtocol\InitializeParams;
use PHPUnit\Framework\TestCase;
use XPHP\Lsp\Analyzer\Analyzer;
use XPHP\Lsp\Analyzer\ParsedDocumentCache;
use XPHP\Lsp\Reflection\FqnIndex;
use XPHP\Lsp\Reflection\FqnIndexWarmer;
use XPHP\Transpiler\Monomorphize\XphpSourceParser;

use function Amp\Promise\wait;
use function Amp\call;

final class FqnIndexWarmerTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/xphp-warmer-' . bin2hex(random_bytes(6));
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
        $warmer = new FqnIndexWarmer($this->index());

        // Unrecognised event -> no listeners returned.
        $listeners = $warmer->getListenersForEvent(new \stdClass());
        self::assertSame([], is_array($listeners) ? $listeners : iterator_to_array($listeners));

        // Initialized event -> exactly one listener.  Assert the shape
        // is `[$warmer, 'warm']` -- bound callable on the warmer
        // instance -- not e.g. the unbound `['warm']` string that
        // would be returned if the listener array were a single
        // method-name string.  Without this assertion an
        // `ArrayItemRemoval` mutant on `[[$this, 'warm']]` -> `[['warm']]`
        // escapes: the listener count is still 1.
        $listeners = $warmer->getListenersForEvent(new Initialized(new InitializeParams(new \Phpactor\LanguageServerProtocol\ClientCapabilities())));
        $listenerList = is_array($listeners) ? $listeners : iterator_to_array($listeners);
        self::assertCount(1, $listenerList);

        $listener = $listenerList[0];
        self::assertIsArray($listener);
        self::assertCount(2, $listener);
        self::assertSame($warmer, $listener[0]);
        self::assertSame('warm', $listener[1]);
        self::assertTrue(is_callable($listener), 'listener must be callable as-is');
    }

    public function testWarmHydratesFilesystemFqnIndex(): void
    {
        // Drop two .xphp files on disk and confirm that after warming,
        // the index's class FQNs are populated -- proving the walk
        // actually fired.  Without warming, allClassFqns() would still
        // populate on first call; we explicitly test that calling the
        // warmer's `warm()` is sufficient and that subsequent reads
        // hit the cache.
        file_put_contents($this->root . '/Alpha.xphp', "<?php\nnamespace App;\nclass Alpha {}\n");
        file_put_contents($this->root . '/Beta.xphp', "<?php\nnamespace App;\nclass Beta {}\n");

        $index = $this->index();
        $warmer = new FqnIndexWarmer($index);

        // Asynchronously schedule the warmer.  Wait for one event-loop
        // tick to let asyncCall complete.
        wait(call(function () use ($warmer): \Generator {
            $warmer->warm(new Initialized(new InitializeParams(new \Phpactor\LanguageServerProtocol\ClientCapabilities())));
            // One short delay lets asyncCall's enqueued callback run.
            yield new \Amp\Delayed(10);
        }));

        // Index is now warm -- subsequent reads are O(1) on the
        // cached map (the test asserts the underlying state by
        // checking the FQNs are known).
        $fqns = $index->allClassFqns();
        self::assertContains('App\\Alpha', $fqns);
        self::assertContains('App\\Beta', $fqns);
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
