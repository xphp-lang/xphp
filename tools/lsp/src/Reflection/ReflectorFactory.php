<?php

declare(strict_types=1);

namespace XPHP\Lsp\Reflection;

use Phpactor\LanguageServer\Core\Workspace\Workspace as PhpactorWorkspace;
use Phpactor\WorseReflection\Reflector;
use Phpactor\WorseReflection\ReflectorBuilder;
use Phpactor\WorseReflection\Core\SourceCodeLocator\StubSourceLocator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;
use XPHP\Lsp\Analyzer\ParsedDocumentCache;
use XPHP\Transpiler\Monomorphize\XphpSourceParser;

/**
 * Build a fully-wired worse-reflection `Reflector` for the LSP session.
 *
 * Locator chain (highest priority first, set via priority arg on
 * `ReflectorBuilder::addLocator`):
 *
 *   1. `WorkspaceSourceLocator`  -- open documents PhpStorm has didOpen'd.
 *      Highest priority because the user's edits aren't on disk yet; the
 *      open buffer is the truth.
 *   2. `FilesystemSourceLocator` -- everything else under the project
 *      root (other .xphp / .php files the user hasn't opened in this
 *      editor session).
 *   3. `StubSourceLocator`       -- `vendor/jetbrains/phpstorm-stubs`,
 *      shipping signatures + docblocks for the entire PHP standard
 *      library + extension surface (DateTime, PDO, etc.).
 *   4. `InternalLocator`         -- auto-added by `ReflectorBuilder::build()`
 *      at priority 255; covers a handful of fundamental types
 *      (Iterator, Generator, ArrayAccess, ...) for which worse-reflection
 *      ships purpose-built reflections.
 *
 * Why no caching on the Reflector itself: worse-reflection's `enableCache()`
 * uses a TTL cache (default 5s) keyed by reflection class -- useful when
 * one logical user action triggers many reflection lookups (completion
 * after `$obj->|` queries the class repeatedly).  For GTD / hover (one
 * lookup per action) it's wasted complexity.  Revisit if a profile shows
 * repeated lookups in completion.
 *
 * Bootstrap caveat: `StubSourceLocator` needs a `Reflector` in its
 * constructor (it uses it to discover FQNs in stub files when building
 * the on-disk cache).  This is a chicken-and-egg setup -- the obvious
 * "single builder + addLocator(stub)" doesn't work.  We resolve it by
 * building a transient bootstrap reflector without stubs first, then
 * using that to instantiate the stub locator, then re-building the
 * final reflector with all three locators.  The bootstrap reflector is
 * thrown away.
 */
final class ReflectorFactory
{
    /**
     * @param string $stubPath  Path to `phpstorm-stubs/` root (the directory
     *                           containing `Core/`, `standard/`, etc.).
     *                           If empty or non-existent, the stub locator
     *                           is omitted from the chain -- native
     *                           function GTD will not resolve, but
     *                           workspace / filesystem lookup still works.
     * @param string $cacheDir  Writable dir for the stub map cache.
     */
    public function __construct(
        private readonly PhpactorWorkspace $workspace,
        private readonly ParsedDocumentCache $cache,
        private readonly XphpSourceParser $parser,
        private readonly string $rootPath,
        private readonly string $stubPath,
        private readonly string $cacheDir,
        private readonly FqnIndex $fqnIndex,
    ) {
    }

    public function build(): Reflector
    {
        $workspaceLocator = new WorkspaceSourceLocator($this->workspace, $this->cache, $this->parser);
        $filesystemLocator = new FilesystemSourceLocator($this->fqnIndex, $this->parser, $this->rootPath);

        $stubsAvailable = $this->stubPath !== '' && is_dir($this->stubPath);

        if (!$stubsAvailable) {
            // No stubs available -- short-circuit, return a stubs-less reflector.
            return ReflectorBuilder::create()
                ->addLocator($workspaceLocator, priority: 100)
                ->addLocator($filesystemLocator, priority: 50)
                ->build();
        }

        // Bootstrap reflector: needed because StubSourceLocator's constructor
        // accepts a Reflector to walk stub files during cache build.  This
        // throwaway reflector covers workspace + filesystem only; once the
        // stub cache exists on disk (one-time build keyed by md5(stubPath)),
        // subsequent runs hit the serialized map directly without touching
        // this bootstrap.
        $bootstrap = ReflectorBuilder::create()
            ->addLocator($workspaceLocator, priority: 100)
            ->addLocator($filesystemLocator, priority: 50)
            ->build();

        $this->ensureCacheDir();
        $stubLocator = new StubSourceLocator($bootstrap, $this->stubPath, $this->cacheDir);

        return ReflectorBuilder::create()
            ->addLocator($workspaceLocator, priority: 100)
            ->addLocator($filesystemLocator, priority: 50)
            ->addLocator($stubLocator, priority: 25)
            ->build();
    }

