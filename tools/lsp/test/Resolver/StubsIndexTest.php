<?php

declare(strict_types=1);

namespace XPHP\Lsp\Test\Resolver;

use PHPUnit\Framework\TestCase;
use XPHP\Lsp\Resolver\StubsIndex;

final class StubsIndexTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/xphp-si-' . bin2hex(random_bytes(6));
        mkdir($this->dir . '/Reflection', 0o755, true);
        mkdir($this->dir . '/standard', 0o755, true);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->dir)) {
            $this->rmrf($this->dir);
        }
    }

    public function testCollectsTopLevelFunctionsAndClassesFromAllNestedFiles(): void
    {
        file_put_contents($this->dir . '/Reflection/ReflectionMethod.php', "<?php\nclass ReflectionMethod {}\nclass ReflectionParameter {}\n");
        file_put_contents($this->dir . '/standard/strings.php', "<?php\nfunction strlen(string \$s): int {}\nfunction str_replace() {}\n");

        $index = StubsIndex::loadOrBuild($this->dir);

        self::assertContains('ReflectionMethod', $index->classes);
        self::assertContains('ReflectionParameter', $index->classes);
        self::assertContains('strlen', $index->functions);
        self::assertContains('str_replace', $index->functions);
    }

    public function testCollectsNamespacedDeclarations(): void
    {
        file_put_contents(
            $this->dir . '/namespaced.php',
            "<?php\nnamespace Foo\\Bar;\nclass Baz {}\nfunction qux(): void {}\n",
        );

        $index = StubsIndex::loadOrBuild($this->dir);
        self::assertContains('Foo\\Bar\\Baz', $index->classes);
        self::assertContains('Foo\\Bar\\qux', $index->functions);
    }

    public function testPersistsJsonCacheAndShortCircuitsOnSecondCall(): void
    {
        file_put_contents($this->dir . '/x.php', "<?php\nfunction first() {}\n");

        $first = StubsIndex::loadOrBuild($this->dir);
        self::assertContains('first', $first->functions);
        self::assertFileExists($this->dir . '/' . StubsIndex::INDEX_FILENAME);

        // Mutate the JSON to prove the second call READS from cache
        // rather than re-scanning.  If the second call re-scanned, it
        // would pick up `first()` from x.php again -- but instead it
        // returns what's in the JSON.
        file_put_contents(
            $this->dir . '/' . StubsIndex::INDEX_FILENAME,
            json_encode(['functions' => ['mutated_marker'], 'classes' => []]),
        );

        $second = StubsIndex::loadOrBuild($this->dir);
        self::assertSame(['mutated_marker'], $second->functions);
        self::assertSame([], $second->classes);
    }

    public function testIgnoresUnparseableFilesGracefully(): void
    {
        // A stub file that fails to parse must not prevent collection
        // of valid declarations in sibling files.
        file_put_contents($this->dir . '/broken.php', "<?php\nfunction broken( {");
        file_put_contents($this->dir . '/good.php', "<?php\nfunction good() {}\n");

        $index = StubsIndex::loadOrBuild($this->dir);
        self::assertContains('good', $index->functions);
    }

    public function testReturnsEmptyForMissingDirectory(): void
    {
        $index = StubsIndex::loadOrBuild('/path/that/definitely/does/not/exist');
        self::assertSame([], $index->functions);
        self::assertSame([], $index->classes);
    }

    public function testRebuildsWhenJsonIsCorrupted(): void
    {
        file_put_contents($this->dir . '/y.php', "<?php\nclass Y {}\n");
        file_put_contents($this->dir . '/' . StubsIndex::INDEX_FILENAME, 'not valid json');

        $index = StubsIndex::loadOrBuild($this->dir);
        self::assertContains('Y', $index->classes);
    }

    private function rmrf(string $dir): void
    {
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $p = $dir . '/' . $entry;
            is_dir($p) ? $this->rmrf($p) : unlink($p);
        }
        rmdir($dir);
    }
}
