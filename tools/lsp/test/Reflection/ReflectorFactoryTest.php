<?php

declare(strict_types=1);

namespace XPHP\Lsp\Test\Reflection;

use PhpParser\ParserFactory;
use Phpactor\LanguageServer\Core\Workspace\Workspace as PhpactorWorkspace;
use Phpactor\LanguageServerProtocol\TextDocumentItem;
use PHPUnit\Framework\TestCase;
use XPHP\Lsp\Analyzer\Analyzer;
use XPHP\Lsp\Analyzer\ParsedDocumentCache;
use XPHP\Lsp\Reflection\ReflectorFactory;
use XPHP\Transpiler\Monomorphize\XphpSourceParser;

final class ReflectorFactoryTest extends TestCase
{
    public function testReflectsClassFromWorkspace(): void
    {
        $workspace = new PhpactorWorkspace();
        $workspace->open(new TextDocumentItem(
            '/User.xphp',
            'xphp',
            1,
            "<?php\nnamespace App\\Models;\nfinal class User {}\n",
        ));

        $reflector = $this->newFactory($workspace, rootPath: $this->emptyRoot())->build();

        $class = $reflector->reflectClass('App\\Models\\User');
        self::assertSame('App\\Models\\User', (string) $class->name());
    }

    public function testReflectsClassFromFilesystem(): void
    {
        $root = sys_get_temp_dir() . '/xphp-rf-' . bin2hex(random_bytes(6));
        mkdir($root . '/src', 0o755, true);
        file_put_contents(
            $root . '/src/Plastic.xphp',
            "<?php\nnamespace App\\Models;\nclass Plastic {}\n",
        );

        try {
            $reflector = $this->newFactory(new PhpactorWorkspace(), rootPath: $root)->build();

            $class = $reflector->reflectClass('App\\Models\\Plastic');
            self::assertSame('App\\Models\\Plastic', (string) $class->name());
        } finally {
            $this->rmrf($root);
        }
    }

    public function testExtractStubsCacheCopiesFromRegularDirectory(): void
    {
        // We can't easily build a real PHAR fixture for the test, but the
        // extractor handles a regular source directory identically to a
        // phar:// source (PHP's stream wrapper transparency).  Cover the
        // recursive copy + sentinel + idempotency contract with an
        // on-disk fixture.
        $source = sys_get_temp_dir() . '/xphp-rf-stub-src-' . bin2hex(random_bytes(6));
        mkdir($source . '/Reflection', 0o755, true);
        mkdir($source . '/standard', 0o755, true);
        file_put_contents($source . '/Reflection/Class.php', "<?php\nclass ReflectionClass {}");
        file_put_contents($source . '/standard/_types.php', "<?php\nfunction strlen(string \$s): int {}");

        try {
            $cache = ReflectorFactory::extractStubsCache($source);

            self::assertFileExists($cache . '/Reflection/Class.php');
            self::assertFileExists($cache . '/standard/_types.php');
            self::assertFileExists($cache . '/.complete', 'sentinel must be written after a successful extraction');

            // Idempotent: second call must short-circuit (returns same path,
            // no error from re-writing into existing dirs/files).
            $cache2 = ReflectorFactory::extractStubsCache($source);
            self::assertSame($cache, $cache2);
        } finally {
            $this->rmrf($source);
        }
    }

    public function testReflectsNativeFunctionFromStubs(): void
    {
        $stubPath = ReflectorFactory::defaultStubPath();
        if (!is_dir($stubPath)) {
            self::markTestSkipped(
                'jetbrains/phpstorm-stubs is not installed at the expected path: ' . $stubPath
            );
        }

        $reflector = $this->newFactory(
            new PhpactorWorkspace(),
            rootPath: $this->emptyRoot(),
        )->build();

        $function = $reflector->reflectFunction('strlen');
        self::assertSame('strlen', (string) $function->name());
    }

    public function testWorkspaceShadowsFilesystemAtSameFqn(): void
    {
        // When both workspace AND filesystem could answer the FQN, workspace
        // wins (priority 100 > priority 50 in the builder).  The user's
        // unsaved edits in the open buffer are the truth, not the stale
        // on-disk version.
        $root = sys_get_temp_dir() . '/xphp-rf-' . bin2hex(random_bytes(6));
        mkdir($root, 0o755, true);
        file_put_contents(
            $root . '/Live.xphp',
            "<?php\nnamespace App;\nclass Live { public string \$disk = 'on-disk'; }\n",
        );

        $workspace = new PhpactorWorkspace();
        $workspace->open(new TextDocumentItem(
            'file://' . $root . '/Live.xphp',
            'xphp',
            2,
            "<?php\nnamespace App;\nclass Live { public string \$buffer = 'in-buffer'; }\n",
        ));

        try {
            $reflector = $this->newFactory($workspace, rootPath: $root)->build();

            $class = $reflector->reflectClass('App\\Live');
            $propertyNames = [];
            foreach ($class->properties() as $property) {
                $propertyNames[] = (string) $property->name();
            }

            self::assertContains('buffer', $propertyNames, 'workspace edits must shadow on-disk source');
            self::assertNotContains('disk', $propertyNames);
        } finally {
            $this->rmrf($root);
        }
    }

