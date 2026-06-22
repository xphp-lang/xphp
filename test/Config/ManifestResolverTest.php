<?php

declare(strict_types=1);

namespace XPHP\Config;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use XPHP\FileSystem\FileFinder\NativeFileFinder;
use XPHP\FileSystem\FileReader\NativeFileReader;

final class ManifestResolverTest extends TestCase
{
    private string $work;

    protected function setUp(): void
    {
        $this->work = sys_get_temp_dir() . '/xphp-manifest-' . uniqid('', true);
        mkdir($this->work, 0o755, true);
    }

    protected function tearDown(): void
    {
        self::rrmdir($this->work);
    }

    public function testResolvesOwnSources(): void
    {
        $this->pkg('app', '{"sources":["src"]}', ['src/A.xphp', 'src/Sub/B.xphp']);

        $r = $this->resolve($this->work . '/app');

        self::assertSame(['A.xphp', 'B.xphp'], $this->basenames($r));
        self::assertNull($r->target);
        self::assertNull($r->cache);
    }

    public function testOmittedSourcesDefaultsToManifestDir(): void
    {
        $this->pkg('app', '{}', ['Root.xphp']);

        $r = $this->resolve($this->work . '/app');

        self::assertSame(['Root.xphp'], $this->basenames($r));
        // The root for that file is the manifest dir itself.
        self::assertSame($this->work . '/app', array_values($r->rootByFile)[0]);
    }

    public function testTargetAndCacheAreAbsolutisedFromEntryDir(): void
    {
        $this->pkg('app', '{"sources":["src"],"target":"dist","cache":".xphp-cache"}', ['src/A.xphp']);

        $r = $this->resolve($this->work . '/app');

        self::assertSame($this->work . '/app/dist', $r->target);
        self::assertSame($this->work . '/app/.xphp-cache', $r->cache);
    }

    public function testCrossPackageExplicitInclude(): void
    {
        $this->pkg('app', '{"sources":["src"],"include":["../lib"]}', ['src/Use.xphp']);
        $this->pkg('lib', '{"sources":["src"]}', ['src/Box.xphp']);

        $r = $this->resolve($this->work . '/app');

        self::assertSame(['Box.xphp', 'Use.xphp'], $this->basenames($r));
    }

    public function testGlobDiscoverySkippingNonXphpDirs(): void
    {
        $this->pkg('app', '{"sources":["src"],"include":["vendor/*/*"]}', ['src/Use.xphp']);
        // A plain (non-xphp) package with no manifest, named to sort FIRST among the glob matches —
        // it must be skipped (not error, not short-circuit the later xphp packages).
        $this->pkg('app/vendor/org/0plain', null, ['src/ignored.php']);
        $this->pkg('app/vendor/org/pkga', '{"sources":["src"]}', ['src/A.xphp']);
        $this->pkg('app/vendor/org/pkgb', '{"sources":["src"]}', ['src/B.xphp']);

        $r = $this->resolve($this->work . '/app');

        self::assertSame(['A.xphp', 'B.xphp', 'Use.xphp'], $this->basenames($r));
    }

    public function testGlobMatchingNoDirsIsACleanNoOp(): void
    {
        // A vendor glob that matches nothing (no packages installed yet) is not an error.
        $this->pkg('app', '{"sources":["src"],"include":["vendor/*/*"]}', ['src/Use.xphp']);

        $r = $this->resolve($this->work . '/app');

        self::assertSame(['Use.xphp'], $this->basenames($r));
    }

    public function testAbsolutePathIncludeIsResolved(): void
    {
        $this->pkg('lib', '{"sources":["src"]}', ['src/Box.xphp']);
        // An absolute include path is used as-is (not joined onto the manifest dir).
        $this->pkg('app', sprintf('{"sources":["src"],"include":["%s"]}', $this->work . '/lib'), ['src/Use.xphp']);

        $r = $this->resolve($this->work . '/app');

        self::assertSame(['Box.xphp', 'Use.xphp'], $this->basenames($r));
    }

    public function testRecursiveGlobDiscoversPackagesAtAnyDepth(): void
    {
        // `**` finds every dir with an xphp.json under the prefix, at any depth, skipping the rest.
        // app's own `sources` is an explicit `src` (NOT the default ".", which would recursively
        // grab packages/*.xphp directly) — so A/B are reachable ONLY through the `**` discovery.
        $this->pkg('app', '{"sources":["src"],"include":["packages/**"]}', ['src/Root.xphp']);
        $this->pkg('app/packages/a', '{"sources":["src"]}', ['src/A.xphp']);
        $this->pkg('app/packages/nested/deep/b', '{"sources":["src"]}', ['src/B.xphp']);
        mkdir($this->work . '/app/packages/plain', 0o755, true); // no xphp.json → not discovered
        // A non-manifest file at depth must NOT be mistaken for a package.
        file_put_contents($this->work . '/app/packages/notes.txt', 'x');

        $r = $this->resolve($this->work . '/app');

        self::assertSame(['A.xphp', 'B.xphp', 'Root.xphp'], $this->basenames($r));
    }