    private function ensureCacheDir(): void
    {
        if (is_dir($this->cacheDir)) {
            return;
        }
        if (!@mkdir($this->cacheDir, 0o755, true) && !is_dir($this->cacheDir)) {
            throw new RuntimeException(sprintf(
                'Could not create stub-cache directory at "%s"',
                $this->cacheDir,
            ));
        }
    }

    /**
     * Default stubs path: `vendor/jetbrains/phpstorm-stubs/` relative to
     * this file's location, which resolves correctly both in dev (file
     * tree) and inside the built PHAR (composer's vendor dir is bundled).
     *
     * PHAR awareness: when running from a PHAR (the typical PhpStorm
     * plugin install), `__DIR__` resolves to `phar://.../src/Reflection`
     * and the stub directory beneath it is reachable via PHP's
     * phar:// stream wrapper -- but Locations the LSP returns to the
     * client carry that same phar:// URI, and PhpStorm's LSP framework
     * warns `Unexpected URI scheme: phar://...` and refuses to open
     * the file (no client-side navigation).  To make native-function
     * GTD usable, we extract the stub tree to a stable on-disk cache
     * the first time we're called from inside a PHAR and return that
     * file:// path instead.
     */
    public static function defaultStubPath(): string
    {
        $candidate = __DIR__ . '/../../vendor/jetbrains/phpstorm-stubs';

        // PHP's __DIR__ inside a PHAR begins with `phar://`.  Detect that
        // and route through the extractor so worse-reflection sees a real
        // filesystem path.
        if (str_starts_with($candidate, 'phar://')) {
            return self::extractStubsCache($candidate);
        }

        $real = realpath($candidate);
        return $real !== false ? $real : $candidate;
    }

    /**
     * Recursively copy a source directory (typically a `phar://` URI)
     * to a stable on-disk cache and return the cache path.  Idempotent:
     * once the extraction completes successfully, subsequent calls
     * detect the sentinel marker and short-circuit.
     *
     * Cache layout: `<cacheRoot>/extracted-stubs/<sha-of-source>/`,
     * where `<cacheRoot>` resolves per {@see self::cacheRoot} -- by
     * default a per-user XDG / `~/.cache` / Library/Caches directory
     * rather than `/tmp`, so the extraction survives reboots and
     * `/tmp`-reaper passes.  Keying by `sha-of-source` means a plugin
     * upgrade (different PHAR path) gets a fresh cache without
     * stepping on the prior install's extraction; orphaned caches
     * from older installs are harmless.
     *
     * Extracted file count: phpstorm-stubs is ~3000 .php files, ~30 MB.
     * Copy time on a warm SSD is sub-second.  We accept the disk cost
     * once per install because the alternative -- teaching IntelliJ's
     * LSP client to read phar:// URIs -- requires either custom plugin
     * code we can't unit-test or a JarFileSystem mount that doesn't
     * work on PHARs (PHARs aren't ZIPs at the byte level despite the
     * superficial format similarity).
     */
    public static function extractStubsCache(string $sourceDir): string
    {
        $cacheRoot = self::cacheRoot() . '/extracted-stubs';
        $sourceHash = substr(sha1($sourceDir), 0, 16);
        $cacheDir = $cacheRoot . '/' . $sourceHash;

        // Sentinel file marks a complete extraction.  If interrupted
        // mid-copy (process killed, disk full, ...) the sentinel won't
        // be written and the next invocation re-extracts.
        $sentinel = $cacheDir . '/.complete';
        if (is_file($sentinel)) {
            return $cacheDir;
        }

        if (!is_dir($cacheDir) && !@mkdir($cacheDir, 0o755, true) && !is_dir($cacheDir)) {
            throw new RuntimeException(sprintf(
                'Could not create stub-extraction directory at "%s"',
                $cacheDir,
            ));
        }

        self::copyDirRecursive($sourceDir, $cacheDir);
        file_put_contents($sentinel, (string) time());

        return $cacheDir;
    }