    public function testCacheRootRespectsExplicitOverride(): void
    {
        $tmp = sys_get_temp_dir() . '/xphp-rf-override-' . bin2hex(random_bytes(4));
        $prev = getenv('XPHP_LSP_CACHE_DIR');
        putenv('XPHP_LSP_CACHE_DIR=' . $tmp);
        try {
            self::assertSame($tmp, ReflectorFactory::cacheRoot());
            self::assertSame($tmp . '/extracted-stubs/', dirname(ReflectorFactory::cacheRoot() . '/extracted-stubs/sha') . '/');
            self::assertSame($tmp . '/stub-cache', ReflectorFactory::defaultCacheDir());
        } finally {
            $prev === false ? putenv('XPHP_LSP_CACHE_DIR') : putenv('XPHP_LSP_CACHE_DIR=' . $prev);
        }
    }

    public function testCacheRootHonoursXdgWhenNoOverride(): void
    {
        $tmp = sys_get_temp_dir() . '/xphp-rf-xdg-' . bin2hex(random_bytes(4));
        $prevOverride = getenv('XPHP_LSP_CACHE_DIR');
        $prevXdg = getenv('XDG_CACHE_HOME');
        putenv('XPHP_LSP_CACHE_DIR');
        putenv('XDG_CACHE_HOME=' . $tmp);
        try {
            self::assertSame($tmp . '/xphp-lsp', ReflectorFactory::cacheRoot());
        } finally {
            $prevOverride === false ? putenv('XPHP_LSP_CACHE_DIR') : putenv('XPHP_LSP_CACHE_DIR=' . $prevOverride);
            $prevXdg === false ? putenv('XDG_CACHE_HOME') : putenv('XDG_CACHE_HOME=' . $prevXdg);
        }
    }

    public function testCacheRootFallsBackToHomeWhenNoXdg(): void
    {
        $home = sys_get_temp_dir() . '/xphp-rf-home-' . bin2hex(random_bytes(4));
        $prevOverride = getenv('XPHP_LSP_CACHE_DIR');
        $prevXdg = getenv('XDG_CACHE_HOME');
        $prevHome = getenv('HOME');
        putenv('XPHP_LSP_CACHE_DIR');
        putenv('XDG_CACHE_HOME');
        putenv('HOME=' . $home);
        try {
            $expected = PHP_OS_FAMILY === 'Darwin'
                ? $home . '/Library/Caches/xphp-lsp'
                : $home . '/.cache/xphp-lsp';
            self::assertSame($expected, ReflectorFactory::cacheRoot());
        } finally {
            $prevOverride === false ? putenv('XPHP_LSP_CACHE_DIR') : putenv('XPHP_LSP_CACHE_DIR=' . $prevOverride);
            $prevXdg === false ? putenv('XDG_CACHE_HOME') : putenv('XDG_CACHE_HOME=' . $prevXdg);
            $prevHome === false ? putenv('HOME') : putenv('HOME=' . $prevHome);
        }
    }

    public function testCacheRootStripsTrailingSlashesFromOverride(): void
    {
        $tmp = sys_get_temp_dir() . '/xphp-rf-trim-' . bin2hex(random_bytes(4));
        $prev = getenv('XPHP_LSP_CACHE_DIR');
        putenv('XPHP_LSP_CACHE_DIR=' . $tmp . '////');
        try {
            self::assertSame($tmp, ReflectorFactory::cacheRoot());
        } finally {
            $prev === false ? putenv('XPHP_LSP_CACHE_DIR') : putenv('XPHP_LSP_CACHE_DIR=' . $prev);
        }
    }

    public function testDefaultCacheDirNestsUnderCacheRootInStubCacheSubdir(): void
    {
        $tmp = sys_get_temp_dir() . '/xphp-rf-dcd-' . bin2hex(random_bytes(4));
        $prev = getenv('XPHP_LSP_CACHE_DIR');
        putenv('XPHP_LSP_CACHE_DIR=' . $tmp);
        try {
            // Locks the layout against `Concat` / `ConcatOperandRemoval`
            // mutants that drop either side of the `cacheRoot() . '/stub-cache'`.
            self::assertSame($tmp . '/stub-cache', ReflectorFactory::defaultCacheDir());
        } finally {
            $prev === false ? putenv('XPHP_LSP_CACHE_DIR') : putenv('XPHP_LSP_CACHE_DIR=' . $prev);
        }
    }