    public function testRecursiveGlobOverMissingDirIsACleanNoOp(): void
    {
        $this->pkg('app', '{"sources":["src"],"include":["packages/**"]}', ['src/Use.xphp']);
        // No `packages/` dir exists at all.

        $r = $this->resolve($this->work . '/app');

        self::assertSame(['Use.xphp'], $this->basenames($r));
    }

    public function testExplicitIncludeWithoutManifestIsHardError(): void
    {
        $this->pkg('app', '{"include":["../nope"]}', []);
        mkdir($this->work . '/nope', 0o755, true); // exists, but no xphp.json

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('has no xphp.json');
        $this->resolve($this->work . '/app');
    }

    public function testTransitiveAcrossThreePackages(): void
    {
        $this->pkg('a', '{"sources":["src"],"include":["../b"]}', ['src/A.xphp']);
        $this->pkg('b', '{"sources":["src"],"include":["../c"]}', ['src/B.xphp']);
        $this->pkg('c', '{"sources":["src"]}', ['src/C.xphp']);

        $r = $this->resolve($this->work . '/a');

        self::assertSame(['A.xphp', 'B.xphp', 'C.xphp'], $this->basenames($r));
    }

    public function testDiamondResolvesSharedDependencyOnce(): void
    {
        $this->pkg('a', '{"include":["../b","../c"]}', []);
        $this->pkg('b', '{"sources":["src"],"include":["../d"]}', ['src/B.xphp']);
        $this->pkg('c', '{"sources":["src"],"include":["../d"]}', ['src/C.xphp']);
        $this->pkg('d', '{"sources":["src"]}', ['src/D.xphp']);

        $r = $this->resolve($this->work . '/a');

        // D appears exactly once despite two include paths.
        self::assertSame(1, count(array_filter($this->basenames($r), static fn (string $b): bool => $b === 'D.xphp')));
        self::assertSame(['B.xphp', 'C.xphp', 'D.xphp'], $this->basenames($r));
    }

    public function testCycleTerminates(): void
    {
        $this->pkg('a', '{"sources":["src"],"include":["../b"]}', ['src/A.xphp']);
        $this->pkg('b', '{"sources":["src"],"include":["../a"]}', ['src/B.xphp']);

        $r = $this->resolve($this->work . '/a'); // must not hang

        self::assertSame(['A.xphp', 'B.xphp'], $this->basenames($r));
    }

    public function testSelfIncludeViaSeparateEntryManifest(): void
    {
        // Dev-profile idiom: a separate entry manifest pulls the package's own xphp.json (its `src`)
        // via include ["."], and adds `tests`.
        mkdir($this->work . '/app/src', 0o755, true);
        mkdir($this->work . '/app/tests', 0o755, true);
        file_put_contents($this->work . '/app/xphp.json', '{"sources":["src"]}');
        file_put_contents($this->work . '/app/xphp.dev.json', '{"sources":["tests"],"include":["."]}');
        file_put_contents($this->work . '/app/src/Lib.xphp', '<?php');
        file_put_contents($this->work . '/app/tests/LibTest.xphp', '<?php');

        $r = $this->resolve($this->work . '/app/xphp.dev.json');

        self::assertSame(['Lib.xphp', 'LibTest.xphp'], $this->basenames($r));
    }

    public function testMissingSourceDirIsHardError(): void
    {
        $this->pkg('app', '{"sources":["nope"]}', []);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('is not a directory');
        $this->resolve($this->work . '/app');
    }

    public function testNoManifestInDirIsHardError(): void
    {
        mkdir($this->work . '/empty', 0o755, true);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('No xphp.json found');
        $this->resolve($this->work . '/empty');
    }

    public function testNonexistentEntryPathIsHardError(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('does not exist');
        $this->resolve($this->work . '/ghost');
    }

    public function testEntryMayBeAnExplicitManifestFile(): void
    {
        $this->pkg('app', '{"sources":["src"]}', ['src/A.xphp']);

        $r = $this->resolve($this->work . '/app/xphp.json');

        self::assertSame(['A.xphp'], $this->basenames($r));
    }

    // --- helpers ---

    /** @param array<int|string,string> $files relative path (or content-keyed) => content */
    private function pkg(string $rel, ?string $manifestJson, array $files): void
    {
        $dir = $this->work . '/' . $rel;
        mkdir($dir, 0o755, true);
        if ($manifestJson !== null) {
            file_put_contents($dir . '/xphp.json', $manifestJson);
        }
        foreach ($files as $f) {
            $path = $dir . '/' . $f;
            if (!is_dir(dirname($path))) {
                mkdir(dirname($path), 0o755, true);
            }
            file_put_contents($path, '<?php');
        }
    }

    private function resolve(string $entry): ResolvedSources
    {
        return (new ManifestResolver(new NativeFileReader(), new NativeFileFinder()))->resolve($entry);
    }

    /** @return list<string> sorted basenames of the resolved files */
    private function basenames(ResolvedSources $r): array
    {
        $names = array_map(static fn (string $p): string => basename($p), $r->files->filepaths);
        sort($names);

        return array_values($names);
    }

    private static function rrmdir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $e) {
            if ($e === '.' || $e === '..') {
                continue;
            }
            $p = $dir . '/' . $e;
            is_dir($p) ? self::rrmdir($p) : unlink($p);
        }
        rmdir($dir);
    }
}
