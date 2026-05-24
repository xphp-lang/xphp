<?php

declare(strict_types=1);

namespace XPHP\Lsp\Reflection;

use Phpactor\LanguageServer\Core\Workspace\Workspace as PhpactorWorkspace;
use Phpactor\WorseReflection\Reflector;
use Phpactor\WorseReflection\ReflectorBuilder;
use Phpactor\WorseReflection\Core\SourceCodeLocator\StubSourceLocator;
use RuntimeException;
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
    ) {
    }

    public function build(): Reflector
    {
        $workspaceLocator = new WorkspaceSourceLocator($this->workspace, $this->cache, $this->parser);
        $filesystemLocator = new FilesystemSourceLocator($this->rootPath, $this->parser);

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
     */
    public static function defaultStubPath(): string
    {
        $candidate = __DIR__ . '/../../vendor/jetbrains/phpstorm-stubs';
        $real = realpath($candidate);
        return $real !== false ? $real : $candidate;
    }

    /**
     * Default stub-map cache dir: a stable per-user temp directory.
     * The map file inside is keyed by md5 of the stubs path, so multiple
     * LSP versions / installs coexist cleanly.
     */
    public static function defaultCacheDir(): string
    {
        return sys_get_temp_dir() . '/xphp-lsp-stub-cache';
    }
}