    public function testCacheRootStripsTrailingSlashesFromXdgValue(): void
    {
        $tmp = sys_get_temp_dir() . '/xphp-rf-xdg-trim-' . bin2hex(random_bytes(4));
        $prevOverride = getenv('XPHP_LSP_CACHE_DIR');
        $prevXdg = getenv('XDG_CACHE_HOME');
        putenv('XPHP_LSP_CACHE_DIR');
        putenv('XDG_CACHE_HOME=' . $tmp . '////');
        try {
            self::assertSame($tmp . '/xphp-lsp', ReflectorFactory::cacheRoot());
        } finally {
            $prevOverride === false ? putenv('XPHP_LSP_CACHE_DIR') : putenv('XPHP_LSP_CACHE_DIR=' . $prevOverride);
            $prevXdg === false ? putenv('XDG_CACHE_HOME') : putenv('XDG_CACHE_HOME=' . $prevXdg);
        }
    }

    public function testCacheRootStripsTrailingSlashesFromHomeValue(): void
    {
        $tmp = sys_get_temp_dir() . '/xphp-rf-home-trim-' . bin2hex(random_bytes(4));
        $prevOverride = getenv('XPHP_LSP_CACHE_DIR');
        $prevXdg = getenv('XDG_CACHE_HOME');
        $prevHome = getenv('HOME');
        putenv('XPHP_LSP_CACHE_DIR');
        putenv('XDG_CACHE_HOME');
        putenv('HOME=' . $tmp . '////');
        try {
            $expected = PHP_OS_FAMILY === 'Darwin'
                ? $tmp . '/Library/Caches/xphp-lsp'
                : $tmp . '/.cache/xphp-lsp';
            self::assertSame($expected, ReflectorFactory::cacheRoot());
        } finally {
            $prevOverride === false ? putenv('XPHP_LSP_CACHE_DIR') : putenv('XPHP_LSP_CACHE_DIR=' . $prevOverride);
            $prevXdg === false ? putenv('XDG_CACHE_HOME') : putenv('XDG_CACHE_HOME=' . $prevXdg);
            $prevHome === false ? putenv('HOME') : putenv('HOME=' . $prevHome);
        }
    }

    public function testExtractStubsCacheLandsUnderCacheRootSubdirectory(): void
    {
        // Force a known cacheRoot and verify the extraction nests under it.
        $tmp = sys_get_temp_dir() . '/xphp-rf-layout-' . bin2hex(random_bytes(4));
        $prev = getenv('XPHP_LSP_CACHE_DIR');
        putenv('XPHP_LSP_CACHE_DIR=' . $tmp);

        $source = sys_get_temp_dir() . '/xphp-rf-stub-src-d-' . bin2hex(random_bytes(4));
        mkdir($source, 0o755, true);
        file_put_contents($source . '/x.php', "<?php\n");

        try {
            $cache = ReflectorFactory::extractStubsCache($source);
            self::assertStringStartsWith($tmp . '/extracted-stubs/', $cache);
            self::assertFileExists($cache . '/x.php');
            self::assertFileExists($cache . '/.complete');
        } finally {
            $this->rmrf($source);
            if (is_dir($tmp)) {
                $this->rmrf($tmp);
            }
            $prev === false ? putenv('XPHP_LSP_CACHE_DIR') : putenv('XPHP_LSP_CACHE_DIR=' . $prev);
        }
    }

    private function newFactory(PhpactorWorkspace $workspace, string $rootPath): ReflectorFactory
    {
        $parser = new XphpSourceParser((new ParserFactory())->createForHostVersion());
        $cache = new ParsedDocumentCache(new Analyzer($parser));
        return new ReflectorFactory(
            $workspace,
            $cache,
            $parser,
            $rootPath,
            ReflectorFactory::defaultStubPath(),
            ReflectorFactory::defaultCacheDir(),
            new \XPHP\Lsp\Reflection\FqnIndex($workspace, $cache, $parser, $rootPath),
        );
    }

    private function emptyRoot(): string
    {
        // A root that contains nothing -- forces lookups to skip the
        // filesystem locator and hit workspace / stubs.
        $path = sys_get_temp_dir() . '/xphp-rf-empty';
        if (!is_dir($path)) {
            mkdir($path, 0o755, true);
        }
        return $path;
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
