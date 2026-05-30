<?php

declare(strict_types=1);

namespace XPHP\Lsp\Test\Analyzer;

use PhpParser\ParserFactory;
use PHPUnit\Framework\TestCase;
use XPHP\Lsp\Analyzer\Analyzer;
use XPHP\Lsp\Analyzer\ParsedDocumentCache;
use XPHP\Transpiler\Monomorphize\XphpSourceParser;

final class ParsedDocumentCacheTest extends TestCase
{
    public function testFirstAccessParsesAndCachesTheResult(): void
    {
        $spy = $this->newSpy();
        $cache = new ParsedDocumentCache($spy);

        $cache->getOrParse('/a.xphp', 1, '<?php');
        self::assertSame(1, $spy->callCount, 'first access parses');

        $cache->getOrParse('/a.xphp', 1, '<?php');
        self::assertSame(1, $spy->callCount, 'same version → cached, no second parse');
    }

    public function testVersionBumpInvalidatesCachedEntry(): void
    {
        $spy = $this->newSpy();
        $cache = new ParsedDocumentCache($spy);

        $cache->getOrParse('/a.xphp', 1, '<?php');
        $cache->getOrParse('/a.xphp', 2, '<?php $x = 1;');
        self::assertSame(2, $spy->callCount, 'version bump must reparse');

        // Same new version doesn't reparse again.
        $cache->getOrParse('/a.xphp', 2, '<?php $x = 1;');
        self::assertSame(2, $spy->callCount);
    }

    public function testDistinctUrisAreCachedIndependently(): void
    {
        $spy = $this->newSpy();
        $cache = new ParsedDocumentCache($spy);

        $cache->getOrParse('/a.xphp', 1, '<?php');
        $cache->getOrParse('/b.xphp', 1, '<?php');
        self::assertSame(2, $spy->callCount, 'distinct URIs each get parsed once');

        $cache->getOrParse('/a.xphp', 1, '<?php');
        $cache->getOrParse('/b.xphp', 1, '<?php');
        self::assertSame(2, $spy->callCount, 'both URIs serve cached results');
    }

    public function testForgetDropsTheEntryAndForcesReparseOnNextAccess(): void
    {
        $spy = $this->newSpy();
        $cache = new ParsedDocumentCache($spy);

        $cache->getOrParse('/a.xphp', 1, '<?php');
        $cache->forget('/a.xphp');
        $cache->getOrParse('/a.xphp', 1, '<?php');
        self::assertSame(2, $spy->callCount, 'forget invalidates → reparse on next access');
    }

    public function testForgetOnUnknownUriIsANoop(): void
    {
        $spy = $this->newSpy();
        $cache = new ParsedDocumentCache($spy);
        $cache->forget('/never-cached.xphp');
        self::assertSame(0, $spy->callCount);
    }

    public function testSeedIfAbsentParsesOnceAndIsServedByGetOrParseAtVersionZero(): void
    {
        $spy = $this->newSpy();
        $cache = new ParsedDocumentCache($spy);

        $cache->seedIfAbsent('file:///a.xphp', '<?php class A {}');
        self::assertSame(1, $spy->callCount, 'seedIfAbsent parses');

        // The warmer's sentinel version is 0; a subsequent getOrParse at
        // version 0 must serve the cached entry without re-parsing.
        $cache->getOrParse('file:///a.xphp', 0, '<?php class A {}');
        self::assertSame(1, $spy->callCount, 'seeded entry serves a version-0 read');
    }

    public function testSeedIfAbsentDoesNotOverwriteExistingVersionedEntry(): void
    {
        $spy = $this->newSpy();
        $cache = new ParsedDocumentCache($spy);

        // Simulate the race: didOpen lands first (version 1).
        $cache->getOrParse('file:///a.xphp', 1, '<?php class Open {}');
        self::assertSame(1, $spy->callCount);

        // Warmer fires later -- must NOT clobber the live in-memory entry
        // with a stale disk-side parse.  Without the if-absent guard, the
        // version-1 entry would be replaced by version-0; the next
        // getOrParse(uri, 1, ...) would see version mismatch and reparse
        // again -- both correctness loss (stale text served between)
        // and a perf regression (extra parse).
        $cache->seedIfAbsent('file:///a.xphp', '<?php class StaleFromDisk {}');
        self::assertSame(1, $spy->callCount, 'seedIfAbsent must skip when an entry exists');

        // The version-1 entry is still intact: reading at version 1
        // serves the open-doc payload.
        $cache->getOrParse('file:///a.xphp', 1, '<?php class Open {}');
        self::assertSame(1, $spy->callCount, 'version-1 read still serves cached open-doc entry');
    }

    public function testPeekReturnsCachedResultOrNull(): void
    {
        $spy = $this->newSpy();
        $cache = new ParsedDocumentCache($spy);

        // Miss returns null without parsing.
        self::assertNull($cache->peek('file:///a.xphp'));
        self::assertSame(0, $spy->callCount, 'peek miss must not parse');

        $cache->seedIfAbsent('file:///a.xphp', '<?php class A {}');
        $result = $cache->peek('file:///a.xphp');
        self::assertNotNull($result);

        // Subsequent peek returns the SAME instance (object identity --
        // proves we serve from the cache table, not a fresh parse).
        self::assertSame($result, $cache->peek('file:///a.xphp'));
        self::assertSame(1, $spy->callCount, 'peek hits must not reparse');
    }

    public function testForgetFilesystemDropsOnlyVersionZeroEntries(): void
    {
        $spy = $this->newSpy();
        $cache = new ParsedDocumentCache($spy);

        // Mix: two warmer-seeded (version 0) + one open-doc (version 1).
        // All three URIs use the `file://` prefix -- distinguishing by
        // prefix would wrongly drop the open-doc entry too.
        $cache->seedIfAbsent('file:///a.xphp', '<?php');
        $cache->seedIfAbsent('file:///b.xphp', '<?php');
        $cache->getOrParse('file:///open.xphp', 1, '<?php class Open {}');
        self::assertSame(3, $spy->callCount);

        $dropped = $cache->forgetFilesystem();
        self::assertSame(2, $dropped, 'must drop both warmer-seeded entries');

        // The two seeded entries are gone -- peek returns null.
        self::assertNull($cache->peek('file:///a.xphp'));
        self::assertNull($cache->peek('file:///b.xphp'));

        // Open-doc entry survives -- still in cache, no reparse on read.
        self::assertNotNull($cache->peek('file:///open.xphp'));
        $cache->getOrParse('file:///open.xphp', 1, '<?php class Open {}');
        self::assertSame(3, $spy->callCount, 'open-doc entry preserved');
    }

    public function testForgetFilesystemOnEmptyCacheReturnsZero(): void
    {
        $spy = $this->newSpy();
        $cache = new ParsedDocumentCache($spy);
        self::assertSame(0, $cache->forgetFilesystem());
    }

    /**
     * Analyzer subclass that counts analyzeFile() invocations. PHPUnit's
     * native mocking infrastructure could express the same shape but with
     * heavier setup; a tiny anonymous-class spy is the path of least
     * resistance and reads like the assertion it backs.
     */
    private function newSpy(): Analyzer
    {
        return new class(new XphpSourceParser((new ParserFactory())->createForHostVersion())) extends Analyzer {
            public int $callCount = 0;

            public function analyzeFile(string $source): \XPHP\Lsp\Analyzer\ParseResult
            {
                $this->callCount++;
                return parent::analyzeFile($source);
            }
        };
    }
}