    /**
     * Recursive copy.  Source MAY be a `phar://` URI -- PHP's stream
     * wrapper handles iteration and read transparently.
     */
    private static function copyDirRecursive(string $source, string $dest): void
    {
        if (!is_dir($source)) {
            return;
        }
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($source, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST,
        );
        $sourceLen = strlen($source);
        foreach ($iterator as $item) {
            /** @var SplFileInfo $item */
            $relative = substr($item->getPathname(), $sourceLen);
            $target = $dest . $relative;
            if ($item->isDir()) {
                if (!is_dir($target) && !@mkdir($target, 0o755, true) && !is_dir($target)) {
                    throw new RuntimeException("Could not create directory: $target");
                }
                continue;
            }
            if (@copy($item->getPathname(), $target) === false) {
                throw new RuntimeException(sprintf(
                    'Could not copy "%s" -> "%s"',
                    $item->getPathname(),
                    $target,
                ));
            }
        }
    }

    /**
     * Default stub-map cache dir: a stable per-user durable directory.
     * The map file inside is keyed by md5 of the stubs path, so multiple
     * LSP versions / installs coexist cleanly.
     */
    public static function defaultCacheDir(): string
    {
        return self::cacheRoot() . '/stub-cache';
    }

    /**
     * Durable per-user cache root for everything this LSP writes
     * (extracted phpstorm-stubs, worse-reflection's stub map, future
     * indices).  Resolution order, picking the first that yields a
     * non-empty string:
     *
     *   1. `XPHP_LSP_CACHE_DIR` -- explicit override the PhpStorm
     *      plugin can wire to its per-user data directory if it
     *      prefers to manage the lifecycle (so plugin uninstall can
     *      also clear caches).
     *   2. `XDG_CACHE_HOME/xphp-lsp` -- XDG basedir spec; honoured by
     *      most Linux DEs and by users who set it manually.
     *   3. `$HOME/.cache/xphp-lsp` (Linux) or
     *      `$HOME/Library/Caches/xphp-lsp` (macOS) -- the platform
     *      defaults when `XDG_CACHE_HOME` isn't set.
     *   4. `%LOCALAPPDATA%/xphp-lsp` -- Windows per-user app data.
     *   5. `<sys_temp>/xphp-lsp` -- last-ditch fallback; volatile but
     *      always writable.  Mirrors the pre-Cycle-D behaviour so
     *      installs without a home dir (e.g. minimal CI images) keep
     *      working.
     *
     * Pre-Cycle-D installs that already extracted stubs into
     * `/tmp/xphp-lsp-extracted-stubs/` will re-extract once into the
     * new durable location; the old `/tmp` copies get reaped by the
     * OS naturally.
     */
    public static function cacheRoot(): string
    {
        $override = getenv('XPHP_LSP_CACHE_DIR');
        if (is_string($override) && $override !== '') {
            return rtrim($override, "/\\");
        }
        $xdg = getenv('XDG_CACHE_HOME');
        if (is_string($xdg) && $xdg !== '') {
            return rtrim($xdg, "/\\") . '/xphp-lsp';
        }
        $home = getenv('HOME');
        if (is_string($home) && $home !== '') {
            $sub = PHP_OS_FAMILY === 'Darwin' ? '/Library/Caches/xphp-lsp' : '/.cache/xphp-lsp';
            return rtrim($home, "/\\") . $sub;
        }
        $localAppData = getenv('LOCALAPPDATA');
        if (is_string($localAppData) && $localAppData !== '') {
            return rtrim($localAppData, "/\\") . '/xphp-lsp';
        }
        return sys_get_temp_dir() . '/xphp-lsp';
    }
}
