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
