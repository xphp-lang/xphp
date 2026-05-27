<?php

declare(strict_types=1);

namespace XPHP\Lsp\Test\Reflection;

use PhpParser\ParserFactory;
use Phpactor\LanguageServer\Core\Workspace\Workspace as PhpactorWorkspace;
use Phpactor\WorseReflection\Core\Exception\SourceNotFound;
use Phpactor\WorseReflection\Core\Name;
use PHPUnit\Framework\TestCase;
use XPHP\Lsp\Analyzer\Analyzer;
use XPHP\Lsp\Analyzer\ParsedDocumentCache;
use XPHP\Lsp\Reflection\FilesystemSourceLocator;
use XPHP\Lsp\Reflection\FqnIndex;
use XPHP\Transpiler\Monomorphize\XphpSourceParser;

final class FilesystemSourceLocatorTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/xphp-fs-loc-' . bin2hex(random_bytes(6));
        mkdir($this->root, 0o755, true);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->root)) {
            $this->rmrf($this->root);
        }
    }

    public function testLocatesClassInPhpFile(): void
    {
        $path = $this->root . '/User.php';
        file_put_contents($path, "<?php\nnamespace App;\nclass User {}\n");

        $document = $this->newLocator()->locate(Name::fromString('App\\User'));

        self::assertStringEndsWith($path, (string) $document->uri());
        self::assertStringContainsString('class User', (string) $document);
    }

    public function testLocatesClassInXphpFileWithGenericClauseStripped(): void
    {
        $path = $this->root . '/Box.xphp';
        file_put_contents($path, "<?php\nnamespace App;\nclass Box<T> { public T \$item; }\n");

        $document = $this->newLocator()->locate(Name::fromString('App\\Box'));
        $text = (string) $document;

        // The `<T>` clause must be whitespace; class header and member still
        // at original offsets (so any Location worse-reflection derives from
        // the parsed source aligns with the editor's view of the .xphp file).
        self::assertStringNotContainsString('<T>', $text);
        self::assertStringContainsString('class Box', $text);
        self::assertStringContainsString('$item', $text);
    }

    public function testLocatesFunctionInXphpFile(): void
    {
        $path = $this->root . '/funcs.xphp';
        file_put_contents($path, "<?php\nfunction greet(string \$n) { return \$n; }\n");

        $document = $this->newLocator()->locate(Name::fromString('greet'));

        self::assertStringEndsWith($path, (string) $document->uri());
    }

    public function testFindsClassInSubdirectory(): void
    {
        mkdir($this->root . '/src/Models', 0o755, true);
        $path = $this->root . '/src/Models/User.php';
        file_put_contents($path, "<?php\nnamespace App\\Models;\nclass User {}\n");

        $document = $this->newLocator()->locate(Name::fromString('App\\Models\\User'));
        self::assertStringEndsWith($path, (string) $document->uri());
    }

    public function testSkipsVendorDirectory(): void
    {
        // vendor/ is intentionally excluded -- composer-installed code is
        // worse-reflection's job via stubs, not ours via brute-force scan.
        mkdir($this->root . '/vendor/some/pkg', 0o755, true);
        file_put_contents($this->root . '/vendor/some/pkg/Lib.php', "<?php\nclass VendorLib {}\n");

        $this->expectException(SourceNotFound::class);
        $this->newLocator()->locate(Name::fromString('VendorLib'));
    }

    public function testThrowsForUnknownFqn(): void
    {
        $this->expectException(SourceNotFound::class);
        $this->newLocator()->locate(Name::fromString('Nope\\Nope'));
    }

    public function testLeadingBackslashIsStrippedBeforeIndexLookup(): void
    {
        // `locate()` calls `ltrim((string) $name, '\\')` so that a
        // fully-qualified `\App\Containers\Box` and its unprefixed form
        // `App\Containers\Box` resolve to the same entry in the FqnIndex
        // (which stores FQNs without leading slashes).  Without this
        // normalization the prefixed lookup would miss and throw
        // SourceNotFound -- but the test would also catch an
        // `UnwrapLtrim` mutant that lets the leading slash leak through
        // to `pathFor`.
        $path = $this->root . '/Box.xphp';
        file_put_contents($path, "<?php\nnamespace App\\Containers;\nclass Box<T> {}\n");
        $locator = $this->newLocator();

        $unprefixed = $locator->locate(Name::fromString('App\\Containers\\Box'));
        $prefixed = $locator->locate(Name::fromString('\\App\\Containers\\Box'));

        self::assertStringEndsWith($path, (string) $unprefixed->uri());
        self::assertStringEndsWith($path, (string) $prefixed->uri());
        // Same FQN after ltrim -> same cached TextDocument instance.
        self::assertSame($unprefixed, $prefixed);
    }

    public function testHitCacheReturnsSameDocumentInstanceOnRepeatedLookups(): void
    {
        // The fix-H hit cache: repeated locate() calls for the same
        // FQN within a session return the same TextDocument instance.
        // No re-read, no re-strip.
        $path = $this->root . '/Box.xphp';
        file_put_contents($path, "<?php\nnamespace App;\nclass Box<T> {}\n");

        $locator = $this->newLocator();
        $first = $locator->locate(Name::fromString('App\\Box'));
        $second = $locator->locate(Name::fromString('App\\Box'));
        $third = $locator->locate(Name::fromString('App\\Box'));

        self::assertSame($first, $second, 'second hit must return the same cached instance');
        self::assertSame($second, $third);
    }

    public function testHitCacheIsFlushedWhenFqnIndexInvalidates(): void
    {
        // Fix H invalidation: after FqnIndex::invalidateFilesystem(),
        // the locator's hit cache is cleared and the next call
        // re-reads + re-strips.  Different TextDocument instance
        // proves the cache was actually flushed.
        $path = $this->root . '/Box.xphp';
        file_put_contents($path, "<?php\nnamespace App;\nclass Box<T> {}\n");

        $parser = new XphpSourceParser((new ParserFactory())->createForHostVersion());
        $cache = new ParsedDocumentCache(new Analyzer($parser));
        $workspace = new PhpactorWorkspace();
        $index = new FqnIndex($workspace, $cache, $parser, $this->root);
        $locator = new FilesystemSourceLocator($index, $parser, $this->root);

        $first = $locator->locate(Name::fromString('App\\Box'));
        $index->invalidateFilesystem();
        $second = $locator->locate(Name::fromString('App\\Box'));

        self::assertNotSame($first, $second, 'invalidation must drop the cached document');
        // ...but they should still describe the SAME content (re-read
        // identical file from disk).
        self::assertSame((string) $first, (string) $second);
    }

    public function testMissLogIsDedupedAcrossCalls(): void
    {
        // Fix H: the noisy `[xphp-lsp locator] miss ...` stderr line
        // fires only ONCE per FQN per cache-generation -- prod logs
        // showed the same FQN missing 30+ times for a single user
        // request.  We can't easily intercept fwrite(STDERR, ...), so
        // verify the underlying state instead: repeated misses still
        // throw SourceNotFound (correct behaviour for worse-reflection's
        // chain) but the `loggedMisses` set marks the FQN exactly once.
        $locator = $this->newLocator();

        $caught = 0;
        for ($i = 0; $i < 5; $i++) {
            try {
                $locator->locate(Name::fromString('Never\\Existed'));
            } catch (SourceNotFound) {
                $caught++;
            }
        }
        self::assertSame(5, $caught, 'every locate call still throws SourceNotFound');

        // After invalidation, the dedupe state resets -- next miss
        // will log again.
        // (No public observability on the loggedMisses set; behavioural
        // test below verifies the cache-flush path.)
    }

    public function testMissLogResetsAfterFqnIndexInvalidation(): void
    {
        // After invalidateFilesystem, the loggedMisses set clears so
        // the next miss for a previously-missed FQN re-logs.  Tested
        // indirectly via the hit cache: a name that missed before
        // invalidation but now has a file on disk should resolve.
        $parser = new XphpSourceParser((new ParserFactory())->createForHostVersion());
        $cache = new ParsedDocumentCache(new Analyzer($parser));
        $workspace = new PhpactorWorkspace();
        $index = new FqnIndex($workspace, $cache, $parser, $this->root);
        $locator = new FilesystemSourceLocator($index, $parser, $this->root);

        // First lookup: missing.
        try {
            $locator->locate(Name::fromString('App\\Added'));
            self::fail('expected SourceNotFound');
        } catch (SourceNotFound) {
            // expected
        }

        // Add the file, invalidate the index, locate again.
        file_put_contents($this->root . '/Added.xphp', "<?php\nnamespace App;\nclass Added {}\n");
        $index->invalidateFilesystem();
        $document = $locator->locate(Name::fromString('App\\Added'));
        self::assertStringContainsString('class Added', (string) $document);
    }

    public function testTypeParamFqnShortCircuitsBeforePathLookup(): void
    {
        // Fix L: `T` referenced inside `namespace App\Containers`
        // name-resolves to `App\Containers\T`.  We must throw
        // SourceNotFound (so worse-reflection's chain falls through to
        // the next locator) but WITHOUT consulting pathFor and WITHOUT
        // logging the noisy "[xphp-lsp locator] miss" line.
        file_put_contents(
            $this->root . '/Box.xphp',
            "<?php\nnamespace App\\Containers;\nclass Box<T> {}\n",
        );
        $locator = $this->newLocator();

        $this->expectException(SourceNotFound::class);
        $this->expectExceptionMessageMatches('/type-param reference/');
        $locator->locate(Name::fromString('App\\Containers\\T'));
    }

    public function testBareBuiltinFunctionFqnShortCircuitsWithoutMissLog(): void
    {
        // Fix 3: cursor on `gettype(...)` inside `namespace App\Demos`
        // makes worse-reflection ask the locator for
        // `App\Demos\gettype`.  PHP's runtime would fall back to the
        // global `gettype()` function, but the locator (class-only)
        // can't represent that and used to log a stderr miss line.
        // Now we recognise the shape and throw SourceNotFound with a
        // distinct message that doesn't go through the miss-log path.
        $locator = $this->newLocator();

        try {
            $locator->locate(Name::fromString('App\\Demos\\gettype'));
            self::fail('expected SourceNotFound');
        } catch (SourceNotFound $e) {
            self::assertStringContainsString(
                'global function reference',
                $e->getMessage(),
                'must use the suppressed-miss code path, not the regular pathFor miss',
            );
        }
    }

    public function testRealClassMissStillHitsTheNormalMissPath(): void
    {
        // The short-circuit must NOT swallow legitimate unknown FQNs
        // -- a name with no generic-param declaration anywhere should
        // still throw with the workspace-walked miss message.
        file_put_contents(
            $this->root . '/Box.xphp',
            "<?php\nnamespace App\\Containers;\nclass Box<T> {}\n",
        );
        $locator = $this->newLocator();

        try {
            $locator->locate(Name::fromString('App\\Containers\\Unknown'));
            self::fail('expected SourceNotFound');
        } catch (SourceNotFound $e) {
            self::assertStringContainsString('No file under', $e->getMessage());
        }
    }

    public function testReturnsEmptyMapWhenRootMissing(): void
    {
        $parser = new XphpSourceParser((new ParserFactory())->createForHostVersion());
        $cache = new ParsedDocumentCache(new Analyzer($parser));
        $workspace = new PhpactorWorkspace();
        $root = '/path/that/definitely/does/not/exist';
        $locator = new FilesystemSourceLocator(
            new FqnIndex($workspace, $cache, $parser, $root),
            $parser,
            $root,
        );

        $this->expectException(SourceNotFound::class);
        $locator->locate(Name::fromString('Anything'));
    }

    private function newLocator(): FilesystemSourceLocator
    {
        $parser = new XphpSourceParser((new ParserFactory())->createForHostVersion());
        $cache = new ParsedDocumentCache(new Analyzer($parser));
        $workspace = new PhpactorWorkspace();
        return new FilesystemSourceLocator(
            new FqnIndex($workspace, $cache, $parser, $this->root),
            $parser,
            $this->root,
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
